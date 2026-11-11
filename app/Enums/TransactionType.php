<?php

namespace App\Enums;

enum TransactionType: string
{
    case Deposit = 'deposit';                       // on-chain deposit credited
    case WithdrawalLock = 'withdrawal_lock';        // funds frozen for request
    case WithdrawalUnlock = 'withdrawal_unlock';    // request rejected/failed
    case WithdrawalDebit = 'withdrawal_debit';      // locked funds settled (sent)
    case AdminAdjustment = 'admin_adjustment';      // manual correction by admin
    case ExchangeDeposit = 'exchange_deposit';      // treasury: exchange inbound
    case ExchangeWithdrawal = 'exchange_withdrawal';// treasury: exchange outbound

    public function label(): string
    {
        return str($this->value)->replace('_', ' ')->title()->toString();
    }

    /** Treasury rows do not belong to a user balance. */
    public function isTreasury(): bool
    {
        return in_array($this, [self::ExchangeDeposit, self::ExchangeWithdrawal], true);
    }
}
