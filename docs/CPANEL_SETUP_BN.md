# Torongo Pay cPanel Setup Guide

এই guide shared hosting/cPanel-এর জন্য। সবচেয়ে ভালো setup হলো subdomain-এর document root সরাসরি Laravel `public` folder-এ point করা।

## 1. Hosting Requirement

- PHP 8.2 বা 8.3
- MySQL/MariaDB
- Composer support থাকলে ভালো
- Subdomain document root: `your-folder/public`

## 2. Upload

1. final ZIP extract করুন।
2. পুরো project folder hosting account-এ upload করুন।
3. cPanel > Domains থেকে subdomain-এর document root `project-folder/public` করে দিন।
4. `.env.example` copy করে `.env` বানান।
5. `.env`-এ আপনার domain/database info বসান।

## 3. .env Minimum Setup

```env
APP_NAME=Torongo Pay
APP_ENV=production
APP_DEBUG=false
APP_URL=https://pay.yourdomain.com

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=your_cpanel_database
DB_USERNAME=your_cpanel_db_user
DB_PASSWORD=your_db_password

WEBHOOK_SECRET=put-a-long-random-secret-here
```

`APP_KEY` blank থাকলে cPanel Terminal থেকে `php artisan key:generate` চালাতে হবে। Terminal না থাকলে local PC-তে key generate করে `.env`-এ paste করুন।

## 4. Database

1. cPanel > MySQL Databases থেকে database/user তৈরি করুন।
2. user-কে database-এর সব permission দিন।
3. Terminal থাকলে project folder-এ চালান:

```bash
php artisan migrate --seed --force
php artisan optimize:clear
```

Terminal না থাকলে phpMyAdmin দিয়ে exported SQL import করতে হবে।

## 5. Default Admin

- URL: `https://pay.yourdomain.com/admin/login`
- Email: `admin@example.com`
- Password: `admin12345`

Login করার পর password change করবেন।

## 6. Admin Setup

1. Settings/Gateway Setup: gateway name, logo, support number/link, redirect URL, webhook secret update করুন।
2. Payment Methods: bKash, Nagad, Rocket, Binance number/QR/API info set করুন।
3. SMS Devices: Android phone add করে API key copy করুন।
4. Language/Text: Bangla/English text প্রয়োজনমতো edit করুন।

## 7. Android Phone Setup

1. APK install করুন।
2. Server URL দিন: `https://pay.yourdomain.com`
3. Device API key দিন।
4. Allowed methods tick করুন।
5. SMS permission allow করুন।
6. Battery optimization off করুন।

## 8. WordPress/WooCommerce

1. WordPress plugin ZIP upload করে activate করুন।
2. WooCommerce > Settings > Payments > Torongo Pay enable করুন।
3. Gateway URL ও Webhook Secret বসান।
4. Test order দিয়ে checkout করুন।

## 9. Security

- `APP_DEBUG=false`
- `.env` public folder-এ রাখবেন না।
- Webhook secret strong রাখুন।
- Admin password change করুন।
- Android API key leak হলে device key rotate/delete করুন।
- Live করার আগে real official SMS sample দিয়ে test করুন।
