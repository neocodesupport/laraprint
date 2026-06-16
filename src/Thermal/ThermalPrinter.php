<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Thermal;

use Mike42\Escpos\PrintConnectors\CupsPrintConnector;
use Mike42\Escpos\PrintConnectors\FilePrintConnector;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;
use Mike42\Escpos\Printer as EscposPrinter;
use Neocode\Laraprint\Connector\ConnectorFactory;
use Neocode\Laraprint\Events\PrintJobCompleted;
use Neocode\Laraprint\Events\PrintJobFailed;
use Neocode\Laraprint\Events\PrintJobStarted;
use Neocode\Laraprint\Support\ReceiptConfig;
use Neocode\Laraprint\Support\Telemetry;

/**
 * Impression thermique ESC/POS à partir d'une config et de données ticket (sans modèles métier).
 */
class ThermalPrinter
{
    private EscposPrinter $printer;

    /** @var array<string, mixed> Config de connexion conservée pour la télémétrie. */
    private array $connectionConfig;

    /**
     * @param  array<string, mixed>  $connectionConfig
     */
    public function __construct(
        NetworkPrintConnector|WindowsPrintConnector|CupsPrintConnector|FilePrintConnector $connector,
        private readonly ReceiptConfig $receiptConfig,
        array $connectionConfig = [],
    ) {
        $this->printer = new EscposPrinter($connector);
        $this->connectionConfig = $connectionConfig;
    }

    /**
     * Crée une instance à partir d'une config de connexion et d'une config ticket.
     */
    public static function fromConnectionConfig(
        array $connectionConfig,
        array|ReceiptConfig $receiptConfig,
    ): self {
        $connector = ConnectorFactory::fromArray($connectionConfig);
        $config = $receiptConfig instanceof ReceiptConfig
            ? $receiptConfig
            : ReceiptConfig::fromArray($receiptConfig);

        return new self($connector, $config, $connectionConfig);
    }

    /**
     * Imprime un ticket à partir des données reçues.
     */
    public function printReceipt(ReceiptData|array $data): bool
    {
        $receipt = $data instanceof ReceiptData ? $data : ReceiptData::fromArray($data);
        $context = ['sale_number' => $receipt->saleNumber, 'items' => count($receipt->items)];

        Telemetry::event(new PrintJobStarted('thermal.receipt', $this->connectionConfig, $context));
        Telemetry::log('info', 'Impression du ticket '.$receipt->saleNumber, $context);

        try {
            $this->printHeader();
            $this->printSaleInfo($receipt);
            $this->printItems($receipt);
            $this->printTotals($receipt);
            $this->printPayments($receipt);
            $this->printFooter($receipt);
            $this->printer->cut();
            $this->printer->close();

            Telemetry::event(new PrintJobCompleted('thermal.receipt', $this->connectionConfig, $context));

            return true;
        } catch (\Throwable $e) {
            if (isset($this->printer)) {
                try {
                    $this->printer->close();
                } catch (\Throwable) {
                }
            }
            Telemetry::event(new PrintJobFailed('thermal.receipt', $e, $this->connectionConfig, $context));
            Telemetry::log('error', 'Échec impression ticket '.$receipt->saleNumber.' : '.$e->getMessage(), $context);
            throw $e;
        }
    }

    /**
     * Imprime un ticket de test.
     */
    public function printTestReceipt(): bool
    {
        Telemetry::event(new PrintJobStarted('thermal.test', $this->connectionConfig));

        try {
            $sep = $this->receiptConfig->getSeparator();
            $this->printer->setJustification(EscposPrinter::JUSTIFY_CENTER);
            $this->printer->setTextSize(
                $this->receiptConfig->getHeaderSize(),
                $this->receiptConfig->getHeaderSize(),
            );
            $this->printer->text($this->receiptConfig->getCompanyName()."\n");
            $this->printer->setTextSize(1, 1);
            $this->printer->text($sep."\n");
            $this->printer->text("TICKET DE TEST\n");
            $this->printer->text('Date: '.(new \DateTimeImmutable)->format('d/m/Y H:i')."\n");
            $this->printer->text($sep."\n");
            $this->printer->text("Ceci est un ticket de test.\n");
            $this->printer->text($this->receiptConfig->getThankYouMessage()."\n");
            $this->printer->text("\n\n\n");
            $this->printer->cut();
            $this->printer->close();

            Telemetry::event(new PrintJobCompleted('thermal.test', $this->connectionConfig));

            return true;
        } catch (\Throwable $e) {
            try {
                $this->printer->close();
            } catch (\Throwable) {
            }
            Telemetry::event(new PrintJobFailed('thermal.test', $e, $this->connectionConfig));
            throw $e;
        }
    }

