<?php

namespace App\Enums;

enum DepositStatus: string
{
    case Detected = 'detected';       // seen in mempool / unconfirmed
    case Confirming = 'confirming';   // has some confirmations
    case Credited = 'credited';       // balance credited, final
    case Orphaned = 'orphaned';       // reorged out before credit
    case Failed = 'failed';           // manual review required

    public function isFinal(): bool
    {
        return in_array($this, [self::Credited, self::Orphaned, self::Failed], true);
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
