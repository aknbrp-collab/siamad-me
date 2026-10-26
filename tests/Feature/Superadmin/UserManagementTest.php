<?php

use App\Enums\Role;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

test('superadmin can create a staff account with a temporary password', function () {
    $this->actingAs(User::factory()->withRole(Role::Superadmin)->create());
    $this->get(route('superadmin.users.create'))->assertOk();

    $component = Livewire::test('pages::superadmin.users.form')
        ->set('username', 'Bendahara')
        ->set('name', 'Ustadzah Fatimah')
        ->call('save')
        ->assertHasErrors('roles')
        ->set('roles', [Role::AdminPayment->value, Role::AcademicMonitor->value])
        ->call('save')
        ->assertHasNoErrors();

    $user = User::query()->where('username', 'bendahara')->sole();

    expect($user->roleList())->toBe([Role::AdminPayment, Role::AcademicMonitor])
        ->and($user->must_change_password)->toBeTrue()
        ->and($component->get('credentialUsername'))->toBe('bendahara')
        ->and(Hash::check($component->get('credentialPassword'), $user->password))->toBeTrue();

    $this->get(route('superadmin.users.index'))->assertOk()->assertSee('Ustadzah Fatimah')->assertSee(__('Admin Payment'));
});

test('staff login IDs are validated and shared with NIM / NIDM', function () {
    $this->actingAs(User::factory()->withRole(Role::Superadmin)->create());
    $teacher = Teacher::factory()->create();

    Livewire::test('pages::superadmin.users.form')
        ->set('username', $teacher->nidm)
        ->set('name', 'Dup')
        ->set('roles', [Role::Admin->value])
        ->call('save')
        ->assertHasErrors('username')
        ->set('username', 'has space')
        ->call('save')
        ->assertHasErrors('username')
        ->set('roles', ['teacher'])
        ->set('username', 'staf.tu')
        ->call('save')
        ->assertHasErrors('roles.0');
});

test('an ustadz can be given the academic monitor role and lose it again', function () {
    $this->actingAs(User::factory()->withRole(Role::Superadmin)->create());
    $teacher = Teacher::factory()->create();

    $this->get(route('superadmin.users.edit', $teacher->user))->assertOk()->assertSee(__('Ustadz profile'));

    Livewire::test('pages::superadmin.users.form', ['user' => $teacher->user])
        ->set('roles', [Role::AcademicMonitor->value])
        ->call('saveRoles')
        ->assertHasNoErrors();

    expect($teacher->user->fresh()->roleList())->toBe([Role::AcademicMonitor, Role::Teacher]);

    // The profile role stays when every staff role is removed.
    Livewire::test('pages::superadmin.users.form', ['user' => $teacher->user])
        ->set('roles', [])
        ->call('saveRoles')
        ->assertHasNoErrors();

    expect($teacher->user->fresh()->roleList())->toBe([Role::Teacher]);
});

test('people with a profile are not edited here', function () {
    $this->actingAs(User::factory()->withRole(Role::Superadmin)->create());
    $teacher = Teacher::factory()->create();

    Livewire::test('pages::superadmin.users.form', ['user' => $teacher->user])
        ->set('name', 'Changed')
        ->call('save')
        ->assertHasErrors('username');

    expect($teacher->user->fresh()->name)->not->toBe('Changed');
});

test('mahasantri and wali santri can not hold staff roles', function () {
    $this->actingAs(User::factory()->withRole(Role::Superadmin)->create());
    $student = Student::factory()->create();
    $guardian = Guardian::factory()->create();

    foreach ([$student->user, $guardian->user] as $user) {
        Livewire::test('pages::superadmin.users.form', ['user' => $user])
            ->set('roles', [Role::Admin->value])
            ->call('saveRoles')
            ->assertHasErrors('roles');

        expect($user->fresh()->hasRole(Role::Admin))->toBeFalse();
    }
});

