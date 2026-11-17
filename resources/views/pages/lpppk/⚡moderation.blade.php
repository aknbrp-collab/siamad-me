<?php

use App\Actions\Discussions\ModerateLpppk;
use App\Enums\Gender;
use App\Enums\TeacherStatus;
use App\Livewire\PageWithStudentPicker;
use App\Models\LpppkSanction;
use App\Models\Semester;
use App\Models\User;
use App\Support\StudentPicker;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;

new #[Title('LPPPK moderation')] class extends PageWithStudentPicker {
    /** '' everyone, 'student' mahasantri only, 'teacher' muhadhir only. */
    public string $who = '';

    public ?int $personId = null;
    public string $action = 'mute';
    public ?string $mutedUntil = null;
    public string $reason = '';

    /**
     * Active mahasantri and muhadhir, all of them until filtered (v7 D43). A class filter leaves the
     * muhadhir out; Putra/Putri applies to both.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function people(): Collection
    {
        $semester = Semester::active();
        $gender = Gender::tryFrom($this->pickerGender);
        $search = trim($this->studentSearch);
        $term = '%'.mb_strtolower($search).'%';
        $withStudents = $this->who !== 'teacher';
        $withTeachers = $this->who !== 'student' && $this->pickerClass === '';

        $query = User::query()
            ->with(['student.classEnrollments' => fn ($query) => $query->where('semester_id', $semester->id ?? 0), 'teacher'])
            ->where(function (Builder $query) use ($withStudents, $withTeachers, $semester, $gender) {
                $query->whereRaw('1 = 0');
                if ($withStudents) {
                    $query->orWhereHas('student', fn (Builder $query) => StudentPicker::filter($query, null, $this->pickerClass, $this->pickerGender, $semester));
                }
                if ($withTeachers) {
                    $query->orWhereHas('teacher', fn (Builder $query) => $query->where('status', TeacherStatus::Active)
                        ->when($gender, fn (Builder $query, Gender $gender) => $query->where('gender', $gender)));
                }
            })
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereRaw('lower(name) like ?', [$term])
                ->orWhereRaw('lower(username) like ?', [$term])));

        $this->pickerTotal = (clone $query)->count();

        return $query->orderBy('name')->limit(max(StudentPicker::PAGE, $this->pickerLimit))->get();
    }

    public function updatedWho(): void
    {
        $this->pickerLimit = StudentPicker::PAGE;
    }

    /**
     * Everyone muted or restricted now.
     *
     * @return Collection<int, LpppkSanction>
     */
    #[Computed]
    public function sanctions(): Collection
    {
        return LpppkSanction::query()
            ->with(['user', 'decider'])
            ->where(fn (Builder $query) => $query->whereNotNull('restricted_at')
                ->orWhere(fn (Builder $query) => $query->whereNotNull('muted_at')->where(fn (Builder $query) => $query->whereNull('muted_until')->orWhere('muted_until', '>', now()))))
            ->latest('updated_at')
            ->get();
    }

    #[Computed]
    public function person(): ?User
    {
        return $this->personId ? User::query()->find($this->personId) : null;
    }

    public function start(int $userId, string $action): void
    {
        $this->personId = $userId;
        $this->action = $action === 'restrict' ? 'restrict' : 'mute';
        $this->mutedUntil = null;
        $this->reason = '';
        $this->resetErrorBag();
        unset($this->person);
        Flux::modal('sanction-form')->show();
    }

    public function decide(ModerateLpppk $moderate): void
    {
        $this->resetErrorBag();
        $person = User::query()->findOrFail($this->personId);

        $this->action === 'restrict'
            ? $moderate->restrict($person, $this->reason, auth()->user())
            : $moderate->mute($person, filled($this->mutedUntil) ? Carbon::parse((string) $this->mutedUntil) : null, $this->reason, auth()->user());

        Flux::modal('sanction-form')->close();
        unset($this->sanctions);
        Flux::toast(variant: 'success', text: $this->action === 'restrict' ? __('Access to LPPPK closed.') : __('Muted.'));
    }

    public function lift(int $userId, ModerateLpppk $moderate): void
    {
        $moderate->lift(User::query()->findOrFail($userId), auth()->user());
        unset($this->sanctions);
        Flux::toast(variant: 'success', text: __('They can take part in LPPPK again.'));
    }
}; ?>

