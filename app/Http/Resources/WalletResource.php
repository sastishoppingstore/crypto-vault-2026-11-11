<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Wallet */
class WalletResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'chain' => $this->chain->value,
            'chain_label' => $this->chain->label(),
            'address' => $this->address,
            'accepted_assets' => $this->acceptedAssets(),
            'is_active' => $this->is_active,
        ];
    }
}
