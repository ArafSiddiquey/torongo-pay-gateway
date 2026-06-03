<?php

namespace App\Console\Commands;

use App\Models\GatewaySetting;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupOldInvoices extends Command
{
    protected $signature = 'gateway:cleanup-old-invoices {--force : Run cleanup even if the two-month interval has not passed}';

    protected $description = 'Delete invoices older than one month, no more than once every two months unless forced.';

    public function handle(): int
    {
        $lastRun = GatewaySetting::where('key', 'invoice_cleanup_last_run_at')->value('value');
        if (! $this->option('force') && $lastRun && Carbon::parse($lastRun)->gt(now()->subMonths(2))) {
            $this->info('Cleanup skipped. Last cleanup was less than two months ago.');
            return self::SUCCESS;
        }

        $cutoff = now()->subMonth();
        $deleted = 0;

        Transaction::where('created_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(200, function ($transactions) use (&$deleted) {
                foreach ($transactions as $transaction) {
                    if ($transaction->payment_proof_path) {
                        Storage::disk('public')->delete($transaction->payment_proof_path);
                    }
                    $transaction->delete();
                    $deleted++;
                }
            });

        GatewaySetting::updateOrCreate(
            ['key' => 'invoice_cleanup_last_run_at'],
            ['value' => now()->toIso8601String()]
        );

        $this->info("Deleted {$deleted} invoice(s) older than one month.");

        return self::SUCCESS;
    }
}
