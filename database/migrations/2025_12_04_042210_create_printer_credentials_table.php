<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('printer_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('printer_id')->constrained()->onDelete('cascade');
            $table->string('username');
            $table->text('password');
            $table->string('domain')->nullable();
            $table->timestamps();

            $table->unique('printer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('printer_credentials');
    }
};
