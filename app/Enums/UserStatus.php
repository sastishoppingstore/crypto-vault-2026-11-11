<?php

namespace App\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Frozen = 'frozen';       // withdrawals disabled, deposits still allowed
    case Suspended = 'suspended'; // login disabled

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
