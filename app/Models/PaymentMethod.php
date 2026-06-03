<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    protected $fillable = [
        'slug', 'name', 'name_bn', 'logo_path', 'payment_number', 'remittance_number', 'qr_path',
        'qr_enabled', 'is_active', 'sort_order', 'send_money_enabled',
        'payment_enabled', 'remittance_enabled', 'cash_out_enabled', 'instructions_en', 'instructions_bn', 'config',
    ];

    protected $casts = [
        'qr_enabled' => 'bool',
        'is_active' => 'bool',
        'send_money_enabled' => 'bool',
        'payment_enabled' => 'bool',
        'remittance_enabled' => 'bool',
        'cash_out_enabled' => 'bool',
        'config' => 'array',
    ];

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }
}
