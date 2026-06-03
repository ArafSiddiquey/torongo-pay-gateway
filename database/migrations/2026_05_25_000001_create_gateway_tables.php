<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('name_bn')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('payment_number')->nullable();
            $table->string('qr_path')->nullable();
            $table->boolean('qr_enabled')->default(true);
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->boolean('send_money_enabled')->default(false);
            $table->boolean('payment_enabled')->default(true);
            $table->boolean('remittance_enabled')->default(false);
            $table->boolean('cash_out_enabled')->default(false);
            $table->text('instructions_en')->nullable();
            $table->text('instructions_bn')->nullable();
            $table->timestamps();
        });

        Schema::create('sms_devices', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('api_key_hash', 128);
            $table->json('allowed_methods')->nullable();
            $table->timestamp('last_sync_at')->nullable()->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_id')->unique();
            $table->string('order_id')->nullable()->index();
            $table->string('signed_token', 128)->index();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 8)->default('BDT');
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method_slug')->nullable()->index();
            $table->string('method_option')->nullable();
            $table->string('customer_number')->nullable();
            $table->string('normalized_customer_number')->nullable()->index();
            $table->string('trx_id')->nullable()->unique();
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('sms_device_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('sms_log_id')->nullable()->index();
            $table->string('created_ip')->nullable();
            $table->timestamp('verified_at')->nullable()->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->string('payment_proof_path')->nullable();
            $table->string('success_url')->nullable();
            $table->string('failed_url')->nullable();
            $table->json('metadata')->nullable();
            $table->text('manual_note')->nullable();
            $table->timestamps();
            $table->index(['status', 'method_slug', 'amount']);
        });

        Schema::create('sms_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sms_device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method_slug')->nullable()->index();
            $table->string('official_sender')->nullable();
            $table->string('raw_sender')->nullable();
            $table->text('raw_body');
            $table->string('sms_hash', 64)->unique();
            $table->decimal('parsed_amount', 12, 2)->nullable();
            $table->string('parsed_customer_number')->nullable();
            $table->string('normalized_customer_number')->nullable()->index();
            $table->string('parsed_trx_id')->nullable()->index();
            $table->timestamp('received_at')->nullable()->index();
            $table->boolean('is_duplicate')->default(false)->index();
            $table->boolean('is_fraud')->default(false)->index();
            $table->foreignId('matched_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->timestamps();
            $table->index(['parsed_trx_id', 'method_slug']);
        });

        Schema::create('manual_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->string('trx_id')->nullable()->index();
            $table->string('customer_number')->nullable();
            $table->text('note')->nullable();
            $table->string('ip')->nullable();
            $table->string('status', 20)->default('submitted')->index();
            $table->timestamps();
        });

        Schema::create('gateway_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
        });

        Schema::create('language_texts', function (Blueprint $table) {
            $table->id();
            $table->string('key');
            $table->string('lang', 8)->default('en');
            $table->text('value')->nullable();
            $table->unique(['key', 'lang']);
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action')->index();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('ip')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('language_texts');
        Schema::dropIfExists('gateway_settings');
        Schema::dropIfExists('manual_verifications');
        Schema::dropIfExists('sms_logs');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('sms_devices');
        Schema::dropIfExists('payment_methods');
    }
};
