<?php

use App\Actions\Discussions\ManageDiscussions;
use App\Actions\Discussions\ModerateLpppk;
use App\Enums\DiscussionKind;
use App\Enums\Role;
use App\Models\Discussion;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->manage = app(ManageDiscussions::class);
    $this->moderate = app(ModerateLpppk::class);
    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->teacher = Teacher::factory()->create();
    $this->student = Student::factory()->create();
    $this->other = Student::factory()->create();
    $this->research = $this->manage->create(DiscussionKind::Lpppk, 'Penelitian', 'Rencana penelitian', null, $this->admin);
    $this->general = $this->manage->create(DiscussionKind::Lpppk, 'Umum', null, null, $this->admin);
});

test('every mahasantri and muhadhir sees the channels, with unread counts even before opening one', function () {
    $this->manage->post($this->research, $this->teacher->user, 'Ada yang mau meneliti zakat profesi?');
    $this->manage->post($this->research, $this->other->user, 'Saya tertarik, ustadz.');

    expect($this->manage->unreadLpppk($this->student->user))->toBe([$this->research->id => 2]);

    $this->actingAs($this->student->user)->withSession(['active_role' => Role::Student->value])
        ->get(route('lpppk'))->assertOk()->assertSee('Penelitian')->assertSee('Umum')->assertSee('Saya tertarik, ustadz.');

    // Opening the channel reads it.
    $this->get(route('discussions.show', $this->research))->assertOk();
    expect($this->manage->unreadLpppk($this->student->user))->toBe([]);

    $this->actingAs(User::factory()->withRole(Role::Guardian)->create())->get(route('lpppk'))->assertForbidden();
});

test('in LPPPK only the author of the message replied to is told', function () {
    $question = $this->manage->post($this->general, $this->student->user, 'Apa beda qiyas dan istihsan?');
    $this->manage->post($this->general, $this->other->user, 'Saya juga ingin tahu.');
    $this->manage->post($this->general, $this->teacher->user, 'Istihsan adalah ...', [], $question->id);

    expect($this->student->user->notifications()->count())->toBe(1)
        ->and($this->other->user->notifications()->count())->toBe(0);
});

test('a muted person reads but cannot post anywhere in LPPPK; a restricted one cannot open it', function () {
    $this->moderate->mute($this->student->user, now()->addDays(3), 'Pesan tidak sopan', $this->admin);

    expect(fn () => $this->manage->post($this->general, $this->student->user, 'Halo'))->toThrow(ValidationException::class, 'muted in LPPPK')
        ->and($this->student->user->notifications()->sole()->data['body'])->toBe('Pesan tidak sopan');
    $this->actingAs($this->student->user)->get(route('discussions.show', $this->research))->assertOk();

    // Bimbingan groups are not affected.
    $group = $this->manage->create(DiscussionKind::Bimbingan, 'Bimbingan', null, $this->teacher, $this->admin);
    $this->manage->addMembers($group, [$this->student->user_id]);
    expect($this->manage->post($group, $this->student->user, 'Tetap bisa di bimbingan')->exists)->toBeTrue();

    $this->moderate->restrict($this->student->user, null, $this->admin);
    $this->actingAs($this->student->user)->get(route('lpppk'))->assertForbidden();
    $this->get(route('discussions.show', $this->research))->assertForbidden();

    $this->moderate->lift($this->student->user, $this->admin);
    $this->get(route('lpppk'))->assertOk();
    expect($this->manage->post($this->general, $this->student->user->fresh(), 'Terima kasih')->exists)->toBeTrue();

    // Only staff moderate, and staff cannot be muted.
    expect(fn () => $this->moderate->mute($this->other->user, null, null, $this->teacher->user))->toThrow(ValidationException::class)
        ->and(fn () => $this->moderate->mute(User::factory()->withRole(Role::AcademicMonitor)->create(), null, null, $this->admin))->toThrow(ValidationException::class);
});

test('staff manage channels and moderate from the pages', function () {
    $this->actingAs($this->admin)->withSession(['active_role' => Role::Admin->value]);

    Livewire::test('pages::lpppk.index')
        ->call('create')->set('name', 'Pengabdian')->call('save')->assertHasNoErrors()
        ->call('toggleClosed', $this->general->id);

    expect(Discussion::query()->where('kind', DiscussionKind::Lpppk)->where('name', 'Pengabdian')->exists())->toBeTrue()
        ->and($this->general->fresh()->closed_at)->not->toBeNull();

    $this->get(route('lpppk.moderation'))->assertOk();
    Livewire::test('pages::lpppk.moderation')
        ->set('search', $this->student->user->name)
        ->assertSee($this->student->nim)
        ->call('start', $this->student->user_id, 'restrict')
        ->set('reason', 'Spam')
        ->call('decide')
        ->assertHasNoErrors()
        ->assertSee('Spam')
        ->call('lift', $this->student->user_id)
        ->assertSee(__('Nobody is muted or without access.'));

    // A mahasantri sees no staff buttons and cannot open moderation.
    $this->actingAs($this->student->user)->withSession(['active_role' => Role::Student->value]);
    $this->get(route('lpppk'))->assertDontSeeHtml('data-test="add-channel"');
    $this->get(route('lpppk.moderation'))->assertForbidden();
});
