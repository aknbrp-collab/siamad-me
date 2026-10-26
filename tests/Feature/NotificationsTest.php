<?php

use App\Actions\Enrollment\ManageClassEnrollments;
use App\Actions\Enrollment\ManageCourseOfferings;
use App\Actions\Finance\ConfirmTransfer;
use App\Actions\Finance\GenerateBills;
use App\Actions\Finance\RecordPayment;
use App\Actions\Finance\ReviewPayments;
use App\Actions\Lms\ManageAssignments;
use App\Actions\Lms\ManageForum;
use App\Actions\Lms\ManageQuizzes;
use App\Actions\Supervision\ManageSupervision;
use App\Enums\Role;
use App\Models\Bill;
use App\Models\ClassGroup;
use App\Models\CourseEnrollment;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Guardian;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Notifications\SiakadNotice;
use App\Support\Forum\SupervisionForum;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->semester = Semester::factory()->active()->create();
    $class = ClassGroup::factory()->create();
    $this->teacher = Teacher::factory()->create();
    $this->offering = app(ManageCourseOfferings::class)->save($this->semester, null, [
        'subject_id' => Subject::factory()->create(['name' => 'Fiqh Ibadah'])->id, 'class_group_id' => $class->id, 'teacher_id' => $this->teacher->id,
    ]);
    [$this->faqih, $this->rifqi] = Student::factory()->count(2)->create()->all();
    app(ManageClassEnrollments::class)->enroll($this->semester, $class, [$this->faqih->id, $this->rifqi->id]);
});

/**
 * @return list<array<string, mixed>>
 */
function noticesOf(User $user): array
{
    return $user->fresh()->notifications()->get()->map(fn ($notification) => [...$notification->data, 'read' => $notification->read_at !== null])->values()->all();
}

test('a new forum topic notifies the other participants, each with their own link', function () {
    $thread = app(ManageForum::class)->startThread($this->offering, $this->faqih->user, 'Najis mughallazhah', 'Bagaimana cara mensucikannya?');

    expect(noticesOf($this->faqih->user))->toBe([]);

    $forRifqi = noticesOf($this->rifqi->user);
    $forTeacher = noticesOf($this->teacher->user);

    expect($forRifqi)->toHaveCount(1)
        ->and($forRifqi[0]['title'])->toContain('Najis mughallazhah')
        ->and($forRifqi[0]['url'])->toBe(route('mahasantri.class', [$this->offering, 'tab' => 'forum', 'thread' => $thread->id], false))
        ->and($forTeacher[0]['url'])->toBe(route('ustadz.offerings.forum', [$this->offering, 'thread' => $thread->id], false));
});

test('replies in one thread share a single unread notification', function () {
    $forum = app(ManageForum::class);
    $thread = $forum->startThread($this->offering, $this->teacher->user, 'Pengumuman', 'Kuis minggu depan.');

    $forum->reply($thread, $this->faqih->user, 'Baik, ustadz.');
    $forum->reply($thread, $this->faqih->user, 'Bab apa saja?');
    $forum->reply($thread, $this->faqih->user, 'Terima kasih.');

    $replies = collect(noticesOf($this->rifqi->user))->where('group', 'forum-thread:'.$thread->id)->values();
    expect($replies)->toHaveCount(1)->and($replies[0]['count'])->toBe(3)->and($replies[0]['body'])->toContain('3');

    // Once read, the next reply starts a new notification.
    $this->rifqi->user->unreadNotifications()->update(['read_at' => now()]);
    $forum->reply($thread, $this->faqih->user, 'Satu lagi.');

    expect(collect(noticesOf($this->rifqi->user))->where('group', 'forum-thread:'.$thread->id))->toHaveCount(2);
});

test('the supervision group forum notifies the supervisor and supervisees', function () {
    app(ManageSupervision::class)->assign($this->teacher, [$this->faqih->id, $this->rifqi->id], User::factory()->withRole(Role::Admin)->create());

    app(ManageForum::class)->startThread(new SupervisionForum($this->teacher), $this->faqih->user, 'Rencana studi', 'Target saya juz 29.');

    expect(noticesOf($this->rifqi->user)[0]['url'])->toStartWith(route('mahasantri.supervision', [], false))
        ->and(noticesOf($this->teacher->user)[0]['url'])->toStartWith(route('ustadz.supervision.forum', [], false));
});

