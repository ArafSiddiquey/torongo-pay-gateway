<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function showLogin(SettingsService $settings)
    {
        return view('admin.login', [
            'googleReady' => $this->googleClientId($settings) !== ''
                && $this->googleClientSecret($settings) !== ''
                && $this->allowedGoogleEmail($settings) !== '',
            'allowedEmail' => $this->allowedGoogleEmail($settings),
        ]);
    }

    public function redirectToGoogle(Request $request, SettingsService $settings)
    {
        $clientId = $this->googleClientId($settings);
        $clientSecret = $this->googleClientSecret($settings);
        $allowedEmail = $this->allowedGoogleEmail($settings);

        if ($clientId === '' || $clientSecret === '' || $allowedEmail === '') {
            return redirect()->route('admin.login')->withErrors([
                'google' => 'Google admin login is not configured.',
            ]);
        }

        $state = Str::random(48);
        $request->session()->put('google_oauth_state', $state);

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => route('admin.google.callback'),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'prompt' => 'select_account',
        ]));
    }

    public function handleGoogleCallback(Request $request, SettingsService $settings)
    {
        if (! hash_equals((string) $request->session()->pull('google_oauth_state'), (string) $request->query('state'))) {
            return redirect()->route('admin.login')->withErrors(['google' => 'Invalid Google login state.']);
        }

        $code = (string) $request->query('code');
        if ($code === '') {
            return redirect()->route('admin.login')->withErrors(['google' => 'Google login was cancelled.']);
        }

        $tokenResponse = Http::asForm()->timeout(12)->post('https://oauth2.googleapis.com/token', [
            'client_id' => $this->googleClientId($settings),
            'client_secret' => $this->googleClientSecret($settings),
            'redirect_uri' => route('admin.google.callback'),
            'grant_type' => 'authorization_code',
            'code' => $code,
        ]);

        if (! $tokenResponse->successful()) {
            return redirect()->route('admin.login')->withErrors(['google' => 'Google token exchange failed.']);
        }

        $accessToken = (string) ($tokenResponse->json('access_token') ?? '');
        $profileResponse = Http::withToken($accessToken)->timeout(12)->get('https://openidconnect.googleapis.com/v1/userinfo');

        if (! $profileResponse->successful()) {
            return redirect()->route('admin.login')->withErrors(['google' => 'Google profile check failed.']);
        }

        $email = strtolower((string) $profileResponse->json('email'));
        $allowedEmail = strtolower($this->allowedGoogleEmail($settings));
        $emailVerified = (bool) $profileResponse->json('email_verified');

        if (! $emailVerified || ! hash_equals($allowedEmail, $email)) {
            return redirect()->route('admin.login')->withErrors(['google' => 'This Google account is not allowed for admin access.']);
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => (string) ($profileResponse->json('name') ?: $email),
                'password' => Str::random(64),
                'email_verified_at' => now(),
            ]
        );

        $request->session()->regenerate();
        $request->session()->put('admin_id', $user->id);

        return redirect()->route('admin.dashboard');
    }

    public function logout(Request $request)
    {
        $request->session()->forget('admin_id');
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    private function googleClientId(SettingsService $settings): string
    {
        return trim((string) $settings->get('google_client_id', env('GOOGLE_CLIENT_ID', '')));
    }

    private function googleClientSecret(SettingsService $settings): string
    {
        return trim((string) $settings->get('google_client_secret', env('GOOGLE_CLIENT_SECRET', '')));
    }

    private function allowedGoogleEmail(SettingsService $settings): string
    {
        return trim((string) $settings->get('google_admin_email', env('GOOGLE_ADMIN_EMAIL', '')));
    }
}
