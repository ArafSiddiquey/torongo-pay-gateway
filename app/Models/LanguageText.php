<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LanguageText extends Model
{
    public $timestamps = false;

    protected $fillable = ['key', 'lang', 'value'];
}
