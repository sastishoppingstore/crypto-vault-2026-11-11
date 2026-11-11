<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WithdrawalRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'asset' => strtoupper(trim((string) $this->input('asset'))),
            'idempotency_key' => $this->header('Idempotency-Key') ?: $this->input('idempotency_key'),
        ]);
    }

    public function rules(): array
    {
        return [
            'asset' => ['required', 'string', 'max:20'],
            // Exact decimal string — never a float.
            'amount' => ['required', 'string', 'regex:/^\d+(\.\d{1,18})?$/'],
            'to_address' => ['required', 'string', 'max:128'],
            'memo' => ['nullable', 'string', 'max:128'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ];
    }
}
