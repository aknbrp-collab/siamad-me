<?php

use App\Enums\Role;
use App\Jobs\SendWebPush;
use App\Models\PushSubscription;
use App\Models\User;
use App\Support\Notify;
use App\Support\WebPush;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Bus;
use Minishlink\WebPush\VAPID;

const PUSH_ENDPOINT = 'https://fcm.googleapis.com/fcm/send/device-one';

beforeEach(function () {
    config([
        'siakad.push.public_key' => 'BPublicKeyForTests',
        'siakad.push.private_key' => 'private-key-for-tests',
    ]);
    $this->user = User::factory()->withRole(Role::Student)->create();
});

function base64url(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

/**
 * A browser-like subscription key pair, or null where OpenSSL cannot make EC
 * keys (Windows without OPENSSL_CONF, see docs/OPERATIONS.md).
 */
function browserKeys(): ?array
{
    $key = @openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);

    if ($key === false) {
        return null;
    }

    $ec = openssl_pkey_get_details($key)['ec'];

    return ['p256dh' => base64url("\x04".$ec['x'].$ec['y']), 'auth' => base64url(random_bytes(16))];
}

function subscribe(User $user, string $endpoint = PUSH_ENDPOINT): PushSubscription
{
    return $user->pushSubscriptions()->create(['endpoint' => $endpoint, 'public_key' => 'p256dh-key', 'auth_token' => 'auth-secret']);
}

test('a browser turns pop-ups on and off for its signed-in user', function () {
    $this->actingAs($this->user);

    $this->postJson(route('push.subscribe'), ['endpoint' => PUSH_ENDPOINT, 'keys' => ['p256dh' => 'p256dh-key', 'auth' => 'auth-secret']])
        ->assertOk()
        ->assertSessionHas('push_endpoint', PUSH_ENDPOINT);

    expect($this->user->pushSubscriptions()->sole())
        ->endpoint->toBe(PUSH_ENDPOINT)
        ->content_encoding->toBe('aes128gcm');

    // Someone else signs in on the same browser and turns it on: the device is theirs now.
    $other = User::factory()->withRole(Role::Student)->create();
    $this->actingAs($other)->postJson(route('push.subscribe'), ['endpoint' => PUSH_ENDPOINT, 'keys' => ['p256dh' => 'p256dh-key', 'auth' => 'auth-secret']])->assertOk();
    expect(PushSubscription::query()->sole()->user_id)->toBe($other->id);

    // Turning off only affects one's own devices.
    $this->actingAs($this->user)->deleteJson(route('push.unsubscribe'), ['endpoint' => PUSH_ENDPOINT])->assertOk();
    expect(PushSubscription::query()->count())->toBe(1);

    $this->actingAs($other)->deleteJson(route('push.unsubscribe'), ['endpoint' => PUSH_ENDPOINT])->assertOk();
    expect(PushSubscription::query()->count())->toBe(0);
});

test('only https push endpoints with keys are accepted', function (array $input) {
    $this->actingAs($this->user)->postJson(route('push.subscribe'), $input)->assertUnprocessable();
})->with([
    'plain http' => [['endpoint' => 'http://push.example.com/x', 'keys' => ['p256dh' => 'k', 'auth' => 'a']]],
    'no keys' => [['endpoint' => PUSH_ENDPOINT]],
]);

test('logging out stops pop-ups on that browser', function () {
    $this->actingAs($this->user)->postJson(route('push.subscribe'), ['endpoint' => PUSH_ENDPOINT, 'keys' => ['p256dh' => 'k', 'auth' => 'a']]);
    subscribe($this->user, 'https://fcm.googleapis.com/fcm/send/phone');

    $this->post(route('logout'));

    expect($this->user->pushSubscriptions()->pluck('endpoint')->all())->toBe(['https://fcm.googleapis.com/fcm/send/phone']);
});

test('a notification pops up on subscribed devices and opens the notification', function () {
    Bus::fake();
    subscribe($this->user);
    $other = User::factory()->create();

    Notify::send([$this->user, $other], 'bill', 'Tagihan baru', 'SPP semester ganjil', '/mahasantri/bills', 'banknotes');

    $notification = $this->user->notifications()->sole();
    Bus::assertDispatchedAfterResponseTimes(SendWebPush::class, 1);
    Bus::assertDispatchedAfterResponse(SendWebPush::class, fn (SendWebPush $job) => $job->userId === $this->user->id && $job->payload === [
        'title' => 'Tagihan baru',
        'body' => 'SPP semester ganjil',
        'url' => '/notifications/'.$notification->id,
        'tag' => $notification->id,
    ]);
});

