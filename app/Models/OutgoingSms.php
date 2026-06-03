<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OutgoingSms extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    protected $table = 'outgoing_sms';

    protected $fillable = [
        'transaction_id',
        'sms_device_id',
        'recipient',
        'message',
        'purpose',
        'status',
        'attempts',
        'last_attempted_at',
        'sent_at',
        'last_error',
    ];

    protected $casts = [
        'attempts' => 'int',
        'last_attempted_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function smsDevice()
    {
        return $this->belongsTo(SmsDevice::class);
    }
}
