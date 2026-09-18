<?php

namespace App\Http\Requests;

use App\Enums\JobPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreJobRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'max:255'],
            'payload' => ['required', 'array'],
            'idempotency_key' => ['required', 'string', 'max:255'],
            'priority' => ['sometimes', Rule::enum(JobPriority::class)],
            'execute_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
