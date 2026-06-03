<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sms_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('sms_logs', 'method_option')) {
                $table->string('method_option')->nullable()->after('method_slug')->index();
            }
            if (! Schema::hasColumn('sms_logs', 'android_subscription_id')) {
                $table->integer('android_subscription_id')->nullable()->after('sms_device_id');
            }
            if (! Schema::hasColumn('sms_logs', 'android_sim_slot')) {
                $table->integer('android_sim_slot')->nullable()->after('android_subscription_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sms_logs', function (Blueprint $table) {
            foreach (['method_option', 'android_subscription_id', 'android_sim_slot'] as $column) {
                if (Schema::hasColumn('sms_logs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
