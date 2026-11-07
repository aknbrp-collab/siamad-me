<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;
use RuntimeException;

/**
 * Push notifications to the mobile app through Firebase Cloud Messaging
 * (HTTP v1 API, v6 D39). Each signed-in device stores its FCM token on its
 * API token (PUT /api/v1/auth/push-token). Off until FIREBASE_CREDENTIALS
 * points to the project's service-account key file.
 *
 * The service account signs a short JWT that Google exchanges for an access
 * token (cached for 50 minutes); each device then gets one request. Tokens
 * Firebase reports as unknown are cleared, so dead devices stop costing a call.
 */
final class FirebasePush
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public static function enabled(): bool
    {
        return self::credentials() !== null;
    }

    /**
     * The service-account key: project_id, client_email and private_key.
     *
     * @return array{project_id: string, client_email: string, private_key: string}|null
     */
    public static function credentials(): ?array
    {
        $path = config('services.firebase.credentials');

        if (! is_string($path) || $path === '' || ! is_file($path)) {
            return null;
        }

        $key = json_decode((string) file_get_contents($path), true);

        if (! is_array($key) || ! is_string($key['project_id'] ?? null) || ! is_string($key['client_email'] ?? null) || ! is_string($key['private_key'] ?? null)) {
            return null;
        }

        return ['project_id' => $key['project_id'], 'client_email' => $key['client_email'], 'private_key' => $key['private_key']];
    }

    /**
     * @param  array{title: string, body: string, link: string, notification_id: string, tag: string}  $payload
     * @return int the number of devices Firebase accepted the message for
     */
    public function send(User $user, array $payload): int
    {
        $credentials = self::credentials();
        $devices = $user->tokens()->whereNotNull('fcm_token')->get();

        if ($credentials === null || $devices->isEmpty()) {
            return 0;
        }

        $url = 'https://fcm.googleapis.com/v1/projects/'.$credentials['project_id'].'/messages:send';
        $accessToken = $this->accessToken($credentials);
        $delivered = 0;

        foreach ($devices as $device) {
            /** @var PersonalAccessToken $device */
            $response = Http::withToken($accessToken)->timeout(10)->post($url, ['message' => [
                'token' => $device->getAttribute('fcm_token'),
                'notification' => ['title' => $payload['title'], 'body' => $payload['body']],
                // For the app: the page the notification is about, and its id to mark it read.
                'data' => ['link' => $payload['link'], 'notification_id' => $payload['notification_id'], 'tag' => $payload['tag']],
                // Grouped notifications (e.g. new messages in one room) replace each other.
                'android' => ['notification' => ['tag' => $payload['tag']]],
                'apns' => ['headers' => ['apns-collapse-id' => mb_substr($payload['tag'], 0, 64)]],
            ]]);

            if ($response->successful()) {
                $delivered++;

                continue;
            }

            // The app was removed or the token replaced: forget it.
            $error = (string) $response->json('error.details.0.errorCode', $response->json('error.status', ''));

            if ($response->status() === 404 || in_array($error, ['UNREGISTERED', 'INVALID_ARGUMENT'], true)) {
                $device->forceFill(['fcm_token' => null])->save();
            }
        }

        return $delivered;
    }

    /**
     * @param  array{project_id: string, client_email: string, private_key: string}  $credentials
     */
    private function accessToken(array $credentials): string
    {
        return Cache::remember('firebase.access_token.'.md5($credentials['client_email']), now()->addMinutes(50), function () use ($credentials) {
            $now = time();
            $encode = fn (array $part) => rtrim(strtr(base64_encode((string) json_encode($part)), '+/', '-_'), '=');
            $unsigned = $encode(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$encode([
                'iss' => $credentials['client_email'],
                'scope' => self::SCOPE,
                'aud' => self::TOKEN_URL,
                'iat' => $now,
                'exp' => $now + 3600,
            ]);

            if (! openssl_sign($unsigned, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('The Firebase service-account key could not sign the request.');
            }

            $response = Http::asForm()->timeout(10)->post(self::TOKEN_URL, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $unsigned.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '='),
            ])->throw();

            return (string) $response->json('access_token');
        });
    }
}
