<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SmsDevice extends Model
{
    protected $fillable = ['name', 'api_key_hash', 'allowed_methods', 'last_sync_at', 'last_sms_received_at', 'is_active'];

    protected $casts = [
        'allowed_methods' => 'array',
        'last_sync_at' => 'datetime',
        'last_sms_received_at' => 'datetime',
        'is_active' => 'bool',
    ];

    public static function generatePlainKey(): string
    {
        return 'smsr_' . Str::random(48);
    }
}
