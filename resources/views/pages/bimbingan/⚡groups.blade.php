<?php

use App\Actions\Discussions\ManageDiscussions;
use App\Enums\DiscussionKind;
use App\Enums\StudentStatus;
use App\Enums\TeacherStatus;
use App\Livewire\PageWithStudentPicker;
use App\Models\Discussion;
use App\Models\DiscussionMember;
use App\Models\Student;
use App\Models\Teacher;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

new #[Title('Bimbingan groups')] class extends PageWithStudentPicker {
    #[Url(as: 'group')]
    public ?int $selectedId = null;

    #[Url]
    public string $show = 'open';

    public ?int $editingId = null;
    public string $name = '';
    public string $description = '';
    public ?int $leadId = null;


    /**
     * @return Collection<int, Discussion>
     */
    #[Computed]
    public function groups(): Collection
    {
        return Discussion::query()
            ->with('leadTeacher.user')
            ->withCount(['members as students_count' => fn ($query) => $query->where('role', 'member')])
            ->where('kind', DiscussionKind::Bimbingan)
            ->when($this->show === 'open', fn (Builder $query) => $query->whereNull('closed_at'))
            ->when($this->show === 'closed', fn (Builder $query) => $query->whereNotNull('closed_at'))
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function selected(): ?Discussion
    {
        return $this->selectedId ? Discussion::query()->with('leadTeacher.user')->where('kind', DiscussionKind::Bimbingan)->find($this->selectedId) : null;
    }

    /**
     * @return Collection<int, DiscussionMember>
     */
    #[Computed]
    public function members(): Collection
    {
        if ($this->selected === null) {
            return new Collection;
        }

        return $this->selected->members()->with('user.student')->where('role', 'member')->get()->sortBy('user.name')->values();
    }

    /**
     * Active mahasantri not yet in the group, all of them until filtered (v7 D43).
     *
     * @return Collection<int, Student>
     */
    #[Computed]
    public function candidates(): Collection
    {
        if ($this->selected === null) {
            return new Collection;
        }

        return $this->pickStudents(fn (Builder $query) => $query->whereNotIn('user_id', $this->members->pluck('user_id')));
    }

    /**
     * @return Collection<int, Teacher>
     */
    #[Computed]
    public function teachers(): Collection
    {
        return Teacher::query()->with('user')->where('status', TeacherStatus::Active)->get()->sortBy('user.name')->values();
    }

    public function select(int $id): void
    {
        $this->selectedId = $id;
        $this->resetStudentPicker();
        unset($this->selected, $this->members, $this->candidates);
    }

    public function create(): void
    {
        $this->reset('editingId', 'name', 'description', 'leadId');
        $this->resetErrorBag();
        Flux::modal('group-form')->show();
    }

    public function edit(int $id): void
    {
        $group = Discussion::query()->where('kind', DiscussionKind::Bimbingan)->findOrFail($id);
        $this->editingId = $group->id;
        $this->name = $group->name;
        $this->description = (string) $group->description;
        $this->leadId = $group->lead_teacher_id;
        $this->resetErrorBag();
        Flux::modal('group-form')->show();
    }

    public function save(ManageDiscussions $manage): void
    {
        $this->resetErrorBag();
        $lead = Teacher::query()->where('status', TeacherStatus::Active)->find($this->leadId);

        if ($lead === null) {
            $this->addError('leadId', __('Choose the muhadhir who leads the group.'));

            return;
        }

        if ($this->editingId) {
            $group = Discussion::query()->where('kind', DiscussionKind::Bimbingan)->findOrFail($this->editingId);
            $manage->rename($group, $this->name, $this->description);
            $manage->setLead($group, $lead);
        } else {
            $group = $manage->create(DiscussionKind::Bimbingan, $this->name, $this->description, $lead, auth()->user());
            $this->selectedId = $group->id;
        }

        Flux::modal('group-form')->close();
        unset($this->groups, $this->selected, $this->members);
        Flux::toast(variant: 'success', text: __('Group saved.'));
    }

    public function addStudent(int $studentId, ManageDiscussions $manage): void
    {
        abort_if($this->selected === null, 404);
        $student = Student::query()->where('status', StudentStatus::Active)->findOrFail($studentId);

        $manage->addMembers($this->selected, [$student->user_id]);

        unset($this->members, $this->candidates, $this->groups);
    }

    public function removeMember(int $memberId, ManageDiscussions $manage): void
    {
        abort_if($this->selected === null, 404);
        $manage->removeMember($this->selected->members()->findOrFail($memberId));

        unset($this->members, $this->candidates, $this->groups);
        Flux::toast(variant: 'success', text: __('Removed from the group. Their messages stay.'));
    }

    public function toggleClosed(ManageDiscussions $manage): void
    {
        abort_if($this->selected === null, 404);
        $manage->setClosed($this->selected, $this->selected->closed_at === null, auth()->user());

        unset($this->selected, $this->groups);
    }
}; ?>

