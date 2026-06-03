<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('gateway_settings')
            ->whereIn('key', [
                'textbee_enabled',
                'textbee_device_id',
                'textbee_api_key',
                'textbee_sim_subscription_id',
            ])
            ->delete();
    }

    public function down(): void
    {
        DB::table('gateway_settings')->upsert([
            ['key' => 'textbee_enabled', 'value' => '0', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'textbee_device_id', 'value' => '', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'textbee_api_key', 'value' => '', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'textbee_sim_subscription_id', 'value' => '', 'created_at' => now(), 'updated_at' => now()],
        ], ['key'], ['value', 'updated_at']);
    }
};
