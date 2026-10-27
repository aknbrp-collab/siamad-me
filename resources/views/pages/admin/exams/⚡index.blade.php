<?php

use App\Actions\Exams\ManageExams;
use App\Enums\ExamType;
use App\Models\ExamPeriod;
use App\Models\Semester;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Exams')] class extends Component {
    #[Url(as: 'semester')]
    public ?int $semesterId = null;

    public ?string $type = null;
    public ?string $startsOn = null;
    public ?string $endsOn = null;

    public function mount(): void
    {
        $this->semesterId ??= Semester::active()?->id ?? Semester::query()->orderByDesc('id')->value('id');
    }

    /**
     * @return Collection<int, Semester>
     */
    #[Computed]
    public function semesters(): Collection
    {
        return Semester::query()->with('academicYear')->orderByDesc('academic_year_id')->orderByDesc('term')->get();
    }

    #[Computed]
    public function semester(): ?Semester
    {
        return $this->semesters->firstWhere('id', $this->semesterId);
    }

    /**
     * @return Collection<int, ExamPeriod>
     */
    #[Computed]
    public function periods(): Collection
    {
        return ExamPeriod::query()
            ->withCount('sessions')
            ->where('semester_id', $this->semesterId)
            ->orderBy('starts_on')
            ->get();
    }

    public function create(): void
    {
        $this->reset('type', 'startsOn', 'endsOn');
        $this->type = collect(ExamType::cases())->first(fn (ExamType $type) => ! $this->periods->contains('type', $type))?->value;
        $this->resetErrorBag();
        Flux::modal('period-form')->show();
    }

    public function save(ManageExams $exams): void
    {
        $this->resetErrorBag();
        abort_if($this->semester === null, 404);

        $period = $exams->savePeriod($this->semester, null, [
            'type' => $this->type,
            'starts_on' => $this->startsOn,
            'ends_on' => $this->endsOn,
        ]);

        Flux::modal('period-form')->close();
        $this->redirect(route('admin.exams.show', $period), navigate: true);
    }
}; ?>

<section class="w-full">
    <x-page-header :title="__('Exams')" :subtitle="__('UTS and UAS per semester: the schedule of every subject, rooms and seat numbers, and exam cards.')">
        <x-slot:actions>
            <flux:button icon="building-office" :href="route('admin.rooms')" wire:navigate>{{ __('Exam rooms') }}</flux:button>
            @if ($this->semester && ! $this->semester->isReadOnly() && $this->periods->count() < count(ExamType::cases()))
                <flux:button variant="primary" icon="plus" wire:click="create">{{ __('Add exam period') }}</flux:button>
            @endif
        </x-slot:actions>
    </x-page-header>

    <flux:select wire:model.live="semesterId" class="mb-4 sm:max-w-60">
        @foreach ($this->semesters as $semester)
            <flux:select.option :value="$semester->id">{{ $semester->label() }}</flux:select.option>
        @endforeach
    </flux:select>

    <div class="space-y-3">
        @forelse ($this->periods as $period)
            <a wire:key="period-{{ $period->id }}" href="{{ route('admin.exams.show', $period) }}" wire:navigate
                class="flex flex-wrap items-center gap-3 rounded-lg border border-zinc-200 p-4 transition hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800">
                <div class="me-auto">
                    <div class="font-semibold">{{ $period->type->label() }}</div>
                    <flux:text class="text-sm">{{ $period->starts_on->translatedFormat('j M Y') }} – {{ $period->ends_on->translatedFormat('j M Y') }}</flux:text>
                </div>
                <flux:text class="text-sm">{{ trans_choice(':count exam|:count exams', $period->sessions_count) }} · {{ $period->seats_assigned_at ? __('seats shuffled') : __('seats not shuffled') }}</flux:text>
                <flux:badge size="sm" :color="$period->isPublished() ? 'green' : 'zinc'">{{ $period->isPublished() ? __('Published') : __('Draft') }}</flux:badge>
            </a>
        @empty
            <flux:text class="py-6 text-center">{{ __('No exam periods in this semester yet.') }}</flux:text>
        @endforelse
    </div>

    <flux:modal name="period-form" class="w-full max-w-md">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ __('Add exam period') }}</flux:heading>
            <flux:select wire:model="type" :label="__('Exam')" required>
                @foreach (ExamType::cases() as $case)
                    <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input wire:model="startsOn" :label="__('From')" type="date" required />
                <flux:input wire:model="endsOn" :label="__('Until')" type="date" required />
            </div>
            <flux:error name="starts_on" />
            <flux:error name="ends_on" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
