<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 32)->unique();
            $table->string('subject');
            $table->text('description');
            $table->string('priority', 16);
            $table->string('status', 32);
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->string('external_reference', 64)->nullable()->unique();
            $table->timestamp('sla_started_at');
            $table->timestamp('sla_due_at');
            $table->timestamp('sla_warning_at');
            $table->timestamp('sla_paused_at')->nullable();
            $table->unsignedTinyInteger('escalation_level')->default(0);
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'sla_due_at']);
            $table->index(['status', 'sla_warning_at']);
            $table->index(['assigned_agent_id', 'status']);
            $table->index(['customer_id', 'created_at']);
            $table->index(['category_id', 'status']);
            $table->index(['priority', 'status']);
            $table->index('created_at');
            $table->index(['status', 'resolved_at']);
            $table->index('updated_at');
            $table->fullText(['subject', 'description']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
