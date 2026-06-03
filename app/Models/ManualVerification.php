<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManualVerification extends Model
{
    protected $fillable = ['transaction_id', 'trx_id', 'customer_number', 'note', 'ip', 'status'];

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }
}
