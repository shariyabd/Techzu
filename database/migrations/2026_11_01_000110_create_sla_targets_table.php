<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sla_targets', function (Blueprint $table): void {
            $table->id();
            $table->string('priority', 16)->unique();
            $table->unsignedInteger('resolution_minutes');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_targets');
    }
};
