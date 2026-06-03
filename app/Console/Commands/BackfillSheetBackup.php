<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Services\SheetBackupService;
use Illuminate\Console\Command;

class BackfillSheetBackup extends Command
{
    protected $signature = 'gateway:sheet-backfill {--limit= : Maximum number of newest invoices to push}';

    protected $description = 'Push existing invoices to the Google Sheet backup webhook.';

    public function handle(SheetBackupService $sheet): int
    {
        $query = Transaction::query()->oldest('id');
        $limit = (int) ($this->option('limit') ?: 0);
        if ($limit > 0) {
            $ids = Transaction::query()->latest('id')->limit($limit)->pluck('id');
            $query->whereIn('id', $ids);
        }

        $count = 0;
        $query->chunkById(500, function ($transactions) use ($sheet, &$count) {
            $sheet->pushMany($transactions, 'bulk_backfill');
            $count += $transactions->count();
            $this->line("Pushed {$transactions->count()} invoice(s).");
        });

        $this->info("Backfilled {$count} invoice(s).");

        return self::SUCCESS;
    }
}
