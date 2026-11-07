<?php

use App\Jobs\SendFirebasePush;
use App\Models\Student;
use App\Support\FirebasePush;
use App\Support\Notify;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;

const FIREBASE_SEND_URL = 'https://fcm.googleapis.com/v1/projects/siakad-test/messages:send';

beforeEach(function () {
    $this->student = Student::factory()->create();
    $this->phone = $this->student->user->createToken('Phone');
    $this->tablet = $this->student->user->createToken('Tablet');
    $this->phone->accessToken->forceFill(['fcm_token' => 'fcm-phone'])->save();
    $this->tablet->accessToken->forceFill(['fcm_token' => 'fcm-tablet'])->save();
});

function useFirebase(): void
{
    config(['services.firebase.credentials' => base_path('tests/Fixtures/firebase-service-account.json')]);
}

/**
 * @return array{title: string, body: string, link: string, notification_id: string, tag: string}
 */
function pushPayload(): array
{
    return ['title' => 'Tugas baru', 'body' => 'Fiqh Ibadah', 'link' => '/mahasantri/classes/1', 'notification_id' => 'n-1', 'tag' => 'n-1'];
}

test('without the Firebase key nothing is pushed and the app is told so', function () {
    Bus::fake();

    Notify::send([$this->student->user], 'test', 'Tugas baru', 'Fiqh Ibadah', '/mahasantri/classes', 'clipboard');

    Bus::assertNotDispatchedAfterResponse(SendFirebasePush::class);
    $this->getJson('/api/v1/app')->assertJsonPath('data.push_enabled', false);
});

test('with the key, a notification is pushed to the devices with a push token, carrying its link', function () {
    useFirebase();
    Bus::fake();

    Notify::send([$this->student->user], 'test', 'Tugas baru', 'Fiqh Ibadah', '/mahasantri/classes', 'clipboard');

    Bus::assertDispatchedAfterResponse(SendFirebasePush::class, fn (SendFirebasePush $job) => $job->userId === $this->student->user_id
        && $job->payload['link'] === '/mahasantri/classes'
        && $job->payload['notification_id'] === $this->student->user->notifications()->value('id'));
    $this->getJson('/api/v1/app')->assertJsonPath('data.push_enabled', true);
});

test('the service account signs for an access token once, and each device gets the message', function () {
    useFirebase();
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'google-token', 'expires_in' => 3600]),
        'fcm.googleapis.com/*' => Http::response(['name' => 'projects/siakad-test/messages/1']),
    ]);

    expect(app(FirebasePush::class)->send($this->student->user, pushPayload()))->toBe(2)
        ->and(app(FirebasePush::class)->send($this->student->user, pushPayload()))->toBe(2);

    // One token request for both sends; its JWT is signed with the service-account key.
    $tokenRequests = Http::recorded(fn (Request $request) => str_contains($request->url(), 'oauth2.googleapis.com'));
    expect($tokenRequests)->toHaveCount(1);
    [$header, $claims, $signature] = explode('.', $tokenRequests[0][0]['assertion']);
    $decode = fn (string $part) => base64_decode(strtr($part, '-_', '+/'));
    $key = json_decode((string) file_get_contents(base_path('tests/Fixtures/firebase-service-account.json')), true);
    $public = openssl_pkey_get_details(openssl_pkey_get_private($key['private_key']))['key'];
    expect(openssl_verify($header.'.'.$claims, $decode($signature), $public, OPENSSL_ALGO_SHA256))->toBe(1)
        ->and(json_decode($decode($claims), true)['scope'])->toBe('https://www.googleapis.com/auth/firebase.messaging');

    Http::assertSent(fn (Request $request) => $request->url() === FIREBASE_SEND_URL
        && $request->hasHeader('Authorization', 'Bearer google-token')
        && $request['message']['token'] === 'fcm-phone'
        && $request['message']['notification']['title'] === 'Tugas baru'
        && $request['message']['data']['link'] === '/mahasantri/classes/1');
});

test('a device Firebase no longer knows is forgotten', function () {
    useFirebase();
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'google-token']),
        'fcm.googleapis.com/*' => fn (Request $request) => $request['message']['token'] === 'fcm-tablet'
            ? Http::response(['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404)
            : Http::response(['name' => 'ok']),
    ]);

    expect(app(FirebasePush::class)->send($this->student->user, pushPayload()))->toBe(1)
        ->and($this->tablet->accessToken->fresh()->getAttribute('fcm_token'))->toBeNull()
        ->and($this->phone->accessToken->fresh()->getAttribute('fcm_token'))->toBe('fcm-phone');
});

test('a failed push is logged and does not break anything', function () {
    useFirebase();
    Exceptions::fake();
    Http::fake(['oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant'], 400)]);

    (new SendFirebasePush($this->student->user_id, pushPayload()))->handle(app(FirebasePush::class));

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'fcm.googleapis.com'));
    Exceptions::assertReported(RequestException::class);
});
