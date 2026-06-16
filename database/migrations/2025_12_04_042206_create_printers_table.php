<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('printers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workstation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->enum('connection_type', ['network', 'smb', 'windows', 'usb', 'cups', 'file'])->default('network');
            $table->string('printer_type')->nullable();
            $table->string('model')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->index(['workstation_id', 'is_default']);
            $table->index(['workstation_id', 'is_active']);
            $table->index('is_default');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('printers');
    }
};