    /**
     * Teste la connexion (ouvre et ferme sans imprimer).
     */
    public function testConnection(): bool
    {
        try {
            $this->printer->close();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function getEscposPrinter(): EscposPrinter
    {
        return $this->printer;
    }

    private function printHeader(): void
    {
        $cfg = $this->receiptConfig;
        $sep = $cfg->getSeparator();

        $this->printer->setJustification(EscposPrinter::JUSTIFY_CENTER);
        $this->printer->setTextSize($cfg->getHeaderSize(), $cfg->getHeaderSize());
        $this->printer->text($cfg->getCompanyName()."\n");
        if ($cfg->getCompanySubtitle() !== '') {
            $this->printer->text($cfg->getCompanySubtitle()."\n");
        }
        $this->printer->setTextSize(1, 1);
        $this->printer->text($sep."\n");
        $this->printer->text(($cfg->company['address'] ?? '')."\n");
        $this->printer->text('Tel: '.($cfg->company['phone'] ?? '')."\n");
        $this->printer->text('Email: '.($cfg->company['email'] ?? '')."\n");
        $this->printer->text($sep."\n");
        $this->printer->setEmphasis(true);
        $this->printer->text("TICKET DE CAISSE\n");
        $this->printer->setEmphasis(false);
        $this->printer->text($sep."\n");
    }

    private function printSaleInfo(ReceiptData $receipt): void
    {
        $sep = $this->receiptConfig->getSeparator();
        $this->printer->setJustification(EscposPrinter::JUSTIFY_LEFT);

        $this->printer->text('Vente N°: '.$receipt->saleNumber."\n");
        $this->printer->text('Date: '.($receipt->soldAt?->format('d/m/Y H:i:s') ?? '')."\n");
        if ($receipt->cashierName !== null) {
            $this->printer->text('Caissier: '.$receipt->cashierName."\n");
        }
        if ($receipt->cashRegisterName !== null) {
            $this->printer->text('Caisse: '.$receipt->cashRegisterName."\n");
        }
        if ($receipt->patientName !== null) {
            $this->printer->text('Patient: '.$receipt->patientName."\n");
            if ($receipt->patientPhone !== null) {
                $this->printer->text('Tel: '.$receipt->patientPhone."\n");
            }
        }
        $this->printer->text($sep."\n");
    }

    private function printItems(ReceiptData $receipt): void
    {
        $cfg = $this->receiptConfig;
        $sep = $cfg->getSeparator();
        $itemSize = $cfg->getItemNameSize();

        if (count($receipt->items) > 0) {
            $this->printer->setJustification(EscposPrinter::JUSTIFY_LEFT);
            $this->printer->text($sep."\n");
            $this->printer->setEmphasis(true);
            $this->printer->text("ARTICLES VENDUS\n");
            $this->printer->setEmphasis(false);
            $this->printer->text($sep."\n");
        }

        foreach ($receipt->items as $item) {
            $this->printer->setTextSize($itemSize, 1);
            $this->printer->text(($item['item_name'] ?? '')."\n");
            $this->printer->setTextSize(1, 1);
            if (! empty($item['item_code'])) {
                $this->printer->text('Code: '.$item['item_code']."\n");
            }
            if (! empty($item['item_description'])) {
                $this->printer->text($item['item_description']."\n");
            }
            $qty = $item['quantity'] ?? 0;
            $unit = $item['unit_price'] ?? 0;
            $this->printer->text(sprintf(
                "%s x %s = %s\n",
                (string) $qty,
                $cfg->formatCurrency((float) $unit),
                $cfg->formatCurrency((float) $unit * (float) $qty),
            ));
            if (($item['discount_amount'] ?? 0) > 0) {
                $this->printer->text('Remise: -'.$cfg->formatCurrency((float) $item['discount_amount'])."\n");
            }
            if (($item['tax_amount'] ?? 0) > 0) {
                $this->printer->text(sprintf(
                    "TVA: +%s\n",
                    $cfg->formatCurrency((float) $item['tax_amount']),
                ));
            }
            $this->printer->setEmphasis(true);
            $this->printer->text('Total: '.$cfg->formatCurrency((float) ($item['total_amount'] ?? 0))."\n");
            $this->printer->setEmphasis(false);
            $this->printer->text($sep."\n");
        }
    }

    private function printTotals(ReceiptData $receipt): void
    {
        $cfg = $this->receiptConfig;
        $sep = $cfg->getSeparator();

        $this->printer->setJustification(EscposPrinter::JUSTIFY_RIGHT);
        $this->printer->text($sep."\n");
        $this->printer->text('Sous-total: '.$cfg->formatCurrency($receipt->subtotal)."\n");
        if ($receipt->discountAmount > 0) {
            $this->printer->text('Remise: -'.$cfg->formatCurrency($receipt->discountAmount)."\n");
        }
        if ($receipt->taxAmount > 0) {
            if (count($receipt->taxesGrouped) > 0) {
                $this->printer->setJustification(EscposPrinter::JUSTIFY_LEFT);
                $this->printer->setEmphasis(true);
                $this->printer->text("TAXES\n");
                $this->printer->setEmphasis(false);
                $this->printer->setJustification(EscposPrinter::JUSTIFY_RIGHT);
                foreach ($receipt->taxesGrouped as $tax) {
                    $label = $tax['name'] ?? 'TVA';
                    $rate = $tax['rate'] ?? 0;
                    if ($rate > 0) {
                        $label .= ' ('.number_format((float) $rate, 0).'%)';
                    }
                    $this->printer->text($label.': +'.$cfg->formatCurrency((float) ($tax['amount'] ?? 0))."\n");
                }
            } else {
                $this->printer->text('TVA: +'.$cfg->formatCurrency($receipt->taxAmount)."\n");
            }
        }
        $this->printer->setEmphasis(true);
        $this->printer->setTextSize($cfg->getTotalSize(), 1);
        $this->printer->text('TOTAL: '.$cfg->formatCurrency($receipt->totalAmount)."\n");
        $this->printer->setTextSize(1, 1);
        $this->printer->setEmphasis(false);
        $this->printer->text($sep."\n");
    }

    private function printPayments(ReceiptData $receipt): void
    {
        $cfg = $this->receiptConfig;
        $sep = $cfg->getSeparator();

        if (count($receipt->payments) === 0) {
            return;
        }

        $this->printer->setJustification(EscposPrinter::JUSTIFY_LEFT);
        $this->printer->setEmphasis(true);
        $this->printer->text("PAIEMENTS\n");
        $this->printer->setEmphasis(false);
        $this->printer->text($sep."\n");

        foreach ($receipt->payments as $payment) {
            $label = $payment['type_label'] ?? $this->getPaymentTypeLabel($payment['type'] ?? '');
            $this->printer->text($label.': '.$cfg->formatCurrency((float) ($payment['amount'] ?? 0))."\n");
            if (! empty($payment['reference'])) {
                $this->printer->text('Ref: '.$payment['reference']."\n");
            }
            if (isset($payment['cash_received']) && $payment['cash_received'] > 0) {
                $this->printer->text('Montant reçu: '.$cfg->formatCurrency((float) $payment['cash_received'])."\n");
                if (isset($payment['change_amount']) && $payment['change_amount'] > 0) {
                    $this->printer->text('Monnaie rendue: '.$cfg->formatCurrency((float) $payment['change_amount'])."\n");
                }
            }
        }
        $this->printer->text($sep."\n");
    }

    private function printFooter(ReceiptData $receipt): void
    {
        $cfg = $this->receiptConfig;
        $sep = $cfg->getSeparator();

        $this->printer->setJustification(EscposPrinter::JUSTIFY_CENTER);
        $this->printer->text($sep."\n");
        $this->printer->text($cfg->getThankYouMessage()."\n");
        $this->printer->text($cfg->getKeepReceiptMessage()."\n");
        $this->printer->text($sep."\n");

        if ($cfg->isQrCodeEnabled()) {
            try {
                $qrData = [
                    'sale_number' => $receipt->saleNumber,
                    'date' => $receipt->soldAt?->format('Y-m-d H:i:s'),
                    'total' => $receipt->totalAmount,
                    'items_count' => count($receipt->items),
                ];
                $this->printer->qrCode(
                    (string) json_encode($qrData),
                    EscposPrinter::QR_ECLEVEL_L,
                    $cfg->getQrCodeSize(),
                );
            } catch (\Throwable) {
                // Ignore QR errors
            }
        }

        $this->printer->text("\n");
        $this->printer->text(($cfg->company['website'] ?? 'www.example.com')."\n");
        $this->printer->text($sep."\n");
    }

    private function getPaymentTypeLabel(string $type): string
    {
        return match ($type) {
            'cash' => 'Espèces',
            'card' => 'Carte bancaire',
            'insurance' => 'Assurance',
            'mobile_money' => 'Mobile Money',
            'bank_transfer' => 'Virement bancaire',
            'tpe' => 'TPE',
            'orange_money' => 'Orange Money',
            'wave' => 'Wave',
            'moov_money' => 'Moov Money',
            'mtn_money' => 'MTN Money',
            'check' => 'Chèque',
            'mixed' => 'Paiement mixte',
            default => ucfirst($type),
        };
    }
}