test('grouped notifications share one pop-up that shows the latest count', function () {
    Bus::fake();
    subscribe($this->user);

    foreach (['Pesan pertama', 'Pesan kedua'] as $body) {
        Notify::send([$this->user], 'forum', 'Forum Fiqh', $body, '/forum/1', 'chat-bubble-left-right', group: 'forum-thread:1', countedBody: ':count pesan baru');
    }

    $notification = $this->user->notifications()->sole();
    Bus::assertDispatchedAfterResponse(SendWebPush::class, fn (SendWebPush $job) => $job->payload['body'] === '2 pesan baru'
        && $job->payload['tag'] === 'forum-thread:1'
        && $job->payload['url'] === '/notifications/'.$notification->id);
});

test('reminders pop up too', function () {
    Bus::fake();
    subscribe($this->user);

    Notify::remindOnce($this->user, 'bill-due:1:7', 'bill', 'Jatuh tempo', 'Tujuh hari lagi', '/mahasantri/bills', 'clock');

    Bus::assertDispatchedAfterResponse(SendWebPush::class, fn (SendWebPush $job) => $job->payload['title'] === 'Jatuh tempo');
});

test('nothing is pushed without keys or without a subscribed device', function () {
    Bus::fake();
    $subscribed = User::factory()->create();
    subscribe($subscribed);

    Notify::send([$this->user], 'bill', 'Tagihan baru', 'SPP', '/mahasantri/bills', 'banknotes');
    config(['siakad.push.public_key' => null]);
    Notify::send([$subscribed], 'bill', 'Tagihan baru', 'SPP', '/mahasantri/bills', 'banknotes');

    Bus::assertNotDispatchedAfterResponse(SendWebPush::class);
    expect($subscribed->notifications()->count())->toBe(1);
});

test('the push service delivers, and devices it reports as gone are forgotten', function () {
    $keys = browserKeys();
    if ($keys === null) {
        $this->markTestSkipped('OpenSSL cannot create EC keys here; set OPENSSL_CONF (docs/OPERATIONS.md).');
    }

    $vapid = VAPID::createVapidKeys();
    config(['siakad.push.public_key' => $vapid['publicKey'], 'siakad.push.private_key' => $vapid['privateKey']]);

    foreach (['https://fcm.googleapis.com/fcm/send/ok', 'https://fcm.googleapis.com/fcm/send/gone'] as $endpoint) {
        $this->user->pushSubscriptions()->create(['endpoint' => $endpoint, 'public_key' => $keys['p256dh'], 'auth_token' => $keys['auth']]);
    }

    $mock = new MockHandler([new Response(201), new Response(410)]);
    $push = new WebPush(['handler' => HandlerStack::create($mock)]);

    expect($push->send($this->user, ['title' => 'T', 'body' => 'B', 'url' => '/notifications', 'tag' => 'x']))->toBe(1)
        ->and($this->user->pushSubscriptions()->pluck('endpoint')->all())->toBe(['https://fcm.googleapis.com/fcm/send/ok'])
        ->and($this->user->pushSubscriptions()->sole()->last_used_at)->not->toBeNull();

    $request = $mock->getLastRequest();
    expect($request->getHeaderLine('Content-Encoding'))->toBe('aes128gcm')
        ->and($request->getHeaderLine('TTL'))->toBe('86400')
        ->and($request->getHeaderLine('Authorization'))->toStartWith('vapid ');
});

test('a failed push is logged and never breaks the notification', function () {
    $this->mock(WebPush::class)->shouldReceive('send')->andThrow(new RuntimeException('push service down'));

    (new SendWebPush($this->user->id, ['title' => 'T', 'body' => 'B', 'url' => '/', 'tag' => 'x']))->handle(app(WebPush::class));
})->throwsNoExceptions();

test('the vapid command prints a key pair once', function () {
    if (browserKeys() === null) {
        $this->markTestSkipped('OpenSSL cannot create EC keys here; set OPENSSL_CONF (docs/OPERATIONS.md).');
    }

    $this->artisan('siakad:vapid')->assertFailed();

    config(['siakad.push.public_key' => null]);
    $this->artisan('siakad:vapid')->expectsOutputToContain('VAPID_PUBLIC_KEY=')->assertSuccessful();
});

test('the notifications page offers the device switch and the app is installable', function () {
    $this->actingAs($this->user)->get(route('notifications'))
        ->assertOk()
        ->assertSee('data-test="push-device"', false)
        ->assertSee('<meta name="push-key" content="BPublicKeyForTests">', false)
        ->assertSee('<link rel="manifest" href="/manifest.webmanifest">', false);

    $manifest = $this->get('/manifest.webmanifest')->assertOk()->json();
    expect($manifest['display'])->toBe('standalone')
        ->and(collect($manifest['icons'])->pluck('sizes')->unique()->values()->all())->toBe(['192x192', '512x512'])
        ->and(file_exists(public_path('sw.js')))->toBeTrue();

    config(['siakad.push.public_key' => null]);
    $this->get(route('notifications'))->assertDontSee('name="push-key"', false);
});
