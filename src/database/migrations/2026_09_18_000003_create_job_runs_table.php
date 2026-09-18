<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('job_id');
            $table->string('reserved_by')->nullable();
            $table->timestamp('ran_at');

            $table->index('job_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_runs');
    }
};