<section class="w-full max-w-3xl">
    <x-page-header :title="__('LPPPK moderation')" :subtitle="__('Mute a person in every LPPPK channel (they can still read), or close LPPPK to them. They are told, with your reason. Single messages are removed in the channel itself.')">
        <x-slot:actions>
            <flux:button icon="arrow-left" :href="route('lpppk')" wire:navigate>{{ __('LPPPK') }}</flux:button>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-2 grid gap-2 sm:grid-cols-[10rem_1fr]">
        <flux:select wire:model.live="who" size="sm" data-test="picker-who">
            <flux:select.option value="">{{ __('Mahasantri & muhadhir') }}</flux:select.option>
            <flux:select.option value="student">{{ __('Mahasantri') }}</flux:select.option>
            <flux:select.option value="teacher">{{ __('Muhadhir') }}</flux:select.option>
        </flux:select>
        <x-student-picker.filters :classes="$this->pickerClasses" :placeholder="__('Name, NIM or NIDM…')" />
    </div>
    @if ($this->people->isNotEmpty())
        <div class="mb-1 max-h-[28rem] divide-y divide-zinc-200 overflow-y-auto rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700" data-test="candidates">
            @foreach ($this->people as $person)
                <div wire:key="person-{{ $person->id }}" class="flex flex-wrap items-center gap-2 px-3 py-2 text-sm">
                    <span class="me-auto">
                        <span class="font-mono text-zinc-500">{{ $person->username }}</span>
                        <span class="ms-2 font-medium">{{ $person->name }}</span>
                        <span class="ms-1 text-xs text-zinc-500">{{ $person->teacher ? __('Muhadhir') : ($person->student?->classEnrollments->first()?->class_name ?? __('Mahasantri')) }}</span>
                    </span>
                    <flux:button size="xs" icon="speaker-x-mark" wire:click="start({{ $person->id }}, 'mute')" data-test="mute-{{ $person->id }}">{{ __('Mute') }}</flux:button>
                    <flux:button size="xs" variant="danger" icon="no-symbol" wire:click="start({{ $person->id }}, 'restrict')" data-test="restrict-{{ $person->id }}">{{ __('Close access') }}</flux:button>
                </div>
            @endforeach
        </div>
    @endif
    <x-student-picker.more :shown="$this->people->count()" :total="$this->pickerTotal" :empty="__('Nobody found.')" class="mb-8" />

    <flux:heading class="mb-2 mt-6">{{ __('Muted or without access') }}</flux:heading>
    <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
        @forelse ($this->sanctions as $sanction)
            <div wire:key="sanction-{{ $sanction->id }}" class="flex flex-wrap items-center gap-2 px-3 py-2.5 text-sm">
                <span class="me-auto">
                    <span class="font-medium">{{ $sanction->user->name }}</span>
                    @if ($sanction->isRestricted())
                        <flux:badge size="sm" color="red" class="ms-1">{{ __('No access') }}</flux:badge>
                    @else
                        <flux:badge size="sm" color="amber" class="ms-1">{{ $sanction->muted_until ? __('Muted until :date', ['date' => $sanction->muted_until->translatedFormat('j M H:i')]) : __('Muted') }}</flux:badge>
                    @endif
                    <span class="block text-xs text-zinc-500">{{ $sanction->reason ?? '—' }} · {{ $sanction->decider->name }}, {{ $sanction->updated_at->translatedFormat('j M Y') }}</span>
                </span>
                <flux:button size="xs" wire:click="lift({{ $sanction->user_id }})" wire:confirm="{{ __('Lift this for :name?', ['name' => $sanction->user->name]) }}" data-test="lift-{{ $sanction->user_id }}">{{ __('Lift') }}</flux:button>
            </div>
        @empty
            <flux:text class="p-3 text-sm">{{ __('Nobody is muted or without access.') }}</flux:text>
        @endforelse
    </div>

    <flux:modal name="sanction-form" class="w-full max-w-md">
        <form wire:submit="decide" class="space-y-4">
            <flux:heading size="lg">{{ $action === 'restrict' ? __('Close LPPPK to :name', ['name' => $this->person?->name]) : __('Mute :name in LPPPK', ['name' => $this->person?->name]) }}</flux:heading>
            @if ($action === 'mute')
                <flux:input wire:model="mutedUntil" type="datetime-local" :label="__('Until')" :description:trailing="__('Leave empty to mute until you lift it.')" />
                <flux:error name="mutedUntil" />
            @else
                <flux:text>{{ __('They no longer see LPPPK at all until you lift it.') }}</flux:text>
            @endif
            <flux:textarea wire:model="reason" :label="__('Reason (shown to them)')" rows="2" />
            <flux:error name="reason" />
            <flux:error name="person" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="danger">{{ $action === 'restrict' ? __('Close access') : __('Mute') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
