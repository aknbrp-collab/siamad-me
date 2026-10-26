<?php

use App\Actions\Academic\SaveAcademicYear;
use App\Actions\Academic\SemesterLifecycle;
use App\Enums\Role;
use App\Models\AcademicYear;
use App\Models\Semester;
use App\Support\ActiveRole;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Academic years')] class extends Component {
    public ?int $editingId = null;
    public string $name = '';
    public ?string $startsOn = null;
    public ?string $endsOn = null;

    public ?int $closingId = null;
    public int $closingPending = 0;

    public ?int $reopeningId = null;
    public string $reopenReason = '';

    /**
     * @return Collection<int, AcademicYear>
     */
    #[Computed]
    public function years(): Collection
    {
        return AcademicYear::query()
            ->with(['semesters' => fn ($query) => $query->withCount(['classEnrollments', 'courseOfferings'])])
            ->orderByDesc('name')
            ->get();
    }

    #[Computed]
    public function isSuperadmin(): bool
    {
        return ActiveRole::get() === Role::Superadmin;
    }

    public function create(): void
    {
        $latest = AcademicYear::query()->orderByDesc('name')->value('name');
        $start = $latest ? (int) explode('/', $latest)[1] : now()->year;

        $this->editingId = null;
        $this->name = $start.'/'.($start + 1);
        $this->startsOn = "{$start}-07-01";
        $this->endsOn = ($start + 1).'-06-30';
        $this->resetValidation();
        Flux::modal('year-form')->show();
    }

    public function edit(int $id): void
    {
        $year = AcademicYear::query()->findOrFail($id);

        $this->editingId = $year->id;
        $this->name = $year->name;
        $this->startsOn = $year->starts_on->format('Y-m-d');
        $this->endsOn = $year->ends_on->format('Y-m-d');
        $this->resetValidation();
        Flux::modal('year-form')->show();
    }

    public function save(SaveAcademicYear $saveAcademicYear): void
    {
        $this->resetErrorBag();

        $saveAcademicYear->handle(
            $this->editingId ? AcademicYear::query()->findOrFail($this->editingId) : null,
            ['name' => $this->name, 'starts_on' => $this->startsOn, 'ends_on' => $this->endsOn],
        );

        Flux::modal('year-form')->close();
        Flux::toast(variant: 'success', text: __('Academic year saved.'));
    }

    public function activate(int $semesterId, SemesterLifecycle $lifecycle): void
    {
        $this->resetErrorBag();

        $lifecycle->activate(Semester::query()->findOrFail($semesterId));

        Flux::toast(variant: 'success', text: __('Semester activated.'));
    }

    public function confirmClose(int $semesterId, SemesterLifecycle $lifecycle): void
    {
        $this->closingId = $semesterId;
        $this->closingPending = $lifecycle->unfinalizedCount(Semester::query()->findOrFail($semesterId));
        $this->resetValidation();
        Flux::modal('close-semester')->show();
    }

    public function close(SemesterLifecycle $lifecycle): void
    {
        $this->resetErrorBag();

        $lifecycle->close(Semester::query()->findOrFail($this->closingId), confirmed: true);

        Flux::modal('close-semester')->close();
        Flux::toast(variant: 'success', text: __('Semester closed. Its records are now read-only.'));
    }

    public function confirmReopen(int $semesterId): void
    {
        abort_unless($this->isSuperadmin, 403);

        $this->reopeningId = $semesterId;
        $this->reopenReason = '';
        $this->resetValidation();
        Flux::modal('reopen-semester')->show();
    }

    public function reopen(SemesterLifecycle $lifecycle): void
    {
        $this->resetErrorBag();

        abort_unless($this->isSuperadmin, 403);

        $lifecycle->reopen(Semester::query()->findOrFail($this->reopeningId), $this->reopenReason);

        Flux::modal('reopen-semester')->close();
        Flux::toast(variant: 'warning', text: __('Semester reopened. Close it again when the correction is done.'));
    }
}; ?>

