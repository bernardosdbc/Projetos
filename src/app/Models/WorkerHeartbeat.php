<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkerHeartbeat extends Model
{
    protected $primaryKey = 'worker_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['worker_id', 'status', 'current_job_id', 'last_seen_at'];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    public static function touch(string $workerId, string $status, ?int $currentJobId): void
    {
        static::updateOrCreate(
            ['worker_id' => $workerId],
            ['status' => $status, 'current_job_id' => $currentJobId, 'last_seen_at' => now()],
        );
    }
}
