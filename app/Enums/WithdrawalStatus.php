<?php

namespace App\Enums;

enum WithdrawalStatus: string
{
    case PendingAuto = 'pending_auto';         // queued for automatic processing
    case PendingManual = 'pending_manual';     // waiting for admin approval
    case Approved = 'approved';                // admin approved, queued for sending
    case Processing = 'processing';            // sent to rail, waiting for confirmation
    case Completed = 'completed';              // final: funds delivered
    case Rejected = 'rejected';                // final: admin rejected, unlocked
    case Failed = 'failed';                    // final: rail failed, unlocked
    case Cancelled = 'cancelled';              // final: cancelled by user before approval

    public function isFinal(): bool
    {
        return in_array($this, [self::Completed, self::Rejected, self::Failed, self::Cancelled], true);
    }

    /** Waiting for a decision or being sent — funds are locked. */
    public function isOpen(): bool
    {
        return in_array($this, [self::PendingAuto, self::PendingManual, self::Approved, self::Processing], true);
    }

    public function needsAdminAction(): bool
    {
        return $this === self::PendingManual;
    }

    public function label(): string
    {
        return match ($this) {
            self::PendingAuto => 'Auto queue',
            self::PendingManual => 'Pending approval',
            self::Approved => 'Approved',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::Rejected => 'Rejected',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::PendingManual => 'bg-amber-100 text-amber-800',
            self::PendingAuto => 'bg-sky-100 text-sky-800',
            self::Approved => 'bg-indigo-100 text-indigo-800',
            self::Processing => 'bg-blue-100 text-blue-800',
            self::Completed => 'bg-emerald-100 text-emerald-800',
            self::Rejected, self::Failed => 'bg-rose-100 text-rose-800',
            self::Cancelled => 'bg-slate-100 text-slate-700',
        };
    }
}
