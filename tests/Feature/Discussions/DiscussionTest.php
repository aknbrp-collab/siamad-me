<?php

use App\Actions\Discussions\ManageDiscussions;
use App\Enums\DiscussionKind;
use App\Enums\Role;
use App\Enums\StudentStatus;
use App\Models\DiscussionFile;
use App\Models\DiscussionMember;
use App\Models\DiscussionMessage;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->manage = app(ManageDiscussions::class);
    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->lead = Teacher::factory()->create();
    $this->ali = Student::factory()->create();
    $this->budi = Student::factory()->create();
    $this->outsider = Student::factory()->create();

    $this->group = $this->manage->create(DiscussionKind::Bimbingan, 'Bimbingan Skripsi A', 'Penulisan skripsi fiqh', $this->lead, $this->admin);
    $this->manage->addMembers($this->group, [$this->ali->user_id, $this->budi->user_id]);
    $this->channel = $this->manage->create(DiscussionKind::Lpppk, 'Penelitian', null, null, $this->admin);
});

test('a bimbingan group is for its lead and members; LPPPK for every active mahasantri and muhadhir', function () {
    $this->actingAs($this->ali->user)->get(route('discussions.show', $this->group))->assertOk()->assertSee('Bimbingan Skripsi A');
    $this->actingAs($this->outsider->user)->get(route('discussions.show', $this->group))->assertForbidden();
    $this->actingAs($this->admin)->get(route('discussions.show', $this->group))->assertOk();

    // LPPPK: anyone active joins on their first visit.
    $this->actingAs($this->outsider->user)->get(route('discussions.show', $this->channel))->assertOk();
    expect(DiscussionMember::query()->where('discussion_id', $this->channel->id)->where('user_id', $this->outsider->user_id)->exists())->toBeTrue();

    $this->outsider->update(['status' => StudentStatus::Graduated]);
    $this->actingAs($this->outsider->user->fresh())->get(route('discussions.show', $this->channel))->assertForbidden();
    $this->actingAs(User::factory()->withRole(Role::AdminPayment)->create())->get(route('discussions.show', $this->channel))->assertForbidden();
});

test('members post messages, replies and files; others are told once per room', function () {
    $first = $this->manage->post($this->group, $this->ali->user, 'Assalamualaikum, ini draf bab 1.', [UploadedFile::fake()->create('bab1.docx', 300)]);
    $reply = $this->manage->post($this->group, $this->lead->user, 'Waalaikumsalam, saya baca dulu.', [], $first->id);
    $this->manage->post($this->group, $this->ali->user, 'Terima kasih ustadz.');

    expect($reply->reply_to_id)->toBe($first->id)
        ->and(DiscussionFile::query()->sole()->original_name)->toBe('bab1.docx')
        // Budi was told he joined, then one notification for the room counts the messages.
        ->and($this->budi->user->unreadNotifications()->count())->toBe(2)
        ->and($this->manage->unread($this->budi->user))->toBe([$this->group->id => 3])
        // Posting marks the room read for the poster.
        ->and($this->manage->unread($this->ali->user))->toBe([]);

    expect(fn () => $this->manage->post($this->group, $this->ali->user, '  '))->toThrow(ValidationException::class)
        ->and(fn () => $this->manage->post($this->group, $this->outsider->user, 'Halo'))->toThrow(ValidationException::class);

    $file = DiscussionFile::query()->sole();
    $this->actingAs($this->budi->user)->get(route('files.show', ['discussion-file', $file->id]))->assertOk();
    $this->actingAs($this->outsider->user)->get(route('files.show', ['discussion-file', $file->id]))->assertForbidden();
});

