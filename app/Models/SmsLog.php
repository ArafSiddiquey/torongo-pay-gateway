<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsLog extends Model
{
    protected $fillable = [
        'sms_device_id', 'android_subscription_id', 'android_sim_slot', 'method_slug', 'method_option', 'official_sender', 'raw_sender', 'raw_body',
        'sms_hash', 'parsed_amount', 'parsed_customer_number', 'normalized_customer_number',
        'parsed_trx_id', 'received_at', 'sms_type', 'is_duplicate', 'is_fraud', 'matched_transaction_id',
    ];

    protected $casts = [
        'parsed_amount' => 'decimal:2',
        'received_at' => 'datetime',
        'is_duplicate' => 'bool',
        'is_fraud' => 'bool',
    ];

    public function smsDevice()
    {
        return $this->belongsTo(SmsDevice::class);
    }
}
