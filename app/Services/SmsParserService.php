<?php

namespace App\Services;

use Carbon\Carbon;

class SmsParserService
{
    private array $defaultSenders = [
        'bkash' => ['bkash', 'bKash', 'BKASH'],
        'nagad' => ['NAGAD', 'Nagad'],
        'rocket' => ['Rocket', 'DBBL', '16216'],
    ];
    private array $senders;

    public function __construct(private readonly SettingsService $settings)
    {
        $this->senders = $this->resolveSenderMap();
    }

    public function parse(string $sender, string $body, ?string $receivedAt = null): array
    {
        $method = $this->detectMethod($sender);
        $isOfficial = $method !== null;
        $amount = $this->extractAmount($body);
        $trxId = $this->extractTrxId($body);
        $customer = $this->extractCustomerNumber($body, $method);
        $smsType = $method ? $this->paymentSmsType($method, $body) : null;

        return [
            'method_slug' => $method,
            'official_sender' => $isOfficial ? $sender : null,
            'parsed_amount' => $amount,
            'parsed_customer_number' => $customer,
            'normalized_customer_number' => NumberNormalizer::mobile($customer),
            'parsed_trx_id' => $trxId,
            'received_at' => $this->extractSmsTimestamp($body) ?: ($receivedAt ? Carbon::parse($receivedAt) : now()),
            'sms_type' => $smsType ?: 'unknown',
            'is_fraud' => ! $isOfficial || ! $smsType || ! $amount || ! $trxId,
        ];
    }

    private function detectMethod(string $sender): ?string
    {
        $normalizedSender = $this->normalizeSender($sender);
        foreach ($this->senders as $method => $needles) {
            foreach ($needles as $needle) {
                if ($normalizedSender === $this->normalizeSender($needle)) {
                    return $method;
                }
            }
        }

        return null;
    }

    private function normalizeSender(string $sender): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(trim($sender))) ?: '';
    }

    private function paymentSmsType(string $method, string $body): ?string
    {
        $text = strtolower($body);

        $outgoingPatterns = [
            '/\bpayment\s+(?:tk|bdt)\s*[0-9,]+(?:\.[0-9]{1,2})?\s+to\b/i',
            '/\b(?:sent|send money to|cash out|payment to|paid to|debit|debited|withdraw|transfer to|charge paid)\b/i',
        ];

        foreach ($outgoingPatterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return 'debit';
            }
        }

        $incomingPatterns = [
            'bkash' => '/\b(?:received|you have received|cash in|payment received|credited|has been received)\b/i',
            'nagad' => '/\b(?:received|money received|cash in|payment received|credited|has been received)\b/i',
            'rocket' => '/\b(?:received|cash in|payment received|credited|has been received)\b/i',
        ];

        if (isset($incomingPatterns[$method]) && preg_match($incomingPatterns[$method], $body) === 1) {
            return 'credit';
        }

        return null;
    }

    private function resolveSenderMap(): array
    {
        $raw = (string) $this->settings->get('official_sender_map', '');
        if ($raw === '') {
            return $this->defaultSenders;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return $this->defaultSenders;
        }

        $map = [];
        foreach (['bkash', 'nagad', 'rocket'] as $method) {
            $items = $decoded[$method] ?? [];
            if (! is_array($items)) {
                $items = [];
            }
            $items = array_values(array_filter(array_map(fn ($v) => trim((string) $v), $items)));
            $map[$method] = $items ?: $this->defaultSenders[$method];
        }

        return $map;
    }

    private function extractAmount(string $body): ?float
    {
        if (preg_match('/(?:total|amount)\s*[:\-]?\s*(?:tk|bdt)?\s*([0-9,]+(?:\.[0-9]{1,2})?)/i', $body, $m)) {
            return (float) str_replace(',', '', $m[1]);
        }

        if (preg_match('/(?:tk|bdt)\s*([0-9,]+(?:\.[0-9]{1,2})?)/i', $body, $m)) {
            return (float) str_replace(',', '', $m[1]);
        }

        if (preg_match('/(?:tk|bdt|amount|received|payment|cash in|sent)\s*[:\-]?\s*([0-9,]+(?:\.[0-9]{1,2})?)/i', $body, $m)) {
            return (float) str_replace(',', '', $m[1]);
        }

        if (preg_match('/([0-9,]+(?:\.[0-9]{1,2})?)\s*(?:tk|bdt)/i', $body, $m)) {
            return (float) str_replace(',', '', $m[1]);
        }

        return null;
    }

    private function extractTrxId(string $body): ?string
    {
        if (preg_match('/(?:trxid|trx id|transaction id|txn id|txnid|trx)\s*[:#\-]?\s*([A-Z0-9]{6,30})/i', $body, $m)) {
            return strtoupper($m[1]);
        }

        return null;
    }

    private function extractCustomerNumber(string $body, ?string $method = null): ?string
    {
        if ($method === 'rocket' && preg_match('/A\/C\s*:\s*\*+([0-9]{3})/i', $body, $m)) {
            return $m[1];
        }

        if (preg_match('/(?:from|sender|customer|account)\s*[:\-]?\s*(\+?88)?(01[0-9]{9})/i', $body, $m)) {
            return ($m[1] ?? '') . $m[2];
        }

        if (preg_match('/(\+?88)?(01[0-9]{9})/', $body, $m)) {
            return ($m[1] ?? '') . $m[2];
        }

        return null;
    }

    private function extractSmsTimestamp(string $body): ?Carbon
    {
        if (preg_match('/\bat\s+([0-3]?\d)[\/\-]([01]?\d)[\/\-](20\d{2})\s+([0-2]?\d):([0-5]\d)(?::([0-5]\d))?/i', $body, $m)) {
            return Carbon::create(
                (int) $m[3],
                (int) $m[2],
                (int) $m[1],
                (int) $m[4],
                (int) $m[5],
                isset($m[6]) ? (int) $m[6] : 0,
                'Asia/Dhaka'
            );
        }

        if (preg_match('/\bDate\s*:\s*([0-3]?\d)-([A-Z]{3})-(\d{2,4})\s+([0-1]?\d):([0-5]\d)(?::([0-5]\d))?\s*(am|pm)?/i', $body, $m)) {
            $month = array_search(strtoupper($m[2]), ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'], true);
            if ($month !== false) {
                $hour = (int) $m[4];
                $ampm = strtolower($m[7] ?? '');
                if ($ampm === 'pm' && $hour < 12) {
                    $hour += 12;
                } elseif ($ampm === 'am' && $hour === 12) {
                    $hour = 0;
                }

                $year = (int) $m[3];
                if ($year < 100) {
                    $year += 2000;
                }

                return Carbon::create(
                    $year,
                    $month + 1,
                    (int) $m[1],
                    $hour,
                    (int) $m[5],
                    isset($m[6]) ? (int) $m[6] : 0,
                    'Asia/Dhaka'
                );
            }
        }

        return null;
    }
}
