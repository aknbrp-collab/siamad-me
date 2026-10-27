<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Turns Web Push on or off for the browser the request comes from. The
 * endpoint is also kept in the session so logging out stops pop-ups for the
 * person who leaves (a shared computer must not show their messages).
 */
class PushSubscriptionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'url:https', 'max:500'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'contentEncoding' => ['nullable', 'in:aes128gcm,aesgcm'],
        ]);

        // A browser has one endpoint; whoever turned it on last receives the pop-ups.
        PushSubscription::query()->updateOrCreate(['endpoint' => $data['endpoint']], [
            'user_id' => $request->user()->id,
            'public_key' => $data['keys']['p256dh'],
            'auth_token' => $data['keys']['auth'],
            'content_encoding' => $data['contentEncoding'] ?? 'aes128gcm',
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
        ]);

        $request->session()->put('push_endpoint', $data['endpoint']);

        return response()->json(['enabled' => true]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:500']]);

        $request->user()->pushSubscriptions()->where('endpoint', $data['endpoint'])->delete();
        $request->session()->forget('push_endpoint');

        return response()->json(['enabled' => false]);
    }
}
