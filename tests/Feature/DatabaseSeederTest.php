<?php

namespace Tests\Feature;

use App\Models\LanguageText;
use App\Models\PaymentMethod;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_gateway_seed_data_contains_readable_bangla_text(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame('বিকাশ', PaymentMethod::where('slug', 'bkash')->value('name_bn'));
        $this->assertSame('নগদ', PaymentMethod::where('slug', 'nagad')->value('name_bn'));
        $this->assertSame('রকেট', PaymentMethod::where('slug', 'rocket')->value('name_bn'));
        $this->assertSame(
            'পেমেন্ট করুন',
            LanguageText::where('key', 'pay_now')->where('lang', 'bn')->value('value')
        );
        $this->assertSame(
            'পেমেন্ট ব্যর্থ বা মেয়াদ শেষ',
            LanguageText::where('key', 'payment_failed')->where('lang', 'bn')->value('value')
        );
    }
}
