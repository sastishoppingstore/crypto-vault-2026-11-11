<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Runtime setting. Values are JSON so ints/bools/arrays keep their types.
 * Always read through SettingsService (Redis cached).
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value', 'group', 'type', 'description'];

    protected $casts = [
        'value' => 'json',
    ];
}
