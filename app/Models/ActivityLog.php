<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    protected $fillable = ['actor_type', 'actor_id', 'action', 'subject_type', 'subject_id', 'ip', 'payload'];

    protected $casts = ['payload' => 'array'];
}