test('moderators hide messages, mute members and lock the room', function () {
    $message = $this->manage->post($this->group, $this->ali->user, 'Pesan yang tidak pantas');

    expect(fn () => $this->manage->hide($message, $this->budi->user))->toThrow(ValidationException::class);
    $this->manage->hide($message, $this->lead->user);
    expect($message->fresh()->isHidden())->toBeTrue()->and($message->fresh()->body)->toBe('Pesan yang tidak pantas');

    $ali = DiscussionMember::query()->where('user_id', $this->ali->user_id)->sole();
    expect(fn () => $this->manage->mute($ali, null, $this->budi->user))->toThrow(ValidationException::class);
    $this->manage->mute($ali, now()->addDay(), $this->lead->user);
    expect(fn () => $this->manage->post($this->group, $this->ali->user, 'Halo'))->toThrow(ValidationException::class, 'muted');

    $this->travel(2)->days();
    expect($this->manage->post($this->group, $this->ali->user, 'Sudah bisa lagi')->exists)->toBeTrue();

    $this->manage->setLocked($this->group, true, $this->admin);
    expect(fn () => $this->manage->post($this->group, $this->budi->user, 'Halo'))->toThrow(ValidationException::class, 'locked')
        ->and($this->manage->post($this->group, $this->lead->user, 'Diskusi ditutup sementara')->exists)->toBeTrue();

    $this->manage->setClosed($this->group, true, $this->admin);
    expect(fn () => $this->manage->post($this->group, $this->lead->user, 'Halo'))->toThrow(ValidationException::class, 'closed');
});

test('notes are written by members and changed by their author or a moderator', function () {
    $note = $this->manage->saveNote($this->group, $this->ali->user, null, 'Kerangka', "Bab 1\nBab 2");

    expect(fn () => $this->manage->saveNote($this->group, $this->budi->user, $note, 'Ubah', 'x'))->toThrow(ValidationException::class);
    $this->manage->saveNote($this->group, $this->lead->user, $note, 'Kerangka final', 'Bab 1-3');
    expect($note->fresh())->title->toBe('Kerangka final')->updated_by->toBe($this->lead->user_id);

    $this->manage->hideNote($note, $this->ali->user);
    expect($note->fresh()->hidden_at)->not->toBeNull();
});

test('the room page sends, refreshes, replies and shows files and notes', function () {
    $this->manage->post($this->group, $this->lead->user, 'Silakan kirim draf.');
    $this->actingAs($this->ali->user);

    $page = Livewire::test('pages::discussion', ['discussion' => $this->group])
        ->assertSee('Silakan kirim draf.')
        ->set('body', 'Ini drafnya, ustadz.')
        ->set('uploads', [UploadedFile::fake()->create('draf.pdf', 200, 'application/pdf')])
        ->call('send')
        ->assertHasNoErrors()
        ->assertSee('Ini drafnya, ustadz.')
        ->assertSee('draf.pdf');

    // A message from someone else shows up on the next refresh.
    $this->manage->post($this->group, $this->budi->user, 'Saya juga sudah.');
    $page->call('refresh')->assertSee('Saya juga sudah.');

    expect($this->manage->unread($this->ali->user))->toBe([]);

    $page->set('tab', 'files')->assertSee('draf.pdf')
        ->set('tab', 'notes')->call('editNote')->set('noteTitle', 'Jadwal')->set('noteBody', 'Setor bab 2 pekan depan')->call('saveNote')
        ->assertHasNoErrors()->assertSee('Setor bab 2 pekan depan');
});

test('the lead mutes from the members tab; a muted member sees why they cannot post', function () {
    $ali = DiscussionMember::query()->where('user_id', $this->ali->user_id)->sole();

    $this->actingAs($this->lead->user);
    Livewire::test('pages::discussion', ['discussion' => $this->group])
        ->set('tab', 'members')
        ->call('startMute', $ali->id)
        ->call('mute')
        ->assertHasNoErrors();

    $this->actingAs($this->ali->user);
    Livewire::test('pages::discussion', ['discussion' => $this->group])->assertSeeHtml('data-test="cannot-post"');
});

test('refreshing the chat does not grow with the number of messages', function () {
    foreach (range(1, 5) as $i) {
        $this->manage->post($this->group, $this->ali->user, "Pesan {$i}", [UploadedFile::fake()->create("f{$i}.pdf", 10)]);
    }

    $this->actingAs($this->budi->user);
    $page = Livewire::test('pages::discussion', ['discussion' => $this->group]);

    // Measured on a refresh with nothing new (a new message also moves the read marker).
    $page->call('refresh');
    DB::enableQueryLog();
    $page->call('refresh');
    $few = count(DB::getQueryLog());

    foreach (range(6, 25) as $i) {
        $this->manage->post($this->group, $this->ali->user, "Pesan {$i}", [UploadedFile::fake()->create("f{$i}.pdf", 10)]);
    }

    $page->call('refresh');
    DB::flushQueryLog();
    $page->call('refresh');

    expect(count(DB::getQueryLog()))->toBe($few)
        ->and(DiscussionMessage::query()->count())->toBe(25);
});
