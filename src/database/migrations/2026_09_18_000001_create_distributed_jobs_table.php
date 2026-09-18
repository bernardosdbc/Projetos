<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->json('payload');
            $table->string('idempotency_key')->unique();
            $table->string('status')->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->timestamp('available_at');
            $table->timestamp('reserved_at')->nullable();
            $table->string('reserved_by')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at'], 'idx_status_available_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jobs');
    }
};