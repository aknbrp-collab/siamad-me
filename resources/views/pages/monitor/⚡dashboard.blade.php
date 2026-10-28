<?php

use App\Actions\Teaching\AcademicRecord;
use App\Actions\Teaching\ManageGrades;
use App\Enums\Role;
use App\Models\Assignment;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\Semester;
use App\Models\Setting;
use App\Support\ActiveRole;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Academic monitor')] class extends Component {
    #[Url(as: 'semester')]
    public ?int $semesterId = null;

    public ?int $unlockingId = null;
    public string $unlockReason = '';

    public function mount(): void
    {
        $this->semesterId ??= Semester::active()?->id ?? Semester::query()->orderByDesc('id')->value('id');
    }

    #[Computed]
    public function canUnlock(): bool
    {
        return in_array(ActiveRole::get(), [Role::Admin, Role::Superadmin], true);
    }

    #[Computed]
    public function minimum(): int
    {
        return (int) Setting::get('attendance.min_percent', 75);
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
     * @return Collection<int, CourseOffering>
     */
    #[Computed]
    public function offerings(): Collection
    {
        return CourseOffering::query()
            ->with(['semester', 'classGroup', 'teacher.user', 'enrollments.student.user'])
            ->withCount(['sessions as meetings_count' => fn ($query) => $query->whereNotNull('attendance_taken_at'), 'components'])
            ->where('semester_id', $this->semesterId)
            ->orderBy('class_group_id')->orderBy('subject_code')
            ->get();
    }

    /**
     * @return array<int, array{hadir: int, izin: int, sakit: int, alpa: int, total: int, percent: int|null}>
     */
    #[Computed]
    public function attendance(): array
    {
        return app(AcademicRecord::class)->attendance(
            $this->offerings->flatMap(fn (CourseOffering $offering) => $offering->enrollments->modelKeys())->values()->all(),
        );
    }

    /**
     * Per offering: average attendance and how many are below the minimum.
     *
     * @return array<int, array{average: int|null, below: int}>
     */
    #[Computed]
    public function offeringStats(): array
    {
        $stats = [];

        foreach ($this->offerings as $offering) {
            $percents = $offering->enrollments
                ->map(fn (CourseEnrollment $enrollment) => $this->attendance[$enrollment->id]['percent'] ?? null)
                ->filter(fn ($percent) => $percent !== null);

            $stats[$offering->id] = [
                'average' => $percents->isEmpty() ? null : (int) round($percents->avg()),
                'below' => $percents->filter(fn (int $percent) => $percent < $this->minimum)->count(),
            ];
        }

        return $stats;
    }

    /**
     * Submissions still missing for assignments past their due date, per offering.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function missingWork(): array
    {
        $missing = [];

        $assignments = Assignment::query()
            ->whereIn('course_offering_id', $this->offerings->modelKeys())
            ->where('due_at', '<', now())
            ->withCount('submissions')
            ->get();

        foreach ($assignments as $assignment) {
            $enrolled = $this->offerings->firstWhere('id', $assignment->course_offering_id)?->enrollments->count() ?? 0;
            $missing[$assignment->course_offering_id] = ($missing[$assignment->course_offering_id] ?? 0) + max(0, $enrolled - $assignment->submissions_count);
        }

        return $missing;
    }

    /**
     * Mahasantri under the attendance minimum, lowest first.
     *
     * @return \Illuminate\Support\Collection<int, array{name: string, nim: string, class: string, subject: string, percent: int}>
     */
    #[Computed]
    public function belowMinimum(): \Illuminate\Support\Collection
    {
        return $this->offerings
            ->flatMap(fn (CourseOffering $offering) => $offering->enrollments->map(fn (CourseEnrollment $enrollment) => [
                'name' => $enrollment->student->user->name,
                'nim' => $enrollment->student->nim,
                'class' => $offering->class_name,
                'subject' => $offering->subject_name,
                'percent' => $this->attendance[$enrollment->id]['percent'] ?? null,
            ]))
            ->filter(fn (array $row) => $row['percent'] !== null && $row['percent'] < $this->minimum)
            ->sortBy('percent')
            ->values();
    }

    /**
     * @return array<string, int> letter => count, over finalised grades this semester
     */
    #[Computed]
    public function distribution(): array
    {
        return $this->offerings
            ->flatMap(fn (CourseOffering $offering) => $offering->enrollments)
            ->whereNotNull('final_letter')
            ->countBy('final_letter')
            ->sortKeys()
            ->all();
    }

    public function openUnlock(int $offeringId): void
    {
        abort_unless($this->canUnlock, 403);

        $this->unlockingId = $offeringId;
        $this->unlockReason = '';
        $this->resetErrorBag();
        Flux::modal('unlock-grades')->show();
    }

    public function unlock(ManageGrades $grades): void
    {
        abort_unless($this->canUnlock, 403);
        $this->resetErrorBag();

        $grades->unlock(CourseOffering::query()->where('semester_id', $this->semesterId)->findOrFail($this->unlockingId), $this->unlockReason);

        Flux::modal('unlock-grades')->close();
        unset($this->offerings, $this->distribution);
        Flux::toast(variant: 'warning', text: __('Grades unlocked. The ustadz can correct and finalise them again.'));
    }
}; ?>

