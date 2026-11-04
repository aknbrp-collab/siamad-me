<?php

namespace App\Http\Middleware;

use App\Models\CmsRequest;
use App\Support\Cms\CmsClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Calls from the CMS (spec §2 and §4.1) carry the Bearer API key and the same
 * signature SIAKAD uses: HMAC-SHA256 of "{timestamp}.{raw body}", with the
 * timestamp at most 300 seconds away. Both are compared in constant time.
 * Every inbound call is logged, refused ones included.
 */
class VerifyCmsRequest
{
    public const MAX_CLOCK_SKEW = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $started = hrtime(true);
        $response = $this->refusal($request) ?? $next($request);

        CmsRequest::create([
            'direction' => 'in',
            'method' => $request->method(),
            'endpoint' => '/'.$request->path(),
            'subject' => Str::limit((string) ($request->header('Idempotency-Key') ?? $request->input('cms_question_id')), 100, ''),
            'status' => $response->getStatusCode(),
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'request_id' => Str::limit((string) $request->header('Idempotency-Key'), 100, '') ?: null,
            'response' => Str::limit((string) $response->getContent(), 2000),
            'created_at' => now(),
        ]);

        return $response;
    }

    private function refusal(Request $request): ?Response
    {
        $apiKey = (string) config('services.cms.api_key');
        $secret = (string) config('services.cms.secret');

        if ($apiKey === '' || $secret === '') {
            return response()->json(['message' => 'The CMS integration is not set up in SIAKAD.'], 403);
        }

        if (! hash_equals($apiKey, (string) $request->bearerToken())) {
            return response()->json(['message' => 'Invalid API key.'], 401);
        }

        $timestamp = (string) $request->header('X-Siakad-Timestamp');
        $signature = (string) $request->header('X-Siakad-Signature');

        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::MAX_CLOCK_SKEW
            || ! hash_equals(CmsClient::signature((int) $timestamp, $request->getContent(), $secret), $signature)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        return null;
    }
}