test('new assignments and published quizzes notify the class; edits and drafts do not', function () {
    $assignments = app(ManageAssignments::class);
    $assignment = $assignments->save($this->offering, null, ['title' => 'Makalah tayammum', 'due_at' => now()->addWeek(), 'max_score' => 100]);
    $assignments->save($this->offering, $assignment, ['title' => 'Makalah tayammum (revisi)', 'due_at' => now()->addWeek(), 'max_score' => 100]);

    $quizzes = app(ManageQuizzes::class);
    $quiz = $quizzes->save($this->offering, null, ['title' => 'Kuis Thaharah', 'opens_at' => now(), 'closes_at' => now()->addWeek(), 'max_attempts' => 1]);

    expect(noticesOf($this->faqih->user))->toHaveCount(1);

    $quizzes->saveQuestion($quiz, null, ['type' => 'true_false', 'body' => 'Air mutlak itu suci?', 'points' => 1, 'options' => [
        ['body' => 'Benar', 'is_correct' => true], ['body' => 'Salah', 'is_correct' => false],
    ]]);
    $quizzes->publish($quiz);

    $titles = collect(noticesOf($this->faqih->user))->pluck('title');
    expect($titles)->toHaveCount(2)
        ->and($titles->implode(' '))->toContain('Makalah tayammum')->toContain('Kuis Thaharah')
        ->and(noticesOf($this->teacher->user))->toBe([]);
});

test('transfer confirmations notify Admin Keuangan, and the review notifies the payer', function () {
    $fee = FeeType::factory()->create();
    FeeRate::create(['fee_type_id' => $fee->id, 'academic_year_id' => $this->semester->academic_year_id, 'amount' => 2_000_000]);
    app(GenerateBills::class)->handle($this->semester);
    $bill = Bill::query()->where('student_id', $this->faqih->id)->sole();
    $cashier = User::factory()->withRole(Role::AdminPayment)->create();

    $payment = app(ConfirmTransfer::class)->handle($bill, $this->faqih->user, [
        'amount' => 1_000_000, 'paid_on' => now()->toDateString(), 'reference' => 'BSI a.n. Faqih',
        'proof' => UploadedFile::fake()->image('bukti.jpg'),
    ]);

    expect(noticesOf($cashier)[0]['url'])->toBe(route('finance.transfers', [], false))
        ->and(noticesOf($this->faqih->user))->toBe([]);

    app(ReviewPayments::class)->rejectTransfer($payment, $cashier, 'Tidak ada di rekening koran');

    $forPayer = noticesOf($this->faqih->user);
    expect($forPayer)->toHaveCount(1)
        ->and($forPayer[0]['body'])->toContain('Tidak ada di rekening koran')
        ->and($forPayer[0]['url'])->toBe(route('mahasantri.bills', [], false));

    // Staff-recorded transfers have no payer to tell.
    $staffTransfer = app(RecordPayment::class)->handle($bill, $cashier, ['amount' => 1_000_000, 'method' => 'transfer', 'paid_on' => now()->toDateString(), 'reference' => 'BSI 2']);
    app(ReviewPayments::class)->verifyTransfer($staffTransfer, User::factory()->withRole(Role::Admin)->create());

    expect(noticesOf($this->faqih->user))->toHaveCount(1);
});

test('deadline reminders go once, only to those who have not handed in', function () {
    $assignments = app(ManageAssignments::class);
    $assignment = $assignments->save($this->offering, null, ['title' => 'Ringkasan bab air', 'due_at' => now()->addHours(12), 'max_score' => 100]);
    $assignments->submit($assignment, CourseEnrollment::query()->where('student_id', $this->faqih->id)->sole(), ['text' => 'Sudah.']);
    $this->faqih->user->notifications()->delete();
    $this->rifqi->user->notifications()->delete();

    $this->artisan('siakad:send-reminders')->assertSuccessful();
    $this->artisan('siakad:send-reminders')->assertSuccessful();

    $forRifqi = noticesOf($this->rifqi->user);
    expect($forRifqi)->toHaveCount(1)
        ->and($forRifqi[0]['title'])->toContain('Ringkasan bab air')
        ->and(noticesOf($this->faqih->user))->toBe([]);
});