<section class="w-full">
    <x-page-header :title="__('Bimbingan groups')" :subtitle="__('Groups for bimbingan penelitian dan penulisan: each is led by one muhadhir and has the mahasantri you add. A mahasantri can be in several groups. Every group has its own discussion room.')">
        <x-slot:actions>
            <flux:button variant="primary" icon="plus" wire:click="create" data-test="add-group">{{ __('Add group') }}</flux:button>
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-6 lg:grid-cols-[20rem_1fr]">
        <div>
            <flux:radio.group wire:model.live="show" variant="segmented" class="mb-3 w-full">
                <flux:radio value="open" :label="__('Active')" />
                <flux:radio value="closed" :label="__('Closed')" />
                <flux:radio value="all" :label="__('All')" />
            </flux:radio.group>
            <div class="grid gap-1">
                @forelse ($this->groups as $group)
                    <button type="button" wire:key="group-{{ $group->id }}" wire:click="select({{ $group->id }})"
                        @class([
                            'flex items-center justify-between gap-2 rounded-lg px-3 py-2 text-start text-sm transition',
                            'bg-zinc-100 font-medium dark:bg-zinc-700' => $selectedId === $group->id,
                            'hover:bg-zinc-50 dark:hover:bg-zinc-800' => $selectedId !== $group->id,
                        ])>
                        <span class="min-w-0">
                            <span class="block truncate">{{ $group->name }}</span>
                            <span class="block truncate text-xs text-zinc-500">{{ $group->leadTeacher?->displayName() }}</span>
                        </span>
                        <flux:badge size="sm">{{ $group->students_count }}</flux:badge>
                    </button>
                @empty
                    <flux:text class="text-sm">{{ __('No groups yet.') }}</flux:text>
                @endforelse
            </div>
        </div>

        <div>
            @if ($this->selected)
                @php($group = $this->selected)
                <div class="mb-4 flex flex-wrap items-start gap-2">
                    <div class="me-auto">
                        <flux:heading size="lg">{{ $group->name }}</flux:heading>
                        <flux:text class="text-sm">{{ __('Led by :name', ['name' => $group->leadTeacher?->displayName()]) }}@if ($group->description) · {{ $group->description }}@endif</flux:text>
                    </div>
                    @if ($group->closed_at)
                        <flux:badge color="zinc" icon="archive-box">{{ __('Closed') }}</flux:badge>
                    @endif
                    <flux:button size="sm" icon="chat-bubble-left-right" :href="route('discussions.show', $group)" wire:navigate>{{ __('Open discussion') }}</flux:button>
                    <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $group->id }})">{{ __('Edit') }}</flux:button>
                    <flux:button size="sm" variant="ghost" :icon="$group->closed_at ? 'arrow-uturn-left' : 'archive-box'" wire:click="toggleClosed"
                        wire:confirm="{{ $group->closed_at ? __('Reopen this group?') : __('Close this group? Its discussion stays readable.') }}" data-test="toggle-closed">
                        {{ $group->closed_at ? __('Reopen') : __('Close') }}
                    </flux:button>
                </div>

                @unless ($group->closed_at)
                    <flux:heading class="mb-2">{{ __('Add mahasantri') }}</flux:heading>
                    <x-student-picker.filters :classes="$this->pickerClasses" class="mb-2" />
                    @if ($this->candidates->isNotEmpty())
                        <div class="mb-1 max-h-96 divide-y divide-zinc-200 overflow-y-auto rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700" data-test="candidates">
                            @foreach ($this->candidates as $candidate)
                                <div wire:key="candidate-{{ $candidate->id }}" class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                                    <span>
                                        <span class="font-mono text-zinc-500">{{ $candidate->nim }}</span> <span class="ms-2 font-medium">{{ $candidate->user->name }}</span>
                                        <span class="ms-1 text-xs text-zinc-500">{{ $candidate->classEnrollments->first()?->class_name ?? __('No class') }}</span>
                                    </span>
                                    <flux:button size="xs" wire:click="addStudent({{ $candidate->id }})" data-test="add-{{ $candidate->id }}">{{ __('Add') }}</flux:button>
                                </div>
                            @endforeach
                        </div>
                    @endif
                    <x-student-picker.more :shown="$this->candidates->count()" :total="$this->pickerTotal" class="mb-4" />
                @endunless

                <flux:heading class="mb-2">{{ trans_choice(':count mahasantri|:count mahasantri', $this->members->count()) }}</flux:heading>
                <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                    @forelse ($this->members as $member)
                        <div wire:key="member-{{ $member->id }}" class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                            <span><span class="font-mono text-zinc-500">{{ $member->user->student?->nim }}</span> <span class="ms-2 font-medium">{{ $member->user->name }}</span></span>
                            <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="removeMember({{ $member->id }})" wire:confirm="{{ __('Remove :name from the group?', ['name' => $member->user->name]) }}" :aria-label="__('Remove')" />
                        </div>
                    @empty
                        <flux:text class="p-3 text-sm">{{ __('No mahasantri in this group yet.') }}</flux:text>
                    @endforelse
                </div>
            @else
                <flux:text>{{ __('Choose a group, or add one.') }}</flux:text>
            @endif
        </div>
    </div>

    <flux:modal name="group-form" class="w-full max-w-lg">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingId ? __('Edit group') : __('Add group') }}</flux:heading>
            <flux:input wire:model="name" :label="__('Name')" :placeholder="__('e.g. Bimbingan Skripsi Fiqh 2026')" required />
            <flux:textarea wire:model="description" :label="__('Description (optional)')" rows="2" />
            <flux:select wire:model="leadId" :label="__('Led by')" :placeholder="__('Choose a muhadhir…')" required>
                @foreach ($this->teachers as $teacher)
                    <flux:select.option :value="$teacher->id">{{ $teacher->displayName() }}</flux:select.option>
                @endforeach
            </flux:select>
            @if ($editingId)
                <flux:text class="text-xs">{{ __('A new lead takes over the group; the previous one leaves it. Their messages stay.') }}</flux:text>
            @endif
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
