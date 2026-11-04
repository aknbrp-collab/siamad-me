<?php

use App\Actions\Articles\ManageArticles;
use App\Enums\Role;
use App\Jobs\Cms\CheckArticle;
use App\Jobs\Cms\PushArticle;
use App\Jobs\Cms\WithdrawArticle;
use App\Models\Article;
use App\Models\CmsRequest;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Cms\CmsClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    config([
        'services.cms.url' => 'https://admin.pondok.test/api/webhooks/siakad',
        'services.cms.secret' => str_repeat('s3cr3t', 6),
        'services.cms.article_type' => 'article',
    ]);

    $this->teacher = Teacher::factory()->create();
    $this->manage = app(ManageArticles::class);
});

function publishedArticle(): Article
{
    $manage = app(ManageArticles::class);
    $article = $manage->save(null, test()->teacher->user, [
        'title' => 'Hikmah Zakat Fitrah',
        'summary' => 'Ringkasan.',
        'body' => "## Pendahuluan\n\nZakat fitrah menyucikan orang yang berpuasa dari perkataan yang sia-sia.",
        'keywords' => 'zakat, ramadhan',
    ]);
    $manage->publish($article, test()->teacher->user);

    return $article->fresh();
}

test('publishing and withdrawing queue CMS calls only while the integration is set up', function () {
    Queue::fake();
    $article = publishedArticle();
    Queue::assertPushed(PushArticle::class, fn ($job) => $job->articleId === $article->id);

    $this->manage->withdraw($article, $this->teacher->user);
    Queue::assertPushed(WithdrawArticle::class);

    config(['services.cms.url' => null]);
    Queue::fake();
    publishedArticle();
    Queue::assertNothingPushed();
});

test('an article is sent signed, with the spec body, and the CMS answer is kept', function () {
    Queue::fake();
    $article = publishedArticle();
    Http::fake(['*/articles' => Http::response(['status' => 'received', 'cms_id' => '01ABC', 'review_status' => 'draft'], 201)]);

    (new PushArticle($article->id))->handle(app(CmsClient::class));

    Http::assertSent(function (Request $request) use ($article) {
        $timestamp = (int) $request->header('X-Siakad-Timestamp')[0];

        return $request->url() === 'https://admin.pondok.test/api/webhooks/siakad/articles'
            && $request->method() === 'POST'
            && abs($timestamp - time()) < 5
            // HMAC-SHA256 of "{timestamp}.{raw body}" (spec §2).
            && $request->header('X-Siakad-Signature')[0] === hash_hmac('sha256', $timestamp.'.'.$request->body(), str_repeat('s3cr3t', 6))
            && $request['id'] === $article->code
            && $request['type'] === 'article'
            && $request['authors'] === [$this->teacher->displayName()]
            && $request['year'] === now()->year
            && $request['keywords'] === ['zakat', 'ramadhan']
            && str_contains($request['body_html'], '<h2>Pendahuluan</h2>');
    });

    expect($article->fresh())->cms_id->toBe('01ABC')->cms_status->toBe('draft')->cms_sent_version->toBe(1)->cms_error->toBeNull()
        ->and(CmsRequest::query()->sole())->status->toBe(201)->subject->toBe($article->code);
});

test('a refusal is shown and not retried; a server error is retried; 429 waits for Retry-After', function () {
    Queue::fake();
    $article = publishedArticle();

    Http::fakeSequence()
        ->push(['message' => 'The given data was invalid.', 'errors' => ['type' => ['The selected type is invalid.']]], 422)
        ->push(['message' => 'Server error'], 503)
        ->push(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '30']);
    (new PushArticle($article->id))->handle(app(CmsClient::class));
    expect($article->fresh()->cms_error)->toContain('422')->toContain('The selected type is invalid.');

    expect(fn () => (new PushArticle($article->id))->handle(app(CmsClient::class)))->toThrow(RuntimeException::class);
    (new PushArticle($article->id))->failed(new RuntimeException('CMS answered 503'));
    expect($article->fresh()->cms_error)->toContain('several attempts');

    $job = (new PushArticle($article->id))->withFakeQueueInteractions();
    $job->handle(app(CmsClient::class));
    $job->assertReleased(30);

    expect((new PushArticle($article->id))->backoff())->toBe([60, 300, 900, 3600])
        ->and((new PushArticle($article->id))->tries)->toBe(5);
});

test('the review status, note and website link are read; withdrawing takes it down', function () {
    Queue::fake();
    $article = publishedArticle();

    Http::fake(['*' => Http::response([
        'id' => $article->code, 'cms_id' => '01ABC', 'review_status' => 'approved', 'review_note' => null,
        'reviewed_at' => '2026-11-04T10:15:00+07:00', 'changes_pending_review' => false,
        'public_url' => 'https://mahad.example/publikasi/hikmah-zakat-fitrah', 'received_at' => '2026-11-03T08:00:00+07:00',
    ])]);
    (new CheckArticle($article->id))->handle(app(CmsClient::class));

    Http::assertSent(fn (Request $request) => $request->method() === 'GET' && $request->body() === ''
        && $request->header('X-Siakad-Signature')[0] === hash_hmac('sha256', $request->header('X-Siakad-Timestamp')[0].'.', str_repeat('s3cr3t', 6)));
    expect($article->fresh())->cms_status->toBe('approved')->cms_public_url->toBe('https://mahad.example/publikasi/hikmah-zakat-fitrah');

    Http::fake(['*' => Http::response(['status' => 'withdrawn', 'cms_id' => '01ABC'])]);
    (new WithdrawArticle($article->id))->handle(app(CmsClient::class));
    expect($article->fresh())->cms_status->toBe('withdrawn')->cms_public_url->toBeNull();
});

test('the author sees the website status and can send again', function () {
    Queue::fake();
    $article = publishedArticle();
    $article->update(['cms_status' => 'approved', 'cms_sent_at' => now(), 'cms_sent_version' => 1, 'cms_public_url' => 'https://mahad.example/publikasi/x']);

    $this->actingAs($this->teacher->user);
    Livewire::test('pages::articles.show', ['article' => $article->fresh()])
        ->assertSeeHtml('data-test="website-status"')
        ->assertSee(__('On the website'))
        ->assertSee('https://mahad.example/publikasi/x')
        ->call('checkWebsite');
    Queue::assertPushed(CheckArticle::class);

    $article->update(['cms_error' => 'The website refused it (422).']);
    Livewire::test('pages::articles.show', ['article' => $article->fresh()])->assertSee('refused')->call('resend');
    Queue::assertPushed(PushArticle::class, 2);
});

test('Superadmin checks the settings and tests the connection', function () {
    $this->actingAs(User::factory()->withRole(Role::Superadmin)->create())->withSession(['active_role' => Role::Superadmin->value]);
    $this->get(route('superadmin.cms'))->assertOk()->assertSee('https://admin.pondok.test/api/webhooks/siakad');

    Http::fakeSequence()->push(['message' => 'Article not found.'], 404)->push(['message' => 'Invalid signature.'], 401);
    Livewire::test('pages::superadmin.cms')->call('test')->assertSet('testOk', true);

    Livewire::test('pages::superadmin.cms')->call('test')->assertSet('testOk', false)->assertSee('401');

    $this->actingAs(User::factory()->withRole(Role::Admin)->create())->get(route('superadmin.cms'))->assertForbidden();
});
