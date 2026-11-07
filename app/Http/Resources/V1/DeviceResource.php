<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * A device signed in to the app (one token each).
 *
 * @mixin PersonalAccessToken
 */
class DeviceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PersonalAccessToken $token */
        $token = $this->resource;
        $current = $request->user()?->currentAccessToken();

        return [
            'id' => $token->id,
            'name' => $token->name,
            'platform' => $token->getAttribute('platform'),
            'push_enabled' => filled($token->getAttribute('fcm_token')),
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'created_at' => $token->created_at?->toIso8601String(),
            /** The device making this request. */
            'current' => $current instanceof PersonalAccessToken && $current->id === $token->id,
        ];
    }
}
