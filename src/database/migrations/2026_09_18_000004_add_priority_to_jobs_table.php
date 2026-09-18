<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table): void {
            // See App\Enums\JobPriority: MySQL ENUM sorts by declaration position,
            // so this list's order IS the claim order (critical first).
            $table->enum('priority', ['critical', 'high', 'normal', 'low'])
                ->default('normal')
                ->after('type');
        });

        Schema::table('jobs', function (Blueprint $table): void {
            // Replaces idx_status_available_at: the claim query now filters by
            // status and orders by (priority, available_at), so priority needs
            // to sit between them in the composite index to serve both.
            $table->dropIndex('idx_status_available_at');
            $table->index(['status', 'priority', 'available_at'], 'idx_status_priority_available_at');
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table): void {
            $table->dropIndex('idx_status_priority_available_at');
            $table->index(['status', 'available_at'], 'idx_status_available_at');
        });

        Schema::table('jobs', function (Blueprint $table): void {
            $table->dropColumn('priority');
        });
    }
};
