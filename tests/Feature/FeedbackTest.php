<?php

use App\Actions\Feedback\ManageFeedback;
use App\Enums\FeedbackStatus;
use App\Enums\Role;
use App\Models\Feedback;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->student = Student::factory()->create();
    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->superadmin = User::factory()->withRole(Role::Superadmin)->create();
});

function sendFeedback(User $user, Role $role, array $input = []): Feedback
{
    return app(ManageFeedback::class)->send($user, $role, [
        'category' => 'app',
        'subject' => 'Jadwal sulit dibaca di HP',
        'message' => 'Tabel jadwal terlalu lebar di layar HP.',
        ...$input,
    ]);
}

test('any user sends feedback from the Feedback page; Admin and Superadmin are notified', function () {
    $guardian = Guardian::factory()->create();
    $this->actingAs($guardian->user)->withSession(['active_role' => Role::Guardian->value]);

    $this->get(route('feedback'))->assertOk()->assertSeeHtml('data-test="feedback-form"');

    Livewire::test('pages::feedback.mine')
        ->set('category', 'finance')
        ->set('subject', 'Rekening transfer')
        ->set('message', 'Mohon tambahkan rekening bank syariah lain.')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('subject', '')
        ->assertSee('Rekening transfer');

    $feedback = Feedback::query()->sole();
    expect($feedback->user_id)->toBe($guardian->user_id)
        ->and($feedback->role)->toBe(Role::Guardian)
        ->and($feedback->status)->toBe(FeedbackStatus::New)
        ->and($this->admin->notifications()->count())->toBe(1)
        ->and($this->superadmin->notifications()->count())->toBe(1);

    Livewire::test('pages::feedback.mine')->call('send')->assertHasErrors(['category', 'subject', 'message']);
});

test('the sidebar shows Feedback for senders and the inbox with a count for Admin', function () {
    sendFeedback($this->student->user, Role::Student);

    expect(collect(Role::Student->navigation()[0]['items'])->pluck('route'))->toContain('feedback')
        ->and(collect(Role::Admin->navigation()[0]['items'])->pluck('route'))->toContain('feedback.inbox');

    $this->actingAs($this->admin)->withSession(['active_role' => Role::Admin->value])
        ->get(route('feedback.inbox'))->assertOk()->assertSee('Jadwal sulit dibaca di HP');

    $this->actingAs($this->student->user)->withSession(['active_role' => Role::Student->value])
        ->get(route('feedback.inbox'))->assertForbidden();
});

test('an anonymous sender is recorded but hidden from the Admin; Superadmin sees the name', function () {
    $feedback = sendFeedback($this->student->user, Role::Student, ['anonymous' => true]);
    $name = $this->student->user->name;

    expect($feedback->user_id)->toBe($this->student->user_id)
        ->and($feedback->senderLabel($this->admin))->toBe(__('Anonymous'))
        ->and($feedback->senderLabel($this->superadmin))->toContain($name);

    $this->actingAs($this->admin)->withSession(['active_role' => Role::Admin->value]);
    Livewire::withQueryParams(['open' => $feedback->id])->test('pages::feedback.inbox')
        ->assertSeeHtml('data-test="feedback-detail"')
        ->assertDontSee($name)
        // Searching names would reveal the sender: only subject and message are searched.
        ->set('search', $name)
        ->assertDontSeeHtml('data-test="feedback-'.$feedback->id.'"');

    $this->actingAs($this->superadmin)->withSession(['active_role' => Role::Superadmin->value]);
    Livewire::withQueryParams(['open' => $feedback->id])->test('pages::feedback.inbox')->assertSee($name);
});

test('the Admin replies and moves the status; the sender is notified and sees the reply', function () {
    $feedback = sendFeedback($this->student->user, Role::Student, ['anonymous' => true]);
    $this->actingAs($this->admin)->withSession(['active_role' => Role::Admin->value]);

    Livewire::withQueryParams(['open' => $feedback->id])->test('pages::feedback.inbox')
        ->set('newStatus', 'in_progress')
        ->call('saveStatus')
        ->set('reply', 'Terima kasih, tampilan jadwal sedang kami perbaiki.')
        ->set('newStatus', 'done')
        ->call('sendReply')
        ->assertHasNoErrors();

    $feedback->refresh();
    expect($feedback->status)->toBe(FeedbackStatus::Done)
        ->and($feedback->replied_by)->toBe($this->admin->id)
        ->and($this->student->user->notifications()->count())->toBe(2);

    $this->actingAs($this->student->user)->withSession(['active_role' => Role::Student->value]);
    Livewire::test('pages::feedback.mine')
        ->assertSee('Terima kasih, tampilan jadwal sedang kami perbaiki.')
        ->assertSee(FeedbackStatus::Done->label());

    // Only Admin and Superadmin handle feedback.
    expect(fn () => app(ManageFeedback::class)->reply($feedback, $this->student->user, 'x'))->toThrow(ValidationException::class);
});

test('the inbox filters by status, category and sender role, and exports to Excel', function () {
    sendFeedback($this->student->user, Role::Student, ['category' => 'academic', 'subject' => 'Jadwal ujian']);
    $teacher = User::factory()->withRole(Role::Teacher)->create();
    $done = sendFeedback($teacher, Role::Teacher, ['category' => 'facilities', 'subject' => 'Proyektor kelas']);
    app(ManageFeedback::class)->setStatus($done, $this->admin, FeedbackStatus::Done);

    $this->actingAs($this->admin)->withSession(['active_role' => Role::Admin->value]);
    Livewire::test('pages::feedback.inbox')
        ->assertSee('Jadwal ujian')
        ->assertDontSee('Proyektor kelas')
        ->set('status', 'all')
        ->assertSee('Proyektor kelas')
        ->set('role', Role::Teacher->value)
        ->assertDontSee('Jadwal ujian')
        ->set('role', '')
        ->set('category', 'academic')
        ->assertDontSee('Proyektor kelas')
        ->call('export')
        ->assertFileDownloaded('kritik-saran.xlsx');
});

test('one person sends at most ten messages a day', function () {
    foreach (range(1, ManageFeedback::DAILY_LIMIT) as $i) {
        sendFeedback($this->student->user, Role::Student);
    }

    expect(fn () => sendFeedback($this->student->user, Role::Student))->toThrow(ValidationException::class);

    $this->travel(25)->hours();
    expect(sendFeedback($this->student->user, Role::Student))->toBeInstanceOf(Feedback::class);
});
