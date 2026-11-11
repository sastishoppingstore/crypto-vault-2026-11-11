<?php

namespace App\Http\Resources;

use App\Support\Decimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Withdrawal */
class WithdrawalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset' => $this->asset,
            'chain' => $this->chain?->value,
            'rail' => $this->rail,
            'amount' => Decimal::prettify($this->amount),
            'fee' => Decimal::prettify($this->fee),
            'total' => Decimal::prettify($this->total),
            'to_address' => $this->to_address,
            'memo' => $this->memo,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'approval_type' => $this->approval_type,
            'tx_hash' => $this->tx_hash,
            'explorer_url' => $this->explorerUrl(),
            'failure_reason' => $this->failure_reason,
            'reject_reason' => $this->reject_reason,
            'approved_at' => optional($this->approved_at)->toIso8601String(),
            'completed_at' => optional($this->completed_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
