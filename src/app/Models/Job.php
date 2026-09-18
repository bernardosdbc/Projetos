<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Job extends Model
{
    protected $fillable = ['type', 'payload', 'idempotency_key', 'status', 'attempts', 'max_attempts', 'available_at', 'reserved_at', 'reserved_by', 'last_error', 'completed_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'available_at' => 'datetime', 'reserved_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}