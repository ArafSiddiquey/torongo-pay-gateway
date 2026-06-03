# Android SMS Reader Build Guide

এই app merchant/payment receive করা Android phone-এ থাকবে। Official bKash/Nagad/Rocket SMS এলে app SMS queue করে server-এ sync করবে। Internet না থাকলে queue থাকবে, পরে online হলে auto sync হবে।

## কখন Android Studio লাগবে

শুধু APK build করার জন্য Android Studio লাগবে। Laravel/admin/payment page local test করার জন্য Android Studio দরকার নেই।

## Build Steps

1. Android Studio install করুন।
2. `android-sms-reader` folder Android Studio দিয়ে open করুন।
3. Gradle sync শেষ হওয়া পর্যন্ত অপেক্ষা করুন।
4. Build > Build APKs চাপুন।
5. APK merchant phone-এ install করুন।

## App Setup

1. Server URL: `https://your-gateway-domain.com`
2. Device API key: Admin > SMS Devices থেকে copy করা key
3. Device name: যেমন `Main bKash Phone`
4. Allowed methods: bKash / Nagad / Rocket
5. Save setup চাপুন।
6. SMS permission allow করুন।
7. Battery optimization off করুন।

## Auto Sync Logic

- SMS এলে app official sender/body prefilter করবে।
- Allowed method match হলে SMS local queue-তে যাবে।
- Internet থাকলে সঙ্গে সঙ্গে server sync হবে।
- Internet না থাকলে queue থাকবে।
- Phone online হলে/boot হলে queued SMS auto sync হবে।
- Sync now button দিয়ে manual sync করা যাবে।

## Live Test

1. Customer payment page থেকে pending payment তৈরি করুন।
2. Merchant phone-এ official payment SMS আসতে দিন।
3. Amount, method, sender number/time window match হলে payment successful হবে।
4. Phone offline থাকলে online হওয়ার পর same pending transaction verify হতে পারবে।
