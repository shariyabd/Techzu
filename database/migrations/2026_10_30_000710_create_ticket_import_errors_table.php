<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_import_errors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_import_id')->constrained('ticket_imports')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('errors');
            $table->json('raw');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['ticket_import_id', 'row_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_import_errors');
    }
};
