<?php

use App\Actions\Academic\SaveAcademicYear;
use App\Actions\Enrollment\ManageClassEnrollments;
use App\Enums\Role;
use App\Enums\SemesterStatus;
use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\ClassEnrollment;
use App\Models\ClassGroup;
use App\Models\MaintenanceSession;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Support\MaintenanceMode;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->superadmin = User::factory()->withRole(Role::Superadmin)->create();
    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->closed = Semester::factory()->closed()->create();
    $this->class = ClassGroup::factory()->create(['level' => 1]);
    $this->student = Student::factory()->create();
});

function actingInRole(User $user, Role $role): void
{
    test()->actingAs($user);
    session(['active_role' => $role->value]);
}

test('superadmin turns maintenance mode on with a reason and off again', function () {
    $this->actingAs($this->superadmin)->get(route('superadmin.maintenance'))->assertOk()->assertSee(__('Off'));

    Livewire::test('pages::superadmin.maintenance')
        ->set('reason', '')
        ->call('turnOn')
        ->assertHasErrors('reason')
        ->set('reason', 'Input nilai 2025/2026 dari arsip')
        ->call('turnOn')
        ->assertHasNoErrors();

    $session = MaintenanceSession::query()->sole();
    expect(MaintenanceMode::isOn())->toBeTrue()
        ->and($session->starter->is($this->superadmin))->toBeTrue()
        ->and($session->ended_at)->toBeNull();

    expect(fn () => MaintenanceMode::turnOn($this->superadmin, 'Lagi'))->toThrow(ValidationException::class, 'already on');

    Livewire::test('pages::superadmin.maintenance')->call('turnOff');

    expect(MaintenanceMode::isOn())->toBeFalse()
        ->and($session->fresh()->ended_by)->toBe($this->superadmin->id);
});

test('staff see the maintenance banner while it is on; muhadhir and mahasantri do not', function () {
    MaintenanceMode::turnOn($this->superadmin, 'Input nilai 2025/2026 dari arsip');

    $this->actingAs($this->admin)->get(route('admin.academic-years'))
        ->assertOk()
        ->assertSee('Maintenance mode:')
        ->assertSee('previous-year data entry is open')
        ->assertSee('Turned on by '.$this->superadmin->name)
        ->assertSee('Input nilai 2025/2026 dari arsip')
        ->assertDontSee('>Manage</a>', false);

    $this->actingAs($this->superadmin)->get(route('superadmin.settings'))->assertSee('>Manage</a>', false);
    $this->actingAs(Teacher::factory()->create()->user)->get(route('ustadz.dashboard'))->assertDontSee('Maintenance mode:');
    $this->actingAs($this->student->user)->get(route('mahasantri.dashboard'))->assertDontSee('Maintenance mode:');

    MaintenanceMode::turnOff($this->superadmin);
    $this->actingAs($this->admin)->get(route('admin.academic-years'))->assertDontSee('Maintenance mode:');
});

test('closed semesters open for admin and finance only while the mode is on', function () {
    actingInRole($this->admin, Role::Admin);
    expect($this->closed->isReadOnly())->toBeTrue()
        ->and(fn () => app(ManageClassEnrollments::class)->enroll($this->closed, $this->class, [$this->student->id]))
        ->toThrow(ValidationException::class, 'read-only');

    MaintenanceMode::turnOn($this->superadmin, 'Input data kelas 2025/2026');

    expect($this->closed->isReadOnly())->toBeFalse();
    app(ManageClassEnrollments::class)->enroll($this->closed, $this->class, [$this->student->id]);
    expect(ClassEnrollment::query()->where('semester_id', $this->closed->id)->count())->toBe(1);

    actingInRole(User::factory()->withRole(Role::AdminPayment)->create(), Role::AdminPayment);
    expect($this->closed->isReadOnly())->toBeFalse();

    // Muhadhir, the academic monitor and mahasantri stay locked out.
    foreach ([[Teacher::factory()->create()->user, Role::Teacher], [User::factory()->withRole(Role::AcademicMonitor)->create(), Role::AcademicMonitor]] as [$user, $role]) {
        actingInRole($user, $role);
        expect($this->closed->isReadOnly())->toBeTrue();
    }
});

test('changes made while the mode is on are tagged with the session', function () {
    actingInRole($this->admin, Role::Admin);
    $before = AcademicYear::factory()->create();
    $session = MaintenanceMode::turnOn($this->superadmin, 'Input data kelas 2025/2026');

    app(ManageClassEnrollments::class)->enroll($this->closed, $this->class, [$this->student->id]);

    MaintenanceMode::turnOff($this->superadmin);
    $after = AcademicYear::factory()->create();

    $log = fn ($model) => AuditLog::query()->where('auditable_type', $model->getMorphClass())->where('auditable_id', (string) $model->getKey())->where('event', 'created')->sole();
    expect($log($before)->maintenance_session_id)->toBeNull()
        ->and($log(ClassEnrollment::query()->sole())->maintenance_session_id)->toBe($session->id)
        ->and($log($after)->maintenance_session_id)->toBeNull()
        ->and($session->changes()->count())->toBeGreaterThan(0);

    $this->actingAs($this->superadmin)->get(route('superadmin.maintenance'))
        ->assertOk()
        ->assertSee('ClassEnrollment #');
});

test('a past academic year is created with closed semesters, only in maintenance mode', function () {
    actingInRole($this->admin, Role::Admin);
    $input = ['name' => '2024/2025', 'starts_on' => '2024-07-01', 'ends_on' => '2025-06-30', 'past' => true];

    expect(fn () => app(SaveAcademicYear::class)->handle(null, $input))
        ->toThrow(ValidationException::class, 'maintenance mode');

    MaintenanceMode::turnOn($this->superadmin, 'Input data 2024/2025');

    $year = app(SaveAcademicYear::class)->handle(null, $input);
    expect($year->semesters->pluck('status')->unique()->all())->toBe([SemesterStatus::Closed]);

    // The option shows on the page only while the mode is on.
    $this->get(route('admin.academic-years'));
    Livewire::test('pages::admin.academic-years')->call('create')->assertSee(__('Previous academic year (data entry)'));
});

test('only superadmin opens the maintenance page', function (Role $role) {
    $this->actingAs(User::factory()->withRole($role)->create())
        ->get(route('superadmin.maintenance'))
        ->assertForbidden();
})->with([Role::Admin, Role::AdminPayment]);
