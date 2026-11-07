<?php

use App\Actions\Discussions\ManageDiscussions;
use App\Actions\TanyaHukum\ManageLegalQuestions;
use App\Enums\DiscussionKind;
use App\Enums\Role;
use App\Jobs\Cms\SendLegalAnswer;
use App\Models\Article;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->admin = User::factory()->withRole(Role::Admin)->create();
    [$this->ahmad, $this->hasan, $this->umar] = Teacher::factory()->count(3)->create()->all();
    $this->faqih = Student::factory()->create();
    $this->rifqi = Student::factory()->create();
});

/**
 * Act as the user through a device token (created directly: logging in for every call would hit the login limit).
 */
function asMember(User $user): TestCase
{
    app('auth')->forgetGuards();

    return test()->withToken($user->createToken('Test')->plainTextToken);
}

test('a bimbingan group: list with unread, chat with files and replies, polling after an id', function () {
    $manage = app(ManageDiscussions::class);
    $group = $manage->create(DiscussionKind::Bimbingan, 'Bimbingan Skripsi Fiqh', null, $this->ahmad, $this->admin);
    $manage->addMembers($group, [$this->faqih->user_id]);
    $first = $manage->post($group, $this->ahmad->user, 'Assalamualaikum, kirim kerangka skripsi minggu ini.');

    asMember($this->faqih->user)->getJson('/api/v1/discussions?kind=bimbingan')->assertOk()
        ->assertJsonPath('data.0.name', 'Bimbingan Skripsi Fiqh')
        ->assertJsonPath('data.0.unread', 1)
        ->assertJsonPath('data.0.lead', $this->ahmad->user->name);

    asMember($this->faqih->user)->getJson("/api/v1/discussions/{$group->id}/messages")->assertOk()->assertJsonPath('data.0.body', $first->body);
    asMember($this->faqih->user)->getJson('/api/v1/discussions?kind=bimbingan')->assertJsonPath('data.0.unread', 0);

    $sent = asMember($this->faqih->user)->post("/api/v1/discussions/{$group->id}/messages", [
        'body' => 'Baik ustadz, terlampir.', 'reply_to_id' => $first->id, 'files' => [UploadedFile::fake()->create('kerangka.pdf', 80, 'application/pdf')],
    ], ['Accept' => 'application/json'])->assertCreated()
        ->assertJsonPath('data.reply_to.id', $first->id)
        ->assertJsonPath('data.files.0.name', 'kerangka.pdf');

    // The muhadhir polls for what came after the last message they have.
    asMember($this->ahmad->user)->getJson("/api/v1/discussions/{$group->id}/messages?after_id={$first->id}")->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.author', $this->faqih->user->name);

    app('auth')->forgetGuards();
    $this->get($sent->json('data.files.0.url'))->assertOk();

    // Someone outside the group cannot read it.
    asMember($this->rifqi->user)->getJson("/api/v1/discussions/{$group->id}/messages")->assertForbidden();
});

test('moderation: the lead hides a message, mutes a member and locks the room; notes are shared', function () {
    $manage = app(ManageDiscussions::class);
    $group = $manage->create(DiscussionKind::Bimbingan, 'Bimbingan Penulisan', null, $this->ahmad, $this->admin);
    $manage->addMembers($group, [$this->faqih->user_id]);
    $message = $manage->post($group, $this->faqih->user, 'Pesan yang salah kirim');

    $member = collect(asMember($this->ahmad->user)->getJson("/api/v1/discussions/{$group->id}")->assertOk()->assertJsonPath('data.moderator', true)->json('data.members'))
        ->firstWhere('name', $this->faqih->user->name);

    asMember($this->ahmad->user)->deleteJson("/api/v1/discussions/{$group->id}/messages/{$message->id}")->assertNoContent();
    asMember($this->faqih->user)->getJson("/api/v1/discussions/{$group->id}/messages")->assertJsonPath('data.0.hidden', true)->assertJsonPath('data.0.body', null);

    asMember($this->ahmad->user)->postJson("/api/v1/discussions/{$group->id}/members/{$member['id']}/mute", ['until' => now()->addDay()->toIso8601String()])->assertNoContent();
    asMember($this->faqih->user)->postJson("/api/v1/discussions/{$group->id}/messages", ['body' => 'Halo'])->assertUnprocessable();
    asMember($this->faqih->user)->getJson("/api/v1/discussions/{$group->id}")->assertJsonPath('data.cannot_post', fn ($reason) => is_string($reason));
    asMember($this->ahmad->user)->deleteJson("/api/v1/discussions/{$group->id}/members/{$member['id']}/mute")->assertNoContent();

    $note = asMember($this->faqih->user)->postJson("/api/v1/discussions/{$group->id}/notes", ['title' => 'Daftar rujukan', 'body' => 'Al-Umm, Al-Majmu\''])->assertCreated()->json('data.id');
    asMember($this->ahmad->user)->putJson("/api/v1/discussions/{$group->id}/notes/{$note}", ['title' => 'Daftar rujukan', 'body' => 'Al-Umm, Al-Majmu\', Fathul Wahhab'])
        ->assertOk()->assertJsonPath('data.edited_by', $this->ahmad->user->name);

    asMember($this->ahmad->user)->putJson("/api/v1/discussions/{$group->id}/lock", ['locked' => true])->assertNoContent();
    asMember($this->faqih->user)->postJson("/api/v1/discussions/{$group->id}/messages", ['body' => 'Halo'])->assertUnprocessable();
    asMember($this->faqih->user)->postJson("/api/v1/discussions/{$group->id}/notes", ['title' => 'Lain', 'body' => 'Isi'])->assertUnprocessable()->assertJsonValidationErrors('body');

    // A mahasantri cannot moderate.
    asMember($this->faqih->user)->putJson("/api/v1/discussions/{$group->id}/lock", ['locked' => false])->assertUnprocessable();
});

