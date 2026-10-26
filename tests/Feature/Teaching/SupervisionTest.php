<?php

use App\Actions\Lms\ManageForum;
use App\Actions\Supervision\ManageSupervision;
use App\Enums\Role;
use App\Enums\StudentStatus;
use App\Enums\TeacherStatus;
use App\Models\ForumThread;
use App\Models\Student;
use App\Models\Supervision;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Forum\SupervisionForum;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->ahmad = Teacher::factory()->create();
    $this->hasan = Teacher::factory()->create();
    [$this->faqih, $this->rifqi] = Student::factory()->count(2)->create()->all();
    $this->manage = app(ManageSupervision::class);
});

test('the admin assigns a pembimbing akademik from the page', function () {
    $this->actingAs($this->admin)->get(route('admin.supervision'))->assertOk()->assertSee($this->ahmad->user->name);

    Livewire::test('pages::admin.supervision')
        ->call('choose', $this->ahmad->id)
        ->assertSee($this->faqih->user->name)
        ->call('assign')
        ->assertHasErrors('students')
        ->set('selected', [$this->faqih->id, $this->rifqi->id])
        ->call('assign')
        ->assertHasNoErrors()
        // Now listed as supervised, and no longer offered as a candidate.
        ->assertSeeHtml('wire:key="supervisee-')
        ->assertDontSeeHtml('wire:key="candidate-'.$this->faqih->id.'"');

    expect(Supervision::query()->current()->pluck('teacher_id', 'student_id')->all())
        ->toBe([$this->faqih->id => $this->ahmad->id, $this->rifqi->id => $this->ahmad->id]);
});

test('changing the supervisor closes the old supervision instead of overwriting it', function () {
    $this->manage->assign($this->ahmad, [$this->faqih->id], $this->admin);
    expect($this->manage->assign($this->ahmad, [$this->faqih->id], $this->admin))->toBe(0);

    $this->manage->assign($this->hasan, [$this->faqih->id], $this->admin);

    $history = $this->faqih->supervisions()->get();
    expect($history)->toHaveCount(2)
        ->and($history->firstWhere('teacher_id', $this->ahmad->id)->ended_on)->not->toBeNull()
        ->and($this->faqih->currentSupervision()->sole()->teacher_id)->toBe($this->hasan->id);
});

test('only active muhadhir supervise, and only active mahasantri are assigned', function () {
    $inactive = Teacher::factory()->create(['status' => TeacherStatus::Inactive]);
    expect(fn () => $this->manage->assign($inactive, [$this->faqih->id], $this->admin))
        ->toThrow(ValidationException::class);

    $graduated = Student::factory()->create(['status' => StudentStatus::Graduated, 'graduated_at' => '2026-06-01']);
    expect($this->manage->assign($this->ahmad, [$graduated->id, $this->faqih->id], $this->admin))->toBe(1);
});

test('the admin can end a supervision; it stays in the history', function () {
    $this->manage->assign($this->ahmad, [$this->faqih->id], $this->admin);
    $supervision = Supervision::query()->sole();

    $this->actingAs($this->admin)->get(route('admin.supervision'));
    Livewire::test('pages::admin.supervision')
        ->call('choose', $this->ahmad->id)
        ->call('end', $supervision->id);

    expect($supervision->fresh()->ended_on)->not->toBeNull()
        ->and($this->faqih->currentSupervision()->exists())->toBeFalse();
});

test('a muhadhir sees the progress of their supervisees, and only theirs', function () {
    $this->manage->assign($this->ahmad, [$this->faqih->id], $this->admin);

    $this->actingAs($this->ahmad->user)
        ->get(route('ustadz.supervision'))
        ->assertOk()
        ->assertSee($this->faqih->user->name)
        ->assertDontSee($this->rifqi->user->name);

    $this->get(route('ustadz.supervision.student', $this->faqih))->assertOk()->assertSee(__('Transcript'));
    $this->get(route('ustadz.supervision.student', $this->rifqi))->assertForbidden();

    $this->actingAs($this->hasan->user)->get(route('ustadz.supervision.student', $this->faqih))->assertForbidden();
});

test('the group forum is for the supervisor and their current supervisees', function () {
    $this->manage->assign($this->ahmad, [$this->faqih->id], $this->admin);
    $forum = app(ManageForum::class);
    $group = new SupervisionForum($this->ahmad);

    $thread = $forum->startThread($group, $this->faqih->user, 'Rencana studi', 'Target hafalan saya juz 29.');
    $forum->reply($thread, $this->ahmad->user, 'Bagus, lanjutkan.');
    $forum->togglePin($thread, $this->ahmad->user);

    expect($thread->fresh())->supervisor_id->toBe($this->ahmad->id)->course_offering_id->toBeNull()->is_pinned->toBeTrue()
        ->and(fn () => $forum->togglePin($thread, $this->faqih->user))->toThrow(ValidationException::class);

    // Not in the group: another mahasantri, another muhadhir.
    expect(fn () => $forum->reply($thread, $this->rifqi->user, 'Ikut ya'))->toThrow(HttpException::class)
        ->and(fn () => $forum->reply($thread, $this->hasan->user, 'Ikut ya'))->toThrow(HttpException::class);

    // After moving to another supervisor, the old group forum is closed to them.
    $this->manage->assign($this->hasan, [$this->faqih->id], $this->admin);
    expect(fn () => $forum->reply($thread, $this->faqih->user, 'Masih bisa?'))->toThrow(HttpException::class);
});

test('the group forum pages work for both sides', function () {
    $this->manage->assign($this->ahmad, [$this->faqih->id], $this->admin);

    $this->actingAs($this->ahmad->user)->get(route('ustadz.supervision.forum'))->assertOk();
    Livewire::test('pages::shared.forum', ['supervisor' => $this->ahmad])
        ->set('newTitle', 'Pengumuman')
        ->set('newBody', 'Bimbingan hari Kamis ba\'da ashar.')
        ->call('startThread')
        ->assertHasNoErrors();

    $this->actingAs($this->faqih->user)
        ->get(route('mahasantri.supervision'))
        ->assertOk()
        ->assertSee($this->ahmad->displayName())
        ->assertSee('Pengumuman');

    expect(ForumThread::query()->sole()->supervisor_id)->toBe($this->ahmad->id);

    $this->actingAs($this->rifqi->user)
        ->get(route('mahasantri.supervision'))
        ->assertOk()
        ->assertSee(__('No pembimbing akademik has been assigned to you yet. Please contact the academic office.'));

    $this->actingAs($this->rifqi->user);
    Livewire::test('pages::shared.forum', ['supervisor' => $this->ahmad])->assertForbidden();
});

test('menus: admin, muhadhir and mahasantri each reach their supervision page', function (Role $role, string $route) {
    expect(collect($role->navigation())->flatMap(fn ($group) => $group['items'])->pluck('route'))->toContain($route);
})->with([
    [Role::Admin, 'admin.supervision'],
    [Role::Superadmin, 'admin.supervision'],
    [Role::Teacher, 'ustadz.supervision'],
    [Role::Student, 'mahasantri.supervision'],
]);
