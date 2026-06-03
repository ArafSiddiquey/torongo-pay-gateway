<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GatewaySetting extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value'];
}
