<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreJobRequest extends FormRequest
{
    public function rules(): array
    {
        return ['type' => ['required', 'string', 'max:255'], 'payload' => ['required', 'array'], 'idempotency_key' => ['required', 'string', 'max:255']];
    }
}