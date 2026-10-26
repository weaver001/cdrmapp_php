<?php

namespace App\Services;

use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class Fcm
{
    public function send(User $user, array $data): void
    {
        if (! config('services.fcm.enabled')) {
            return;
        }
        $project = config('services.fcm.project');
        if (! preg_match('/^[a-z][a-z0-9-]{4,62}$/', (string) $project)) {
            throw new \RuntimeException('FCM configuration is incomplete.');
        }
        $json = config('services.fcm.credentials_json');
        if ($json === null || $json === '') {
            $file = config('services.fcm.credentials');
            if (! is_string($file) || ! is_file($file) || ! is_readable($file)) {
                throw new \RuntimeException('FCM configuration is incomplete.');
            }
            $json = file_get_contents($file);
        }
        try {
            $account = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException|\TypeError) {
            throw new \RuntimeException('FCM service-account JSON is invalid.');
        }
        if (! is_array($account) || ! is_string($account['client_email'] ?? null) || ! filter_var($account['client_email'], FILTER_VALIDATE_EMAIL) || ! is_string($account['private_key'] ?? null) || $account['private_key'] === '') {
            throw new \RuntimeException('FCM service-account credentials are incomplete.');
        }
        $access = Cache::remember('fcm-token:'.hash('sha256', $json.'|'.$project), 3000, function () use ($account) {
            $key = @openssl_pkey_get_private($account['private_key']);
            if (! $key || openssl_pkey_get_details($key)['type'] !== OPENSSL_KEYTYPE_RSA) {
                throw new \RuntimeException('FCM service-account signing key is invalid.');
            }
            $encode = fn ($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
            $jwt = $encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$encode(json_encode(['iss' => $account['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging', 'aud' => 'https://oauth2.googleapis.com/token', 'iat' => time(), 'exp' => time() + 3600]));
            if (! openssl_sign($jwt, $signature, $key, OPENSSL_ALGO_SHA256)) {
                throw new \RuntimeException('FCM signing failed.');
            }
            $response = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt.'.'.$encode($signature)]);
            if (! $response->successful() || ! $response->json('access_token')) {
                throw new \RuntimeException('FCM authentication failed (HTTP '.$response->status().').');
            }

            return $response->json('access_token');
        });
        foreach (DeviceToken::where('user_id', $user->id)->cursor() as $device) {
            $response = Http::withToken($access)->timeout(15)->post('https://fcm.googleapis.com/v1/projects/'.$project.'/messages:send', ['message' => ['token' => $device->token, 'data' => array_map('strval', $data), 'android' => ['priority' => 'normal']]]);
            if ($response->json('error.details.0.errorCode') === 'UNREGISTERED') {
                $device->delete();

                continue;
            }
            if (! $response->successful()) {
                throw new \RuntimeException('FCM delivery failed (HTTP '.$response->status().').');
            }
        }
    }
}