<section class="w-full space-y-8">
    <x-page-header :title="__('Academic monitor')" :subtitle="ActiveRole::get()?->label().' · '.__('attendance and grade progress')">
        <x-slot:actions>
            <flux:select wire:model.live="semesterId" class="w-60">
                @foreach ($this->semesters as $semester)
                    <flux:select.option :value="$semester->id">{{ $semester->label() }}</flux:select.option>
                @endforeach
            </flux:select>
        </x-slot:actions>
    </x-page-header>

    @php
        $finalized = $this->offerings->filter(fn ($offering) => $offering->isFinalized())->count();
    @endphp

    <div class="grid gap-4 sm:grid-cols-3">
        <flux:card>
            <flux:text class="text-sm">{{ __('Course offerings') }}</flux:text>
            <div class="text-2xl font-semibold">{{ $this->offerings->count() }}</div>
        </flux:card>
        <flux:card>
            <flux:text class="text-sm">{{ __('Grades finalised') }}</flux:text>
            <div class="text-2xl font-semibold">{{ $finalized }} / {{ $this->offerings->count() }}</div>
        </flux:card>
        <flux:card>
            <flux:text class="text-sm">{{ __('Below :min% attendance', ['min' => $this->minimum]) }}</flux:text>
            <div @class(['text-2xl font-semibold', 'text-red-600' => $this->belowMinimum->isNotEmpty()])>{{ $this->belowMinimum->count() }}</div>
        </flux:card>
    </div>

    @error('grades')
        <flux:callout variant="danger" icon="exclamation-triangle" :text="$message" />
    @enderror

    <div>
        <flux:heading size="lg" class="mb-3">{{ __('Offerings') }}</flux:heading>
        <div class="overflow-x-auto">
            <flux:table class="table-stack">
                <flux:table.columns>
                    <flux:table.column>{{ __('Subject') }}</flux:table.column>
                    <flux:table.column class="hidden md:table-cell">{{ __('Ustadz') }}</flux:table.column>
                    <flux:table.column align="center">{{ __('Meetings') }}</flux:table.column>
                    <flux:table.column align="center">{{ __('Attendance') }}</flux:table.column>
                    <flux:table.column align="center" class="hidden sm:table-cell">{{ __('Missing work') }}</flux:table.column>
                    <flux:table.column>{{ __('Grades') }}</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($this->offerings as $offering)
                        @php($stat = $this->offeringStats[$offering->id])
                        <flux:table.row :key="$offering->id">
                            <flux:table.cell class="whitespace-normal">
                                <span class="font-medium">{{ $offering->subject_name }}</span>
                                <div class="text-xs text-zinc-500">{{ $offering->subject_code }} · {{ $offering->class_name }}</div>
                            </flux:table.cell>
                            <flux:table.cell class="hidden md:table-cell">{{ $offering->teacher->displayName() }}</flux:table.cell>
                            <flux:table.cell align="center">{{ $offering->meetings_count }}</flux:table.cell>
                            <flux:table.cell align="center">
                                @if ($stat['average'] !== null)
                                    <flux:badge size="sm" :color="$stat['average'] < $this->minimum ? 'red' : 'green'">{{ $stat['average'] }}%</flux:badge>
                                    @if ($stat['below'] > 0)
                                        <div class="text-xs text-red-600">{{ trans_choice(':count below|:count below', $stat['below']) }}</div>
                                    @endif
                                @else
                                    <span class="text-zinc-400">—</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell align="center" class="hidden sm:table-cell">
                                @php($missing = $this->missingWork[$offering->id] ?? 0)
                                @if ($missing > 0)
                                    <flux:badge size="sm" color="amber">{{ $missing }}</flux:badge>
                                @else
                                    <span class="text-zinc-400">—</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                @if ($offering->isFinalized())
                                    <flux:badge size="sm" color="green">{{ __('Final') }}</flux:badge>
                                @elseif ($offering->components_count === 0)
                                    <flux:badge size="sm" color="amber">{{ __('No components') }}</flux:badge>
                                @else
                                    <flux:badge size="sm">{{ __('In progress') }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell align="end">
                                @if ($this->canUnlock && $offering->isFinalized() && ! $offering->semester->isReadOnly())
                                    <flux:button size="sm" variant="ghost" icon="lock-open" wire:click="openUnlock({{ $offering->id }})">{{ __('Unlock') }}</flux:button>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="7" class="text-center">{{ __('No course offerings in this semester.') }}</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    </div>

    <div class="grid gap-8 xl:grid-cols-2">
        <div>
            <flux:heading size="lg" class="mb-3">{{ __('Below :min% attendance', ['min' => $this->minimum]) }}</flux:heading>
            <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                @forelse ($this->belowMinimum as $row)
                    <div wire:key="below-{{ $row['nim'] }}-{{ $loop->index }}" class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                        <span>
                            <span class="font-medium">{{ $row['name'] }}</span>
                            <span class="text-zinc-500">· {{ $row['class'] }} · {{ $row['subject'] }}</span>
                        </span>
                        <flux:badge size="sm" color="red">{{ $row['percent'] }}%</flux:badge>
                    </div>
                @empty
                    <flux:text class="p-3 text-sm">{{ __('Everyone is above the minimum.') }}</flux:text>
                @endforelse
            </div>
        </div>

        <div>
            <flux:heading size="lg" class="mb-3">{{ __('Grade distribution (finalised)') }}</flux:heading>
            @php($maxCount = max([1, ...array_values($this->distribution)]))
            <div class="space-y-2">
                @forelse ($this->distribution as $letter => $count)
                    <div class="flex items-center gap-3 text-sm">
                        <span class="w-8 font-medium">{{ $letter }}</span>
                        <div class="h-3 flex-1 rounded bg-zinc-100 dark:bg-zinc-700">
                            <div class="h-3 rounded bg-zinc-800 dark:bg-zinc-200" style="width: {{ round($count / $maxCount * 100) }}%"></div>
                        </div>
                        <span class="w-8 text-end tabular-nums">{{ $count }}</span>
                    </div>
                @empty
                    <flux:text class="text-sm">{{ __('No finalised grades yet.') }}</flux:text>
                @endforelse
            </div>
        </div>
    </div>

    <flux:modal name="unlock-grades" class="w-full max-w-md">
        <form wire:submit="unlock" class="space-y-6">
            <flux:heading size="lg">{{ __('Unlock grades') }}</flux:heading>
            <flux:text>{{ __('The final grades of this offering are cleared so the ustadz can correct and finalise them again. The reason is kept.') }}</flux:text>
            <flux:textarea wire:model="unlockReason" :label="__('Reason')" rows="2" required />
            <flux:error name="unlockReason" />
            <flux:error name="semester" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="danger">{{ __('Unlock') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
