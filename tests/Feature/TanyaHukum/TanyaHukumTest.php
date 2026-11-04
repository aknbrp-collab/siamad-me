<?php

use App\Actions\TanyaHukum\ManageLegalQuestions;
use App\Enums\DiscussionKind;
use App\Enums\Role;
use App\Jobs\Cms\SendLegalAnswer;
use App\Models\CmsRequest;
use App\Models\LegalAnswer;
use App\Models\LegalQuestion;
use App\Models\Student;
use App\Models\Teacher;
use App\Support\Cms\CmsClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    config([
        'services.cms.url' => 'https://admin.pondok.test/api/webhooks/siakad',
        'services.cms.secret' => str_repeat('s3cr3t', 6),
        'services.cms.api_key' => 'api-key-from-the-cms',
        'services.cms.answered_by' => "Dewan Asatidz Ma'had Aly",
    ]);

    [$this->a, $this->b, $this->c] = Teacher::factory()->count(3)->create()->all();
    $this->manage = app(ManageLegalQuestions::class);
});

function forwarded(array $overrides = []): array
{
    return [
        'cms_question_id' => '01a19XYZ',
        'tracking_code' => 'TH-7KQ4M9AB',
        'category' => 'muamalah',
        'question' => 'Bagaimana hukum jual beli dengan sistem pre-order?',
        'asker' => ['name' => 'Ahmad', 'city' => 'Kendal', 'anonymous' => false],
        'submitted_at' => '2026-10-31T09:12:00+07:00',
        'moderator_note' => "Mohon dijawab dengan rujukan kitab Syafi'iyah.",
        'callback_url' => 'https://admin.pondok.test/api/webhooks/siakad/answers',
        ...$overrides,
    ];
}

/**
 * Post as the CMS does (spec §4.1): Bearer key, signed body, Idempotency-Key.
 */
function forward(array $payload, array $tamper = []): TestResponse
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $timestamp = $tamper['timestamp'] ?? time();
    $signature = $tamper['signature'] ?? CmsClient::signature($timestamp, $body);

    return test()->call('POST', '/api/cms/legal-questions', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.($tamper['key'] ?? 'api-key-from-the-cms'),
        'HTTP_X_SIAKAD_TIMESTAMP' => (string) $timestamp,
        'HTTP_X_SIAKAD_SIGNATURE' => $signature,
        'HTTP_IDEMPOTENCY_KEY' => $payload['cms_question_id'] ?? '',
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $body);
}

test('a forwarded question is received once, with its own LPPPK discussion', function () {
    $first = forward(forwarded())->assertCreated();
    $question = LegalQuestion::query()->sole();

    expect($first->json('id'))->toBe(sprintf('SIAKAD-Q-%06d', $question->id))
        ->and($question->asker_name)->toBe('Ahmad')
        ->and($question->discussion->kind)->toBe(DiscussionKind::TanyaHukum)
        ->and($this->a->user->notifications()->count())->toBe(1);

    // The CMS retries after a timeout: same id, nothing new.
    forward(forwarded())->assertOk()->assertExactJson(['id' => $first->json('id')]);
    expect(LegalQuestion::query()->count())->toBe(1)
        ->and(CmsRequest::query()->where('direction', 'in')->count())->toBe(2);

    // Anonymous askers' names are not kept.
    forward(forwarded(['cms_question_id' => 'anon-1', 'asker' => ['name' => null, 'city' => 'Demak', 'anonymous' => true]]))->assertCreated();
    expect(LegalQuestion::query()->where('cms_question_id', 'anon-1')->sole()->askerLabel())->toContain('Demak')->not->toContain('Ahmad');
});

test('bad keys, signatures, clocks and payloads are refused as the spec says', function () {
    forward(forwarded(), ['key' => 'wrong'])->assertUnauthorized()->assertJson(['message' => 'Invalid API key.']);
    forward(forwarded(), ['signature' => str_repeat('0', 64)])->assertUnauthorized()->assertJson(['message' => 'Invalid signature.']);
    forward(forwarded(), ['timestamp' => time() - 301])->assertUnauthorized();

    forward(forwarded(['category' => 'politik']))->assertUnprocessable()->assertJsonValidationErrors('category');
    // Answers only go back to the CMS itself.
    forward(forwarded(['callback_url' => 'https://evil.example/collect']))->assertUnprocessable()->assertJsonValidationErrors('callback_url');

    config(['services.cms.api_key' => null]);
    forward(forwarded())->assertForbidden();

    expect(LegalQuestion::query()->count())->toBe(0)
        ->and(CmsRequest::query()->where('direction', 'in')->count())->toBe(6);
});

