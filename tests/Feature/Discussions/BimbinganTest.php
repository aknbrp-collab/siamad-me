<?php

use App\Actions\Discussions\ManageDiscussions;
use App\Enums\DiscussionKind;
use App\Enums\Role;
use App\Models\Discussion;
use App\Models\DiscussionMember;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->ahmad = Teacher::factory()->create();
    $this->hasan = Teacher::factory()->create();
    $this->faqih = Student::factory()->create(['nim' => '2026110001']);
    $this->rifqi = Student::factory()->create(['nim' => '2026110002']);
});

test('admin creates a group, adds mahasantri and they are told', function () {
    $this->actingAs($this->admin)->get(route('bimbingan.groups'))->assertOk();

    $page = Livewire::test('pages::bimbingan.groups')
        ->call('create')
        ->set('name', 'Bimbingan Skripsi Fiqh')
        ->call('save')
        ->assertHasErrors('leadId')
        ->set('leadId', $this->ahmad->id)
        ->call('save')
        ->assertHasNoErrors();

    $group = Discussion::query()->where('kind', DiscussionKind::Bimbingan)->sole();
    expect($page->get('selectedId'))->toBe($group->id);

    $page->set('studentSearch', '2026110001')
        ->assertSee($this->faqih->user->name)
        ->call('addStudent', $this->faqih->id)
        ->set('studentSearch', 'xx')
        ->assertSee(trans_choice(':count mahasantri|:count mahasantri', 1));

    expect(DiscussionMember::query()->where('discussion_id', $group->id)->pluck('role', 'user_id')->all())
        ->toBe([$this->ahmad->user_id => 'lead', $this->faqih->user_id => 'member'])
        ->and($this->faqih->user->notifications()->sole()->data['title'])->toContain('Bimbingan Skripsi Fiqh');
});

test('a new lead takes over and the previous one leaves', function () {
    $manage = app(ManageDiscussions::class);
    $group = $manage->create(DiscussionKind::Bimbingan, 'Bimbingan A', null, $this->ahmad, $this->admin);
    $manage->addMembers($group, [$this->faqih->user_id]);

    $this->actingAs($this->admin);
    Livewire::test('pages::bimbingan.groups')->call('edit', $group->id)->set('leadId', $this->hasan->id)->call('save')->assertHasNoErrors();

    expect($group->fresh()->lead_teacher_id)->toBe($this->hasan->id)
        ->and(DiscussionMember::query()->where('discussion_id', $group->id)->pluck('role', 'user_id')->sortKeys()->all())
        ->toBe(collect([$this->faqih->user_id => 'member', $this->hasan->user_id => 'lead'])->sortKeys()->all());

    $this->actingAs($this->ahmad->user)->get(route('discussions.show', $group))->assertForbidden();
});

test('muhadhir and mahasantri see their groups with unread counts; closed groups stay readable', function () {
    $manage = app(ManageDiscussions::class);
    $mine = $manage->create(DiscussionKind::Bimbingan, 'Bimbingan Makalah', null, $this->ahmad, $this->admin);
    $other = $manage->create(DiscussionKind::Bimbingan, 'Bimbingan Lain', null, $this->hasan, $this->admin);
    $manage->addMembers($mine, [$this->faqih->user_id, $this->rifqi->user_id]);
    $manage->post($mine, $this->ahmad->user, 'Kirim kerangka pekan ini.');
    $manage->post($mine, $this->rifqi->user, 'Baik ustadz.');

    $this->actingAs($this->faqih->user)->withSession(['active_role' => Role::Student->value])
        ->get(route('bimbingan'))->assertOk()
        ->assertSee('Bimbingan Makalah')->assertDontSee('Bimbingan Lain')
        ->assertSeeHtml('data-test="group-'.$mine->id.'"');
    expect($manage->unread($this->faqih->user, DiscussionKind::Bimbingan))->toBe([$mine->id => 2]);

    $this->actingAs($this->ahmad->user)->withSession(['active_role' => Role::Teacher->value])
        ->get(route('bimbingan'))->assertOk()->assertSee('Bimbingan Makalah');

    $manage->setClosed($mine, true, $this->admin);
    $this->actingAs($this->faqih->user)->get(route('discussions.show', $mine))->assertOk()->assertSee(__('This discussion is closed; it can only be read.'));

    $this->actingAs(User::factory()->withRole(Role::AdminPayment)->create())->get(route('bimbingan.groups'))->assertForbidden();
    $this->actingAs(User::factory()->withRole(Role::AcademicMonitor)->create())->get(route('bimbingan.groups'))->assertOk();
});

test('bimbingan is in the menu of muhadhir and mahasantri, and managed by Admin and the monitor', function (Role $role, string $route) {
    expect(collect($role->navigation())->flatMap(fn ($group) => $group['items'])->pluck('route'))->toContain($route);
})->with([
    [Role::Teacher, 'bimbingan'],
    [Role::Student, 'bimbingan'],
    [Role::Admin, 'bimbingan.groups'],
    [Role::AcademicMonitor, 'bimbingan.groups'],
]);
