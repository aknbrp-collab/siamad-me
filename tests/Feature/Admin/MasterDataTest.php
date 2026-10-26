<?php

use App\Enums\Role;
use App\Models\StudyProgram;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
});

test('admin can manage study programs', function () {
    $this->get(route('admin.study-programs'))->assertOk();

    Livewire::test('pages::admin.study-programs')
        ->call('create')
        ->set('code', 'MA')
        ->set('name', 'Takhassus Fiqh wa Ushuluhu')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Takhassus Fiqh wa Ushuluhu');

    $program = StudyProgram::query()->where('code', 'MA')->firstOrFail();

    Livewire::test('pages::admin.study-programs')
        ->call('edit', $program->id)
        ->set('name', 'Takhassus Fiqh')
        ->call('save')
        ->assertHasNoErrors();

    expect($program->fresh()->name)->toBe('Takhassus Fiqh');

    Livewire::test('pages::admin.study-programs')
        ->call('create')
        ->set('code', 'MA')
        ->set('name', 'Duplicate')
        ->call('save')
        ->assertHasErrors(['code' => 'unique']);
});