test('an answer is sent after two muhadhir other than the drafter approve it', function () {
    Queue::fake();
    [$question] = $this->manage->receive(forwarded());

    expect(fn () => $this->manage->saveDraft($question, Student::factory()->create()->user, str_repeat('Jawaban. ', 5), null))->toThrow(ValidationException::class);
    $answer = $this->manage->saveDraft($question, $this->a->user, "Jual beli **salam** boleh dengan syarat:\n\n- harga dibayar di muka\n- sifat barang jelas", 'Fathul Qarib, Bab Salam');
    $this->manage->submit($answer, $this->a->user);

    expect(fn () => $this->manage->approve($answer->fresh(), $this->a->user))->toThrow(ValidationException::class, 'drafter');
    $this->manage->approve($answer->fresh(), $this->b->user);
    expect(fn () => $this->manage->approve($answer->fresh(), $this->b->user))->toThrow(ValidationException::class, 'already');
    Queue::assertNothingPushed();

    $this->manage->approve($answer->fresh(), $this->c->user);
    expect($answer->fresh()->status)->toBe('approved');
    Queue::assertPushed(SendLegalAnswer::class);

    Http::fake(['*/answers' => Http::response(['status' => 'received', 'cms_id' => 'x', 'question_status' => 'answered', 'correction_pending' => false])]);
    (new SendLegalAnswer($answer->id))->handle(app(CmsClient::class));

    Http::assertSent(fn (Request $request) => $request->url() === 'https://admin.pondok.test/api/webhooks/siakad/answers'
        && $request['question_id'] === '01a19XYZ'
        && $request['siakad_question_id'] === $question->publicId()
        && $request['answered_by'] === "Dewan Asatidz Ma'had Aly"
        && $request['references'] === 'Fathul Qarib, Bab Salam'
        && str_contains($request['answer_html'], '<strong>salam</strong>')
        && $request->header('X-Siakad-Signature')[0] === hash_hmac('sha256', $request->header('X-Siakad-Timestamp')[0].'.'.$request->body(), str_repeat('s3cr3t', 6)));
    expect($answer->fresh())->status->toBe('sent')->cms_status->toBe('answered');
});

test('going back to draft drops approvals; a correction needs two new ones and replaces the sent answer', function () {
    Queue::fake();
    [$question] = $this->manage->receive(forwarded());
    $answer = $this->manage->saveDraft($question, $this->a->user, str_repeat('Jawaban awal. ', 5), null);
    $this->manage->submit($answer, $this->a->user);
    $this->manage->approve($answer->fresh(), $this->b->user);
    $this->manage->reopen($answer->fresh(), $this->c->user);
    expect($answer->fresh()->status)->toBe('draft')->and($answer->approvals()->count())->toBe(0);

    $this->manage->submit($answer->fresh(), $this->a->user);
    $this->manage->approve($answer->fresh(), $this->b->user);
    $this->manage->approve($answer->fresh(), $this->c->user);
    $answer->update(['status' => 'sent', 'sent_at' => now(), 'cms_status' => 'published']);

    $correction = $this->manage->startCorrection($question, $this->b->user);
    expect($correction->version)->toBe(2)->and($correction->answer_md)->toBe($answer->answer_md)
        ->and(fn () => $this->manage->startCorrection($question, $this->c->user))->toThrow(ValidationException::class);

    $this->manage->saveDraft($question, $this->b->user, str_repeat('Jawaban dikoreksi. ', 5), null);
    $this->manage->submit($correction->fresh(), $this->b->user);
    $this->manage->approve($correction->fresh(), $this->a->user);
    $this->manage->approve($correction->fresh(), $this->c->user);

    Http::fake(['*' => Http::response(['status' => 'received', 'cms_id' => 'x', 'question_status' => 'published', 'correction_pending' => true])]);
    (new SendLegalAnswer($correction->id))->handle(app(CmsClient::class));

    expect($answer->fresh()->status)->toBe('superseded')
        ->and($correction->fresh())->status->toBe('sent')->correction_pending->toBeTrue()
        ->and($correction->fresh()->statusLabel())->toContain('moderator');
});

test('a refused answer keeps the reason; a server error is retried', function () {
    [$question] = $this->manage->receive(forwarded());
    $answer = LegalAnswer::create(['legal_question_id' => $question->id, 'version' => 1, 'answer_md' => 'Jawaban.', 'drafted_by' => $this->a->user_id, 'status' => 'approved']);

    Http::fakeSequence()->push(['message' => 'The question was not forwarded.'], 409)->push(['message' => 'Oops'], 500);
    (new SendLegalAnswer($answer->id))->handle(app(CmsClient::class));
    expect($answer->fresh())->status->toBe('approved')->error->toContain('409');

    expect(fn () => (new SendLegalAnswer($answer->id))->handle(app(CmsClient::class)))->toThrow(RuntimeException::class);
});

test('mahasantri read and discuss; muhadhir write and approve on the pages', function () {
    Queue::fake();
    [$question] = $this->manage->receive(forwarded());
    $student = Student::factory()->create();

    $this->actingAs($student->user)->withSession(['active_role' => Role::Student->value]);
    $this->get(route('lpppk'))->assertSeeHtml('data-test="tanya-hukum-link"');
    $this->get(route('tanya-hukum'))->assertOk()->assertSee('pre-order');
    $this->get(route('tanya-hukum.show', $question))->assertOk()->assertDontSeeHtml('data-test="answer-form"');
    $this->get(route('discussions.show', $question->discussion))->assertOk();

    $this->actingAs($this->a->user)->withSession(['active_role' => Role::Teacher->value]);
    Livewire::test('pages::tanya-hukum.show', ['question' => $question])
        ->assertSeeHtml('data-test="answer-form"')
        ->set('answer', "Hukumnya boleh bila memenuhi syarat akad salam.\n\n1. Harga dibayar tunai\n2. Barang jelas sifatnya")
        ->set('references', 'Al-Majmu')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-test="open-answer"')
        ->assertDontSeeHtml('data-test="approve-answer"');

    $this->actingAs($this->b->user);
    Livewire::test('pages::tanya-hukum.show', ['question' => $question])->call('approve')->assertHasNoErrors();
    $this->actingAs($this->c->user);
    Livewire::test('pages::tanya-hukum.show', ['question' => $question])->call('approve')->assertHasNoErrors();

    Queue::assertPushed(SendLegalAnswer::class);
});
