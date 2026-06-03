<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'invoice_id', 'order_id', 'signed_token', 'amount', 'currency',
        'payment_method_id', 'method_slug', 'method_option', 'customer_number',
        'normalized_customer_number', 'trx_id', 'status', 'sms_device_id',
        'sms_log_id', 'created_ip', 'verified_at', 'expires_at', 'payment_proof_path',
        'success_url', 'failed_url', 'metadata', 'manual_note',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'verified_at' => 'datetime',
        'expires_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function smsDevice()
    {
        return $this->belongsTo(SmsDevice::class);
    }

    public function smsLog()
    {
        return $this->belongsTo(SmsLog::class);
    }

    public function manualVerifications()
    {
        return $this->hasMany(ManualVerification::class);
    }

    public function latestManualVerification()
    {
        return $this->hasOne(ManualVerification::class)->latestOfMany();
    }

    public function paidAmount(): float
    {
        if ($this->status === self::STATUS_SUCCESS && (float) ($this->metadata['paid_amount'] ?? 0) <= 0) {
            return (float) $this->amount;
        }

        return round((float) ($this->metadata['paid_amount'] ?? 0), 2);
    }

    public function discountAmount(): float
    {
        return round((float) ($this->metadata['discount_amount'] ?? 0), 2);
    }

    public function dueAmount(): float
    {
        return max(round((float) $this->amount - $this->paidAmount() - $this->discountAmount(), 2), 0);
    }

    public function isInvoiceSettled(): bool
    {
        return $this->status === self::STATUS_SUCCESS || $this->dueAmount() <= 0;
    }

    public function officialSenderNumber(): ?string
    {
        if (filled($this->smsLog?->parsed_customer_number)) {
            return $this->smsLog->parsed_customer_number;
        }

        $receipt = collect($this->metadata['payment_receipts'] ?? [])
            ->reverse()
            ->first(fn ($receipt) => filled($receipt['customer_number'] ?? null));

        return $receipt['customer_number'] ?? null;
    }

    public function hasMismatchedOfficialSenderNumber(): bool
    {
        $official = $this->officialSenderNumber();

        return filled($official)
            && filled($this->customer_number)
            && $official !== $this->customer_number;
    }
}
