<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            'status' => $this->status->value,
            'email_verified_at' => optional($this->email_verified_at)->toIso8601String(),
            'two_factor_enabled' => $this->hasTwoFactorEnabled(),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
