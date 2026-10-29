<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_available')->default(true)->after('password');
            $table->timestamp('last_assigned_at')->nullable()->after('is_available');

            $table->index('is_available');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['is_available']);
            $table->dropColumn(['is_available', 'last_assigned_at']);
        });
    }
};
