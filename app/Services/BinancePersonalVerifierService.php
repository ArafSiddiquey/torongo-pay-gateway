<?php

namespace App\Services;

use App\Models\ManualVerification;
use App\Models\Transaction;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BinancePersonalVerifierService
{
    public function check(Transaction $transaction): ?string
    {
        if ($transaction->method_slug !== 'binance' || $transaction->status !== Transaction::STATUS_PENDING) {
            return null;
        }

        $method = $transaction->paymentMethod;
        $config = $method?->config ?? [];
        $apiKey = trim($this->decryptSecret($config['binance_api_key'] ?? null));
        $secret = trim($this->decryptSecret($config['binance_secret_key'] ?? null));

        if ($apiKey === '' || $secret === '') {
            return null;
        }

        $submittedOrderId = ManualVerification::where('transaction_id', $transaction->id)
            ->latest()
            ->value('trx_id');

        if (! $submittedOrderId) {
            return null;
        }

        $submittedOrderId = strtoupper(trim($submittedOrderId));
        if (Transaction::where('trx_id', $submittedOrderId)->whereKeyNot($transaction->id)->exists()) {
            $transaction->update(['manual_note' => 'Duplicate Binance Order ID blocked: ' . $submittedOrderId]);
            return 'duplicate';
        }

        $rows = $this->transactions($apiKey, $secret, $transaction);
        foreach ($rows as $row) {
            if (! $this->matchesOrderId($row, $submittedOrderId)) {
                continue;
            }

            if (! $this->isIncoming($row)) {
                continue;
            }

            $paidAmount = $this->amount($row);
            if ($paidAmount === null) {
                continue;
            }

            $expectedAmount = $this->expectedAssetAmount($transaction, $config, $row);
            if ($paidAmount + 0.000001 < $expectedAmount) {
                $transaction->update([
                    'manual_note' => sprintf(
                        'Binance underpaid. Required: %s, paid: %s, Order ID: %s',
                        rtrim(rtrim(number_format($expectedAmount, 8, '.', ''), '0'), '.'),
                        rtrim(rtrim(number_format($paidAmount, 8, '.', ''), '0'), '.'),
                        $submittedOrderId
                    ),
                ]);

                return 'underpaid';
            }

            $transaction->update([
                'status' => Transaction::STATUS_SUCCESS,
                'trx_id' => $submittedOrderId,
                'verified_at' => now(),
                'metadata' => array_merge($transaction->metadata ?? [], [
                    'binance_paid_amount' => $paidAmount,
                    'binance_expected_amount' => $expectedAmount,
                    'binance_asset' => $this->asset($row, $config),
                    'binance_matched_row' => $row,
                ]),
                'manual_note' => 'Binance personal API matched payment.',
            ]);

            return 'success';
        }

        return null;
    }

    private function transactions(string $apiKey, string $secret, Transaction $transaction): array
    {
        $timestamp = (int) floor(microtime(true) * 1000);
        $startTime = max(0, ($transaction->created_at?->timestamp ?? now()->timestamp) * 1000);
        $params = [
            'startTime' => $startTime,
            'endTime' => $timestamp,
            'timestamp' => $timestamp,
            'recvWindow' => 5000,
        ];
        $query = http_build_query($params, '', '&');
        $signature = hash_hmac('sha256', $query, $secret);

        try {
            $response = Http::connectTimeout(2)->timeout(5)
                ->withHeaders(['X-MBX-APIKEY' => $apiKey])
                ->get('https://api.binance.com/sapi/v1/pay/transactions?' . $query . '&signature=' . $signature);

            if (! $response->successful()) {
                Log::warning('Binance personal pay history failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [];
            }

            $json = $response->json();
            if (isset($json['data']) && is_array($json['data'])) {
                return $json['data'];
            }

            return is_array($json) ? $json : [];
        } catch (\Throwable $exception) {
            Log::warning('Binance personal pay history exception', ['error' => $exception->getMessage()]);

            return [];
        }
    }

    private function matchesOrderId(array $row, string $submittedOrderId): bool
    {
        foreach (['transactionId', 'orderId', 'orderNo', 'merchantTradeNo', 'tradeNo'] as $key) {
            if (isset($row[$key]) && strtoupper((string) $row[$key]) === $submittedOrderId) {
                return true;
            }
        }

        return str_contains(strtoupper(json_encode($row, JSON_UNESCAPED_UNICODE)), $submittedOrderId);
    }

    private function isIncoming(array $row): bool
    {
        $text = strtoupper(json_encode($row, JSON_UNESCAPED_UNICODE));
        foreach (['PAY', 'SEND', 'OUT', 'DEBIT', 'WITHDRAW'] as $blocked) {
            if (str_contains($text, '"ORDER_TYPE":"' . $blocked) || str_contains($text, '"DIRECTION":"' . $blocked)) {
                return false;
            }
        }

        foreach (['RECEIVE', 'INCOME', 'CREDIT', 'C2C'] as $allowed) {
            if (str_contains($text, $allowed)) {
                return true;
            }
        }

        return true;
    }

    private function amount(array $row): ?float
    {
        foreach (['amount', 'receiveAmount', 'orderAmount', 'totalAmount', 'quantity'] as $key) {
            if (isset($row[$key]) && is_numeric($row[$key])) {
                return (float) $row[$key];
            }
        }

        if (isset($row['fundsDetail']) && is_array($row['fundsDetail'])) {
            foreach ($row['fundsDetail'] as $fund) {
                foreach (['amount', 'quantity'] as $key) {
                    if (isset($fund[$key]) && is_numeric($fund[$key])) {
                        return (float) $fund[$key];
                    }
                }
            }
        }

        return null;
    }

    private function expectedAssetAmount(Transaction $transaction, array $config, array $row): float
    {
        $rowAsset = strtoupper($this->asset($row, $config));
        $transactionCurrency = strtoupper((string) $transaction->currency);

        if ($rowAsset === $transactionCurrency) {
            return (float) $transaction->amount;
        }

        $rate = max((float) ($config['binance_exchange_rate'] ?? 0), 0.000001);

        return $this->truncateToTwoDecimals(((float) $transaction->amount) / $rate);
    }

    private function truncateToTwoDecimals(float $amount): float
    {
        return floor($amount * 100) / 100;
    }

    private function asset(array $row, array $config): string
    {
        foreach (['currency', 'asset', 'coin'] as $key) {
            if (! empty($row[$key])) {
                return strtoupper((string) $row[$key]);
            }
        }

        if (isset($row['fundsDetail']) && is_array($row['fundsDetail'])) {
            foreach ($row['fundsDetail'] as $fund) {
                foreach (['currency', 'asset', 'coin'] as $key) {
                    if (! empty($fund[$key])) {
                        return strtoupper((string) $fund[$key]);
                    }
                }
            }
        }

        return strtoupper((string) ($config['binance_asset'] ?? 'USDT'));
    }

    private function decryptSecret(?string $value): string
    {
        if (! $value) {
            return '';
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return $value;
        }
    }
}
