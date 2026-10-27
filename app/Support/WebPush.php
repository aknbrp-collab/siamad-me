<?php

namespace App\Support;

use App\Models\PushSubscription;
use App\Models\User;
use GuzzleHttp\Client;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush as PushClient;

/**
 * Sends Web Push pop-ups to every device a user turned them on for. Devices
 * the push service reports as gone (unsubscribed, browser data cleared) are
 * forgotten.
 */
class WebPush
{
    /**
     * @param  array<string, mixed>  $clientOptions  Guzzle options (tests pass a mock handler)
     */
    public function __construct(private readonly array $clientOptions = []) {}

    public static function enabled(): bool
    {
        return filled(config('siakad.push.public_key')) && filled(config('siakad.push.private_key'));
    }

    /**
     * @param  array{title: string, body: string, url: string, tag: string}  $payload
     * @return int the number of devices that accepted the message
     */
    public function send(User $user, array $payload): int
    {
        $subscriptions = $user->pushSubscriptions()->get();

        if (! self::enabled() || $subscriptions->isEmpty()) {
            return 0;
        }

        $client = new PushClient(
            ['VAPID' => [
                'subject' => (string) config('siakad.push.subject'),
                'publicKey' => (string) config('siakad.push.public_key'),
                'privateKey' => (string) config('siakad.push.private_key'),
            ]],
            // Keep undelivered messages for a day (a phone that is off), no longer.
            ['TTL' => 86400, 'urgency' => 'normal'],
            new Client(['timeout' => 10, ...$this->clientOptions]),
        );

        foreach ($subscriptions as $subscription) {
            $client->queueNotification(Subscription::create([
                'endpoint' => $subscription->endpoint,
                'publicKey' => $subscription->public_key,
                'authToken' => $subscription->auth_token,
                'contentEncoding' => $subscription->content_encoding,
            ]), (string) json_encode($payload));
        }

        $delivered = 0;

        foreach ($client->flush() as $report) {
            $query = PushSubscription::query()->where('endpoint', $report->getEndpoint());

            if ($report->isSuccess()) {
                $query->update(['last_used_at' => now()]);
                $delivered++;
            } elseif ($report->isSubscriptionExpired()) {
                $query->delete();
            } else {
                logger()->warning('Web Push failed', ['user_id' => $user->id, 'reason' => $report->getReason()]);
            }
        }

        return $delivered;
    }
}
