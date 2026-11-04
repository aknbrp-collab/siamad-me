<?php

namespace App\Support\Cms;

use App\Models\CmsRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Calls the CMS webhooks (v5 D31, docs/req/siakad-api-spec.md §2–3). Every
 * request is signed: HMAC-SHA256 of "{timestamp}.{raw body}" with the shared
 * secret, sent in X-Siakad-Timestamp and X-Siakad-Signature. The body is the
 * exact JSON bytes sent (empty for GET and DELETE). Every call is logged.
 */
class CmsClient
{
    public const TIMEOUT_SECONDS = 15;

    public static function enabled(): bool
    {
        return filled(config('services.cms.url')) && filled(config('services.cms.secret'));
    }

    public static function signature(int $timestamp, string $body, ?string $secret = null): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, (string) ($secret ?? config('services.cms.secret')));
    }

    /**
     * @param  array<string, mixed>|null  $payload
     *
     * @throws ConnectionException when the CMS cannot be reached (logged first)
     */
    public function send(string $method, string $path, ?array $payload = null, ?string $subject = null): Response
    {
        $body = $payload === null ? '' : (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = time();
        $requestId = (string) Str::uuid();
        $started = hrtime(true);

        $request = Http::withHeaders([
            'X-Siakad-Timestamp' => (string) $timestamp,
            'X-Siakad-Signature' => self::signature($timestamp, $body),
            'X-Request-Id' => $requestId,
            'Accept' => 'application/json',
        ])->timeout(self::TIMEOUT_SECONDS);

        if ($body !== '') {
            $request = $request->withBody($body, 'application/json');
        }

        $url = rtrim((string) config('services.cms.url'), '/').'/'.ltrim($path, '/');

        try {
            $response = $request->send($method, $url);
        } catch (ConnectionException $exception) {
            $this->log($method, $path, $subject, null, $started, $requestId, null, $exception->getMessage());

            throw $exception;
        }

        $this->log($method, $path, $subject, $response->status(), $started, $requestId, $response->body());

        return $response;
    }

    private function log(string $method, string $path, ?string $subject, ?int $status, int|float $started, string $requestId, ?string $response, ?string $error = null): void
    {
        CmsRequest::create([
            'direction' => 'out',
            'method' => $method,
            'endpoint' => '/'.ltrim($path, '/'),
            'subject' => $subject,
            'status' => $status,
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'request_id' => $requestId,
            'response' => $response !== null ? Str::limit($response, 2000) : null,
            'error' => $error !== null ? Str::limit($error, 2000) : null,
            'created_at' => now(),
        ]);
    }
}