test('LPPPK channels are open to every mahasantri and muhadhir', function () {
    $channel = app(ManageDiscussions::class)->create(DiscussionKind::Lpppk, 'Penelitian', 'Diskusi penelitian', null, $this->admin);

    asMember($this->rifqi->user)->getJson('/api/v1/discussions?kind=lpppk')->assertOk()->assertJsonFragment(['name' => 'Penelitian']);
    asMember($this->rifqi->user)->postJson("/api/v1/discussions/{$channel->id}/messages", ['body' => 'Ada yang meneliti zakat profesi?'])->assertCreated();
    asMember($this->umar->user)->getJson('/api/v1/discussions?kind=lpppk')->assertJsonPath('data.0.unread', 1);
});

test('articles: a mahasantri writes and submits, two muhadhir approve and it is published', function () {
    $body = str_repeat('Zakat profesi menurut ulama kontemporer dibahas dengan rujukan kitab. ', 3);

    $article = asMember($this->faqih->user)->postJson('/api/v1/articles', ['title' => 'Zakat Profesi', 'body' => $body, 'keywords' => 'zakat, fiqh'])
        ->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.can.submit', true)->assertJsonPath('data.can.publish', false)->json('data.id');
    asMember($this->faqih->user)->postJson("/api/v1/articles/{$article}/publish")->assertUnprocessable();
    asMember($this->faqih->user)->postJson("/api/v1/articles/{$article}/submit")->assertOk()->assertJsonPath('data.status', 'in_review');

    asMember($this->ahmad->user)->getJson('/api/v1/articles/review')->assertOk()->assertJsonPath('data.0.id', $article)->assertJsonPath('data.0.reviewed_by_me', false);
    asMember($this->ahmad->user)->postJson("/api/v1/articles/{$article}/review", ['approve' => true])->assertOk()->assertJsonPath('data.can.review', false);
    asMember($this->hasan->user)->postJson("/api/v1/articles/{$article}/review", ['approve' => true])->assertOk()->assertJsonPath('data.status', 'published');

    asMember($this->faqih->user)->getJson("/api/v1/articles/{$article}")->assertOk()
        ->assertJsonPath('data.body_html', fn ($html) => str_contains($html, 'Zakat profesi'))
        ->assertJsonPath('data.can.withdraw', true);
    asMember($this->faqih->user)->getJson('/api/v1/articles/mine')->assertJsonPath('data.0.status', 'published');

    // A muhadhir publishes their own article directly.
    $own = asMember($this->umar->user)->postJson('/api/v1/articles', ['title' => 'Adab Menuntut Ilmu', 'body' => $body])->json('data.id');
    asMember($this->umar->user)->postJson("/api/v1/articles/{$own}/publish")->assertOk()->assertJsonPath('data.status', 'published');
    expect(Article::query()->where('status', 'published')->count())->toBe(2);
});

test('Tanya Hukum: a muhadhir drafts, asks for approval, two others approve and it is sent', function () {
    Queue::fake();
    config(['services.cms.url' => 'https://admin.pondok.test/api/webhooks/siakad', 'services.cms.secret' => str_repeat('s3cr3t', 6)]);
    [$question] = app(ManageLegalQuestions::class)->receive([
        'cms_question_id' => 'Q-1', 'category' => 'muamalah', 'question' => 'Bagaimana hukum jual beli pre-order?',
        'asker' => ['name' => 'Ahmad', 'anonymous' => false], 'callback_url' => 'https://admin.pondok.test/api/webhooks/siakad/answers',
    ]);

    asMember($this->faqih->user)->getJson('/api/v1/tanya-hukum')->assertOk()->assertJsonPath('data.0.question', 'Bagaimana hukum jual beli pre-order?');
    asMember($this->faqih->user)->getJson("/api/v1/tanya-hukum/{$question->id}")->assertJsonPath('data.can.draft', false);
    asMember($this->faqih->user)->putJson("/api/v1/tanya-hukum/{$question->id}/draft", ['answer' => str_repeat('Jawaban. ', 5)])->assertUnprocessable();

    $answer = 'Jual beli pre-order termasuk akad salam yang dibolehkan dengan syarat-syaratnya.';
    asMember($this->ahmad->user)->putJson("/api/v1/tanya-hukum/{$question->id}/draft", ['answer' => $answer, 'references' => 'Fathul Qarib'])
        ->assertOk()->assertJsonPath('data.open_answer.status', 'draft');
    asMember($this->ahmad->user)->postJson("/api/v1/tanya-hukum/{$question->id}/submit")->assertOk()->assertJsonPath('data.open_answer.status', 'awaiting');
    asMember($this->ahmad->user)->postJson("/api/v1/tanya-hukum/{$question->id}/approve")->assertUnprocessable();

    asMember($this->hasan->user)->postJson("/api/v1/tanya-hukum/{$question->id}/approve")->assertOk()->assertJsonPath('data.open_answer.approvals.0.approver', $this->hasan->user->name);
    asMember($this->umar->user)->postJson("/api/v1/tanya-hukum/{$question->id}/approve")->assertOk()->assertJsonPath('data.open_answer.status', 'approved');
    Queue::assertPushed(SendLegalAnswer::class);

    // Its LPPPK room.
    asMember($this->faqih->user)->getJson("/api/v1/discussions/{$question->discussion_id}/messages")->assertOk();
});
