<?php

use App\Enums\Role;
use App\Models\Setting;
use App\Models\User;
use Livewire\Livewire;

test('settings fall back to the configured defaults', function () {
    expect(Setting::get('payment.max_installments'))->toBe(3);
    expect(Setting::get('payment.min_installment_amount'))->toBe(200000);
});

test('superadmin can change settings', function () {
    $this->actingAs(User::factory()->withRole(Role::Superadmin)->create());

    $this->get(route('superadmin.settings'))->assertOk();

    Livewire::test('pages::superadmin.settings')
        ->assertSet('maxInstallments', 3)
        ->set('institutionName', "Ma'had Aly Contoh")
        ->set('maxInstallments', 4)
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('institution.name'))->toBe("Ma'had Aly Contoh");
    expect(Setting::get('payment.max_installments'))->toBe(4);
    expect(Setting::query()->find('payment.min_installment_amount'))->toBeNull();
});

test('settings are validated', function () {
    $this->actingAs(User::factory()->withRole(Role::Superadmin)->create());

    Livewire::test('pages::superadmin.settings')
        ->set('institutionName', '')
        ->set('maxInstallments', 0)
        ->set('minInstallmentAmount', null)
        ->call('save')
        ->assertHasErrors(['institutionName', 'maxInstallments', 'minInstallmentAmount']);
});

test('other roles can not open settings', function (Role $role) {
    $this->actingAs(User::factory()->withRole($role)->create())
        ->get(route('superadmin.settings'))
        ->assertForbidden();
})->with([Role::Admin, Role::AdminPayment, Role::Student]);
