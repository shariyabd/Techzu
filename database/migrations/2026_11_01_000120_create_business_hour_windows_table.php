<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_hour_windows', function (Blueprint $table): void {
            $table->id();
            $table->string('weekday', 9);
            $table->char('starts_at', 5);
            $table->char('ends_at', 5);
            $table->timestamps();

            $table->index(['weekday', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_hour_windows');
    }
};
