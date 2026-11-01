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
            $table->unsignedBigInteger('ticket_import_id')->nullable()->index();
            $table->char('import_fingerprint', 64)->nullable()->unique();
            $table->timestamp('sla_started_at');
            $table->timestamp('sla_due_at');
            $table->timestamp('sla_warning_at');
            $table->timestamp('sla_paused_at')->nullable();
            $table->unsignedInteger('sla_paused_seconds')->default(0);
            $table->unsignedTinyInteger('escalation_level')->default(0);
            $table->unsignedTinyInteger('escalation_notified_level')->default(0);
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'sla_due_at']);
            $table->index(['status', 'sla_warning_at']);
            $table->index(['assigned_agent_id', 'status']);
            $table->index(['customer_id', 'created_at']);
            $table->index(['customer_id', 'subject']);
            $table->index(['category_id', 'status']);
            $table->index(['status', 'category_id', 'assigned_agent_id']);
            $table->index(['priority', 'status']);
            $table->index('created_at');
            $table->index(['status', 'resolved_at']);
            $table->index('updated_at');
            $table->index(['status', 'escalated_at', 'escalation_notified_level', 'escalation_level'], 'tickets_escalation_redelivery_index');
            $table->fullText(['subject', 'description']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
