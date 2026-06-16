<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workstations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('hostname')->nullable()->unique();
            $table->string('ip_address')->unique();
            $table->string('location')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('ip_address');
            $table->index('hostname');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workstations');
    }
};
