<?php

namespace App\Http\Resources;

use App\Support\Decimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Transaction */
class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'asset' => $this->asset,
            'amount' => Decimal::prettify($this->amount),
            'balance_after' => $this->balance_after !== null ? Decimal::prettify($this->balance_after) : null,
            'description' => $this->description,
            'reference' => $this->when($this->reference_type !== null, [
                'type' => $this->reference_type,
                'id' => $this->reference_id,
            ]),
            'meta' => $this->meta,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
