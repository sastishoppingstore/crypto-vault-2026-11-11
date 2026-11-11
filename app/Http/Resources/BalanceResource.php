<?php

namespace App\Http\Resources;

use App\Support\Decimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Balance */
class BalanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'asset' => $this->asset,
            'available' => Decimal::prettify($this->available),
            'locked' => Decimal::prettify($this->locked),
            'total' => Decimal::prettify(Decimal::add($this->available, $this->locked)),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