<section class="w-full">
    <x-page-header :title="__('Academic years')" :subtitle="__('Each year has an odd and an even semester. Closing a semester makes all its records read-only.')">
        <x-slot:actions>
            <flux:button variant="primary" icon="plus" wire:click="create">{{ __('Add academic year') }}</flux:button>
        </x-slot:actions>
    </x-page-header>

    @error('semester')
        <flux:callout variant="danger" icon="exclamation-triangle" class="mb-6" :text="$message" />
    @enderror

    <div class="flex flex-col gap-4">
        @forelse ($this->years as $year)
            <flux:card wire:key="year-{{ $year->id }}" class="space-y-4">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <flux:heading size="lg">{{ $year->name }}</flux:heading>
                        <flux:text>{{ $year->starts_on->translatedFormat('j M Y') }} – {{ $year->ends_on->translatedFormat('j M Y') }}</flux:text>
                    </div>
                    <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $year->id }})" :aria-label="__('Edit')" />
                </div>

                <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($year->semesters as $semester)
                        <div wire:key="semester-{{ $semester->id }}" class="flex flex-col gap-3 py-3 sm:flex-row sm:items-center">
                            <div class="flex flex-1 flex-wrap items-center gap-2">
                                <span class="font-medium">{{ $semester->term->label() }}</span>
                                <flux:badge size="sm" :color="$semester->status->color()">{{ $semester->status->label() }}</flux:badge>
                                <flux:text class="text-sm">
                                    {{ trans_choice(':count mahasantri|:count mahasantri', $semester->class_enrollments_count) }} ·
                                    {{ trans_choice(':count offering|:count offerings', $semester->course_offerings_count) }}
                                </flux:text>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <flux:button size="sm" :href="route('admin.semesters.show', $semester)" wire:navigate icon="arrow-right">{{ __('Open') }}</flux:button>
                                @if ($semester->status === \App\Enums\SemesterStatus::Planned)
                                    <flux:button size="sm" variant="primary" wire:click="activate({{ $semester->id }})" wire:confirm="{{ __('Activate this semester?') }}">{{ __('Activate') }}</flux:button>
                                @elseif ($semester->status === \App\Enums\SemesterStatus::Active)
                                    <flux:button size="sm" wire:click="confirmClose({{ $semester->id }})" icon="lock-closed">{{ __('Close') }}</flux:button>
                                @elseif ($this->isSuperadmin)
                                    <flux:button size="sm" variant="danger" wire:click="confirmReopen({{ $semester->id }})" icon="lock-open">{{ __('Reopen') }}</flux:button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </flux:card>
        @empty
            <flux:text class="py-8 text-center">{{ __('No academic years yet. Add the first one to start.') }}</flux:text>
        @endforelse
    </div>

    <flux:modal name="year-form" class="w-full max-w-md">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit academic year') : __('Add academic year') }}</flux:heading>
            <flux:input wire:model="name" :label="__('Name')" placeholder="2026/2027" required :disabled="(bool) $editingId" />
            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input wire:model="startsOn" :label="__('Starts on')" type="date" required />
                <flux:input wire:model="endsOn" :label="__('Ends on')" type="date" required />
            </div>
            @unless ($editingId)
                <flux:text class="text-sm">{{ __('The odd and even semesters are created as planned.') }}</flux:text>
            @endunless
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="close-semester" class="w-full max-w-md">
        <div class="space-y-6">
            <flux:heading size="lg">{{ __('Close semester?') }}</flux:heading>
            <flux:text>{{ __('After closing, enrolments, offerings, attendance and grades of this semester can no longer be changed. Only a Superadmin can reopen it.') }}</flux:text>
            @if ($closingPending > 0)
                <flux:callout variant="warning" icon="exclamation-triangle"
                    :text="trans_choice(':count course enrolment has no final grade yet.|:count course enrolments have no final grade yet.', $closingPending)" />
            @endif
            @error('semester')
                <flux:callout variant="danger" icon="exclamation-triangle" :text="$message" />
            @enderror
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="close" data-test="confirm-close-semester">{{ __('Close semester') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="reopen-semester" class="w-full max-w-md">
        <form wire:submit="reopen" class="space-y-6">
            <flux:heading size="lg">{{ __('Reopen semester?') }}</flux:heading>
            <flux:text>{{ __('Reopening allows changes to a closed semester again. The reason is kept in the audit log.') }}</flux:text>
            <flux:textarea wire:model="reopenReason" :label="__('Reason')" rows="3" required />
            @error('semester')
                <flux:callout variant="danger" icon="exclamation-triangle" :text="$message" />
            @enderror
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="danger">{{ __('Reopen') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
