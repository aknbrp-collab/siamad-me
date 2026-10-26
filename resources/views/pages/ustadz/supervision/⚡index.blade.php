<?php

use App\Actions\Teaching\AcademicRecord;
use App\Models\ClassEnrollment;
use App\Models\CourseEnrollment;
use App\Models\Semester;
use App\Models\Setting;
use App\Models\Supervision;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('My supervisees')] class extends Component {
    /**
     * Mahasantri this muhadhir supervises now.
     *
     * @return Collection<int, Supervision>
     */
    #[Computed]
    public function supervisions(): Collection
    {
        return Supervision::query()->current()
            ->with('student.user')
            ->where('teacher_id', auth()->user()->teacher?->id)
            ->get()
            ->sortBy('student.user.name')
            ->values();
    }

    /**
     * Class (active semester), attendance this semester, IPK and credits, by student id.
     *
     * @return array<int, array{class: string|null, attendance: int|null, ipk: float|null, credits: int}>
     */
    #[Computed]
    public function progress(): array
    {
        $studentIds = $this->supervisions->pluck('student_id')->all();
        $semester = Semester::active();
        $record = app(AcademicRecord::class);

        $classes = $semester === null ? collect() : ClassEnrollment::query()
            ->where('semester_id', $semester->id)
            ->whereIn('student_id', $studentIds)
            ->pluck('class_name', 'student_id');

        $enrollments = $semester === null ? collect() : CourseEnrollment::query()
            ->whereIn('student_id', $studentIds)
            ->whereHas('courseOffering', fn ($query) => $query->where('semester_id', $semester->id))
            ->get(['id', 'student_id']);

        $attendance = $record->attendance(array_values($enrollments->pluck('id')->all()));

        $progress = [];

        foreach ($this->supervisions as $supervision) {
            $mine = $enrollments->where('student_id', $supervision->student_id)->pluck('id');
            $present = $mine->sum(fn (int $id) => $attendance[$id]['hadir'] ?? 0);
            $total = $mine->sum(fn (int $id) => $attendance[$id]['total'] ?? 0);
            $transcript = $record->transcript($supervision->student);

            $progress[$supervision->student_id] = [
                'class' => $classes[$supervision->student_id] ?? null,
                'attendance' => $total > 0 ? (int) round($present / $total * 100) : null,
                'ipk' => $transcript['ipk'],
                'credits' => $transcript['credits'],
            ];
        }

        return $progress;
    }

    #[Computed]
    public function minimum(): int
    {
        return (int) Setting::get('attendance.min_percent', 75);
    }
}; ?>

<section class="w-full">
    <x-page-header :title="__('My supervisees')" :subtitle="__('Mahasantri you supervise as pembimbing akademik: their class, attendance this semester and IPK.')">
        <x-slot:actions>
            <flux:button icon="chat-bubble-left-right" :href="route('ustadz.supervision.forum')" wire:navigate>{{ __('Group forum') }}</flux:button>
        </x-slot:actions>
    </x-page-header>

    <div class="overflow-x-auto">
        <flux:table class="table-stack">
            <flux:table.columns>
                <flux:table.column>{{ __('Mahasantri') }}</flux:table.column>
                <flux:table.column>{{ __('Class') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Attendance') }}</flux:table.column>
                <flux:table.column align="end">{{ __('IPK') }}</flux:table.column>
                <flux:table.column align="end">{{ __('SKS') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->supervisions as $supervision)
                    @php($row = $this->progress[$supervision->student_id])
                    <flux:table.row :key="$supervision->id">
                        <flux:table.cell>
                            <flux:link :href="route('ustadz.supervision.student', $supervision->student)" wire:navigate class="font-medium">{{ $supervision->student->user->name }}</flux:link>
                            <span class="ms-1 font-mono text-xs text-zinc-500">{{ $supervision->student->nim }}</span>
                        </flux:table.cell>
                        <flux:table.cell>{{ $row['class'] ?? '—' }}</flux:table.cell>
                        <flux:table.cell align="end">
                            @if ($row['attendance'] !== null)
                                <flux:badge size="sm" :color="$row['attendance'] < $this->minimum ? 'red' : 'green'">{{ $row['attendance'] }}%</flux:badge>
                            @else
                                —
                            @endif
                        </flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $row['ipk'] !== null ? number_format($row['ipk'], 2) : '—' }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $row['credits'] }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5" class="text-center">{{ __('No mahasantri are assigned to you as pembimbing akademik.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
</section>
