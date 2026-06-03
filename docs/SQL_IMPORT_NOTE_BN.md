# SQL / Migration Note

এই project Laravel migration ব্যবহার করে। তাই direct SQL import করার বদলে সবচেয়ে safe command:

```bash
php artisan migrate --seed --force
```

এতে নিচের main table তৈরি হবে:
- `payment_methods`
- `transactions`
- `sms_devices`
- `sms_logs`
- `manual_verifications`
- `gateway_settings`
- `language_texts`
- `activity_logs`

যদি hosting provider SQL চাই, migration file:

`database/migrations/2026_05_25_000001_create_gateway_tables.php`

ওদের দিয়ে run করাতে পারেন অথবা Laravel command চালাতে বলুন।
