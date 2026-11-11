<?php

namespace App\Http\Requests;

use App\Enums\Chain;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DepositAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'chain' => ['required', 'string', Rule::in(array_map(
                fn (Chain $c) => $c->value,
                Chain::enabledChains(),
            ))],
        ];
    }
}