test('bill reminders go to the mahasantri and their wali, 7 days before and on the day', function () {
    $fee = FeeType::factory()->create();
    FeeRate::create(['fee_type_id' => $fee->id, 'academic_year_id' => $this->semester->academic_year_id, 'amount' => 1_500_000]);
    app(GenerateBills::class)->handle($this->semester, today()->addDays(7)->toDateString());
    $guardian = Guardian::factory()->create();
    $guardian->students()->attach($this->faqih);

    $this->artisan('siakad:send-reminders')->assertSuccessful();
    $this->artisan('siakad:send-reminders')->assertSuccessful();

    expect(noticesOf($this->faqih->user))->toHaveCount(1)
        ->and(noticesOf($guardian->user))->toHaveCount(1)
        ->and(noticesOf($guardian->user)[0]['url'])->toBe(route('wali.bills', [], false));

    // On the due date: one more reminder each.
    $this->travelTo(today()->addDays(7)->setTime(9, 0));
    $this->artisan('siakad:send-reminders')->assertSuccessful();

    expect(noticesOf($this->faqih->user))->toHaveCount(2);
});

test('deactivated accounts get no notifications', function () {
    $this->rifqi->user->update(['is_active' => false]);

    app(ManageForum::class)->startThread($this->offering, $this->faqih->user, 'Tanya', 'Isi.');

    expect(noticesOf($this->rifqi->user))->toBe([]);
});

test('the bell shows the unread count; opening a notification marks it read', function () {
    app(ManageForum::class)->startThread($this->offering, $this->faqih->user, 'Tanya bab najis', 'Isi.');
    $notification = $this->rifqi->user->notifications()->sole();

    $this->actingAs($this->rifqi->user)
        ->get(route('notifications'))
        ->assertOk()
        ->assertSee('Tanya bab najis')
        ->assertSeeHtml('data-test="notifications-link"');

    $this->get(route('notifications.open', $notification->id))
        ->assertRedirect($notification->data['url']);

    expect($notification->fresh()->read_at)->not->toBeNull();

    // Someone else's notification cannot be opened.
    $this->actingAs($this->faqih->user)->get(route('notifications.open', $notification->id))->assertNotFound();
});

test('links are stored as paths, so reminders made on the command line work on any host', function () {
    URL::forceRootUrl('http://wrong-host:8000');

    app(ManageForum::class)->startThread($this->offering, $this->faqih->user, 'Tanya', 'Isi.');
    $notification = $this->rifqi->user->notifications()->sole();

    expect($notification->data['url'])->toStartWith('/mahasantri/classes/');

    URL::forceRootUrl(null);
    $this->actingAs($this->rifqi->user)
        ->get(route('notifications.open', $notification->id))
        ->assertRedirect($notification->data['url']);
});

test('a notification never redirects outside the application', function () {
    $this->rifqi->user->notify(new SiakadNotice('x', 'T', 'B', '//evil.example/phish', 'bell'));

    $this->actingAs($this->rifqi->user)
        ->get(route('notifications.open', $this->rifqi->user->notifications()->sole()->id))
        ->assertRedirect(route('notifications'));
});

test('mark all as read', function () {
    $forum = app(ManageForum::class);
    $forum->startThread($this->offering, $this->faqih->user, 'Satu', 'Isi.');
    $forum->startThread($this->offering, $this->faqih->user, 'Dua', 'Isi.');

    $this->actingAs($this->rifqi->user)->get(route('notifications'))->assertOk();
    Livewire::test('pages::notifications')->call('markAllRead');

    expect($this->rifqi->user->unreadNotifications()->count())->toBe(0);
});