test('a staff account keeps at least one role', function () {
    $this->actingAs(User::factory()->withRole(Role::Superadmin)->create());
    $admin = User::factory()->withRole(Role::Admin)->create();

    Livewire::test('pages::superadmin.users.form', ['user' => $admin])
        ->set('roles', [])
        ->call('saveRoles')
        ->assertHasErrors('roles');

    expect($admin->fresh()->roleList())->toBe([Role::Admin]);
});

test('superadmin can edit a staff account and can not lock themself out', function () {
    $this->actingAs($me = User::factory()->withRole(Role::Superadmin)->create());
    $admin = User::factory()->withRole(Role::Admin)->create();
    $this->get(route('superadmin.users.edit', $admin))->assertOk()->assertSee(__('Reset password'));

    Livewire::test('pages::superadmin.users.form', ['user' => $admin])
        ->set('username', 'tata.usaha')
        ->set('name', 'Tata Usaha')
        ->call('save')
        ->assertHasNoErrors()
        ->call('resetPassword')
        ->assertSet('credentialUsername', 'tata.usaha')
        ->call('toggleActive');

    expect($admin->fresh())->username->toBe('tata.usaha')->must_change_password->toBeTrue()->is_active->toBeFalse();

    Livewire::test('pages::superadmin.users.form', ['user' => $me])
        ->set('roles', [Role::Admin->value])
        ->call('saveRoles')
        ->assertHasErrors('roles')
        ->call('toggleActive')
        ->assertForbidden();

    expect($me->fresh()->roleList())->toBe([Role::Superadmin])->and($me->fresh()->is_active)->toBeTrue();
});

test('a removed role stops working on the next request', function () {
    $admin = User::factory()->withRole(Role::Admin, Role::AdminPayment)->create();
    $this->actingAs($admin)->withSession(['active_role' => Role::AdminPayment->value]);
    $this->get(route('finance.bills.index'))->assertOk();

    $admin->removeRole(Role::AdminPayment);

    $this->get(route('finance.bills.index'))->assertForbidden();
    $this->get(route('dashboard'))->assertRedirect(route('admin.dashboard'));
});

test('only superadmin can manage users', function (Role $role) {
    $this->actingAs(User::factory()->withRole($role)->create())
        ->get(route('superadmin.users.index'))
        ->assertForbidden();
})->with([Role::Admin, Role::AdminPayment, Role::AcademicMonitor, Role::Teacher]);

test('an admin can not take over the login of an ustadz with a staff role', function () {
    $teacher = Teacher::factory()->create();
    $teacher->user->assignRole(Role::Superadmin);
    $email = $teacher->user->email;

    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    $this->get(route('admin.teachers.edit', $teacher))->assertOk()->assertDontSee(__('Reset password'));

    Livewire::test('pages::admin.teachers.form', ['teacher' => $teacher])
        ->call('resetPassword')
        ->assertForbidden();

    Livewire::test('pages::admin.teachers.form', ['teacher' => $teacher])
        ->call('toggleActive')
        ->assertForbidden();

    Livewire::test('pages::admin.teachers.form', ['teacher' => $teacher])
        ->set('email', 'admin-owned@example.test')
        ->call('save')
        ->assertHasErrors('email');

    expect($teacher->user->fresh())->email->toBe($email)->is_active->toBeTrue()->must_change_password->toBeFalse();

    // A Superadmin still can.
    $this->actingAs(User::factory()->withRole(Role::Superadmin)->create())->withSession(['active_role' => Role::Superadmin->value]);
    $this->get(route('admin.teachers.edit', $teacher))->assertOk()->assertSee(__('Reset password'));

    Livewire::test('pages::admin.teachers.form', ['teacher' => $teacher])
        ->set('email', 'new@example.test')
        ->call('save')
        ->assertHasNoErrors()
        ->call('resetPassword')
        ->assertSet('credentialUsername', $teacher->nidm);
});
