<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserRole;
use Livewire\Livewire;

test('creating, updating and deleting audited models is logged', function () {
    $actor = User::factory()->withRole(Role::Admin)->create();
    $this->actingAs($actor);

    $user = User::factory()->create(['name' => 'Before']);
    $user->update(['name' => 'After']);
    $user->delete();

    $logs = AuditLog::query()
        ->where('auditable_type', $user->getMorphClass())
        ->where('auditable_id', (string) $user->id)
        ->orderBy('id')
        ->get();

    expect($logs->pluck('event')->all())->toBe(['created', 'updated', 'deleted']);
    expect($logs[1]->old_values)->toBe(['name' => 'Before']);
    expect($logs[1]->new_values)->toBe(['name' => 'After']);
    expect($logs[1]->user_id)->toBe($actor->id);
});

test('hidden attributes are never written to the log', function () {
    $user = User::factory()->create();
    $user->update(['password' => 'a-new-secret-password']);

    $log = AuditLog::query()->where('event', 'updated')->latest('id')->first();

    expect($log->new_values)->toBe(['password' => '[hidden]']);
    expect(json_encode(AuditLog::all()))->not->toContain($user->password);
});

test('role assignments and settings changes are logged', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::Teacher);
    Setting::set('payment.max_installments', 5);

    expect(AuditLog::query()->where('auditable_type', (new UserRole)->getMorphClass())->value('new_values'))
        ->toMatchArray(['role' => 'teacher', 'user_id' => $user->id]);

    expect(AuditLog::query()->where('auditable_type', (new Setting)->getMorphClass())->value('auditable_id'))
        ->toBe('payment.max_installments');
});

test('logins do not create audit noise', function () {
    $user = User::factory()->withRole(Role::Student)->create();
    $before = AuditLog::count();

    $this->post(route('login.store'), ['username' => $user->username, 'password' => 'password']);

    expect(AuditLog::count())->toBe($before);
});

test('superadmin can browse and filter the audit log', function () {
    $this->actingAs(User::factory()->withRole(Role::Superadmin)->create(['name' => 'Kepala Sistem']));
    User::factory()->create(['name' => 'Someone']);

    $this->get(route('superadmin.audit-logs'))->assertOk();

    Livewire::test('pages::superadmin.audit-logs')
        ->assertSee('User #')
        ->set('event', 'deleted')
        ->assertSee(__('No audit entries found.'));
});

test('only superadmin can open the audit log', function () {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create())
        ->get(route('superadmin.audit-logs'))
        ->assertForbidden();
});
