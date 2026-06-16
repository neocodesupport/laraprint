<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Printing;

/**
 * Client IPP minimal (Internet Printing Protocol) — opération Print-Job.
 *
 * Permet d'imprimer réellement sur les imprimantes **IPP / AirPrint** (port 631),
 * là où le SDK ne gérait que le flux brut 9100. Encode une requête IPP puis la POST
 * en HTTP. L'encodage de la requête est pur et testable.
 */
final class IppClient
{
    /**
     * Envoie un document à imprimer via IPP Print-Job.
     *
     * @param  string  $uri  URI IPP, ex. `ipp://192.168.1.50:631/ipp/print`.
     */
    public function printJob(
        string $uri,
        string $data,
        string $documentFormat = 'application/octet-stream',
        string $jobName = 'laraprint',
        float $timeout = 10.0,
    ): bool {
        $request = self::buildPrintJobRequest($uri, $jobName, $documentFormat, 1).$data;

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/ipp\r\n",
                'content' => $request,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents(self::toHttpUrl($uri), false, $context);
        if ($response === false || strlen($response) < 4) {
            return false;
        }

        // Octets 2-3 = status-code ; < 0x0100 = famille « successful ».
        $status = (ord($response[2]) << 8) | ord($response[3]);

        return $status < 0x0100;
    }

    /**
     * Construit une requête IPP Print-Job (en-tête + attributs d'opération).
     */
    public static function buildPrintJobRequest(
        string $printerUri,
        string $jobName,
        string $documentFormat,
        int $requestId,
    ): string {
        $out = pack('n', 0x0101);   // version 1.1
        $out .= pack('n', 0x0002);  // operation-id : Print-Job
        $out .= pack('N', $requestId);

        $out .= chr(0x01);          // operation-attributes-tag
        $out .= self::attribute(0x47, 'attributes-charset', 'utf-8');
        $out .= self::attribute(0x48, 'attributes-natural-language', 'en');
        $out .= self::attribute(0x45, 'printer-uri', $printerUri);
        $out .= self::attribute(0x42, 'requesting-user-name', 'laraprint');
        $out .= self::attribute(0x42, 'job-name', $jobName);
        $out .= self::attribute(0x49, 'document-format', $documentFormat);
        $out .= chr(0x03);          // end-of-attributes-tag

        return $out;
    }

    /**
     * Convertit une URI ipp(s):// en URL http(s):// pour le transport.
     */
    public static function toHttpUrl(string $uri): string
    {
        if (str_starts_with($uri, 'ipps://')) {
            return 'https://'.substr($uri, 7);
        }
        if (str_starts_with($uri, 'ipp://')) {
            return 'http://'.substr($uri, 6);
        }

        return $uri;
    }

    private static function attribute(int $valueTag, string $name, string $value): string
    {
        return chr($valueTag)
            .pack('n', strlen($name)).$name
            .pack('n', strlen($value)).$value;
    }
}
