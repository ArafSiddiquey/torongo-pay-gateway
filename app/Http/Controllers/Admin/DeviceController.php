<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use App\Models\SmsDevice;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    private const SMS_METHODS = ['bkash', 'nagad', 'rocket'];
    private const ONLINE_GRACE_SECONDS = 30;

    public function index()
    {
        return view('admin.devices.index', ['devices' => SmsDevice::latest()->get()]);
    }

    public function status()
    {
        return response()->json([
            'devices' => SmsDevice::latest()->get()->map(function (SmsDevice $device) {
                $status = $this->deviceStatus($device);

                return [
                    'id' => $device->id,
                    'last_sync' => $this->lastSyncLabel($device),
                    'status_label' => $status['label'],
                    'status_class' => $status['class'],
                ];
            }),
        ]);
    }

    public function create()
    {
        return $this->formView(new SmsDevice(), null);
    }

    public function store(Request $request)
    {
        $data = $this->validatedDevice($request);
        $plainKey = SmsDevice::generatePlainKey();
        $device = SmsDevice::create([
            'name' => $data['name'],
            'api_key_hash' => hash('sha256', $plainKey),
            'allowed_methods' => $data['allowed_methods'],
            'is_active' => $request->boolean('is_active', true),
        ]);

        return $this->formView($device, $plainKey)
            ->with('ok', 'Device created. Copy the API key now.');
    }

    public function edit(SmsDevice $device)
    {
        return $this->formView($device, null);
    }

    public function update(Request $request, SmsDevice $device)
    {
        $data = $this->validatedDevice($request);
        $update = ['name' => $data['name'], 'allowed_methods' => $data['allowed_methods'], 'is_active' => $request->boolean('is_active')];
        $plainKey = null;

        if ($request->boolean('rotate_key')) {
            $plainKey = SmsDevice::generatePlainKey();
            $update['api_key_hash'] = hash('sha256', $plainKey);
        }

        $device->update($update);

        return $this->formView($device, $plainKey)
            ->with('ok', 'Device saved.');
    }

    public function destroy(SmsDevice $device)
    {
        $device->delete();
        return back()->with('ok', 'Device deleted.');
    }

    private function formView(SmsDevice $device, ?string $plainKey)
    {
        $deviceUrl = $this->deviceUrl();
        $allowedMethods = array_values(array_intersect($device->allowed_methods ?? self::SMS_METHODS, self::SMS_METHODS));
        $setupPayload = $plainKey ? [
            'type' => 'torongo_verify_setup',
            'server' => $deviceUrl,
            'api_key' => $plainKey,
            'device_name' => $device->name,
            'methods' => implode(',', $allowedMethods),
        ] : null;

        return view('admin.devices.form', [
            'device' => $device,
            'methods' => PaymentMethod::whereIn('slug', self::SMS_METHODS)->orderBy('sort_order')->get(),
            'plainKey' => $plainKey,
            'deviceUrl' => $deviceUrl,
            'setupPayload' => $setupPayload,
        ]);
    }

    private function validatedDevice(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'allowed_methods' => ['nullable', 'array'],
            'allowed_methods.*' => ['string', 'in:bkash,nagad,rocket'],
        ]);

        $data['allowed_methods'] = array_values(array_intersect($data['allowed_methods'] ?? [], self::SMS_METHODS));

        return $data;
    }

    private function deviceUrl(): string
    {
        $configuredUrl = rtrim((string) config('app.url'), '/');
        $host = parse_url($configuredUrl, PHP_URL_HOST);

        if (! in_array($host, ['localhost', '127.0.0.1'], true)) {
            return $configuredUrl;
        }

        $localIp = $this->localNetworkIp();

        return $localIp ? 'http://' . $localIp . ':8000' : $configuredUrl;
    }

    private function localNetworkIp(): ?string
    {
        $ips = @gethostbynamel(gethostname()) ?: [];

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
                && preg_match('/^(10\.|172\.(1[6-9]|2[0-9]|3[0-1])\.|192\.168\.)/', $ip)
            ) {
                return $ip;
            }
        }

        return null;
    }

    public static function onlineGraceSeconds(): int
    {
        return self::ONLINE_GRACE_SECONDS;
    }

    public function deviceStatus(SmsDevice $device): array
    {
        $online = $device->last_sync_at && $device->last_sync_at->gte(now()->subSeconds(self::ONLINE_GRACE_SECONDS));

        if (! $device->is_active) {
            return ['label' => 'Inactive', 'class' => 'inactive'];
        }

        return $online
            ? ['label' => 'Online', 'class' => 'online']
            : ['label' => 'Offline', 'class' => 'offline'];
    }

    public function lastSyncLabel(SmsDevice $device): string
    {
        if (! $device->last_sync_at) {
            return 'Never';
        }

        return $device->last_sync_at->format('d M Y, h:i:s A');
    }
}
