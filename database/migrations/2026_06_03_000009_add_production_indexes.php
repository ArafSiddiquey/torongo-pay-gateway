<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sms_devices', function (Blueprint $table) {
            $table->index(['is_active', 'api_key_hash'], 'sms_devices_active_key_hash_idx');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->index(['status', 'method_option', 'expires_at'], 'transactions_status_option_expires_idx');
            $table->index(['status', 'method_slug', 'method_option', 'created_at'], 'transactions_status_method_option_created_idx');
        });

        Schema::table('sms_logs', function (Blueprint $table) {
            $table->index(['method_slug', 'method_option', 'sms_type', 'is_fraud', 'is_duplicate', 'received_at'], 'sms_logs_match_window_idx');
            $table->index(['matched_transaction_id', 'sms_type', 'is_fraud'], 'sms_logs_unmatched_type_fraud_idx');
        });

        Schema::table('manual_verifications', function (Blueprint $table) {
            $table->index(['status', 'trx_id', 'created_at'], 'manual_verifications_status_trx_created_idx');
        });

        Schema::table('outgoing_sms', function (Blueprint $table) {
            $table->index(['status', 'attempts', 'created_at'], 'outgoing_sms_status_attempts_created_idx');
            $table->index(['status', 'sms_device_id'], 'outgoing_sms_status_device_idx');
        });
    }

    public function down(): void
    {
        Schema::table('outgoing_sms', function (Blueprint $table) {
            $table->dropIndex('outgoing_sms_status_device_idx');
            $table->dropIndex('outgoing_sms_status_attempts_created_idx');
        });

        Schema::table('manual_verifications', function (Blueprint $table) {
            $table->dropIndex('manual_verifications_status_trx_created_idx');
        });

        Schema::table('sms_logs', function (Blueprint $table) {
            $table->dropIndex('sms_logs_unmatched_type_fraud_idx');
            $table->dropIndex('sms_logs_match_window_idx');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('transactions_status_method_option_created_idx');
            $table->dropIndex('transactions_status_option_expires_idx');
        });

        Schema::table('sms_devices', function (Blueprint $table) {
            $table->dropIndex('sms_devices_active_key_hash_idx');
        });
    }
};
