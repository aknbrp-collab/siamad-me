<?php

use App\Enums\TeacherStatus;
use App\Models\ClassGroup;
use App\Models\CourseOffering;
use App\Models\Semester;
use App\Models\Teacher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Timetable')] class extends Component {
    #[Url(as: 'semester')]
    public ?int $semesterId = null;

    /** class | teacher | room */
    #[Url]
    public string $by = 'class';

    #[Url(as: 'for')]
    public string $value = '';

    public function mount(): void
    {
        $this->semesterId ??= Semester::active()?->id ?? Semester::query()->orderByDesc('id')->value('id');
        $this->value = $this->value !== '' ? $this->value : (string) ($this->choices->keys()->first() ?? '');
    }

    public function updatedBy(): void
    {
        unset($this->choices);
        $this->value = (string) ($this->choices->keys()->first() ?? '');
    }

    /**
     * @return Collection<int, Semester>
     */
    #[Computed]
    public function semesters(): Collection
    {
        return Semester::query()->with('academicYear')->orderByDesc('academic_year_id')->orderByDesc('term')->get();
    }

    /**
     * What can be picked for the current view, value => label.
     *
     * @return SupportCollection<string, string>
     */
    #[Computed]
    public function choices(): SupportCollection
    {
        return match ($this->by) {
            'teacher' => Teacher::query()->with('user')->where('status', TeacherStatus::Active)->get()
                ->sortBy('user.name')->mapWithKeys(fn (Teacher $teacher) => [(string) $teacher->id => $teacher->displayName()]),
            'room' => CourseOffering::query()->where('semester_id', $this->semesterId)->whereNotNull('room')
                ->distinct()->orderBy('room')->pluck('room')->mapWithKeys(fn (string $room) => [$room => $room]),
            default => ClassGroup::query()->orderBy('level')->orderBy('name')->get()
                ->mapWithKeys(fn (ClassGroup $class) => [(string) $class->id => $class->name]),
        };
    }

    /**
     * @return Collection<int, CourseOffering>
     */
    #[Computed]
    public function offerings(): Collection
    {
        if ($this->value === '') {
            return new Collection;
        }

        return CourseOffering::query()
            ->with(['classGroup', 'teacher.user'])
            ->where('semester_id', $this->semesterId)
            ->when($this->by === 'class', fn ($query) => $query->where('class_group_id', (int) $this->value))
            ->when($this->by === 'teacher', fn ($query) => $query->where('teacher_id', (int) $this->value))
            ->when($this->by === 'room', fn ($query) => $query->where('room', $this->value))
            ->get();
    }
}; ?>

<section class="w-full">
    <x-page-header :title="__('Timetable')" :subtitle="__('The weekly schedule of course offerings. Set day, time and room on the semester page (Kelas kuliah).')">
        <x-slot:actions>
            <flux:button icon="printer" onclick="window.print()" class="print:hidden">{{ __('Print') }}</flux:button>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row print:hidden">
        <flux:select wire:model.live="semesterId" class="sm:max-w-60">
            @foreach ($this->semesters as $semester)
                <flux:select.option :value="$semester->id">{{ $semester->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="by" class="sm:max-w-44">
            <flux:select.option value="class">{{ __('By class') }}</flux:select.option>
            <flux:select.option value="teacher">{{ __('By muhadhir') }}</flux:select.option>
            <flux:select.option value="room">{{ __('By room') }}</flux:select.option>
        </flux:select>
        <flux:select wire:model.live="value" class="sm:max-w-64">
            @forelse ($this->choices as $key => $label)
                <flux:select.option :value="$key">{{ $label }}</flux:select.option>
            @empty
                <flux:select.option value="">{{ __('— none —') }}</flux:select.option>
            @endforelse
        </flux:select>
    </div>

    <flux:heading class="mb-3 hidden print:block">{{ $this->semesters->firstWhere('id', $semesterId)?->label() }} · {{ $this->choices[$value] ?? '' }}</flux:heading>

    <x-timetable :offerings="$this->offerings" :show="match ($by) { 'class' => ['teacher', 'room'], 'teacher' => ['class', 'room'], default => ['class', 'teacher'] }" />
</section>
