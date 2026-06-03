<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogger
{
    public function log(string $action, $subject = null, array $payload = [], ?Request $request = null): void
    {
        ActivityLog::create([
            'actor_type' => $request?->session()->has('admin_id') ? 'admin' : 'system',
            'actor_id' => $request?->session()->get('admin_id'),
            'action' => $action,
            'subject_type' => $subject ? get_class($subject) : null,
            'subject_id' => $subject?->id,
            'ip' => $request?->ip(),
            'payload' => $payload,
        ]);
    }
}
