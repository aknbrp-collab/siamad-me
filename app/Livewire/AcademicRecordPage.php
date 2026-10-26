<?php

namespace App\Livewire;

use App\Actions\Teaching\AcademicRecord;
use App\Models\Semester;
use App\Models\Setting;
use App\Models\Student;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Shared logic of the pages showing one mahasantri's KHS, attendance and
 * transcript (mahasantri, wali santri and admin views).
 *
 * @property-read Collection<int, Semester> $semesters
 * @property-read Semester|null $semester
 * @property-read array{rows: \Illuminate\Support\Collection<int, array{enrollment_id: int, code: string, name: string, credits: int, score: string|null, letter: string|null, point: string|null, final: bool}>, credits: int, quality: float, ip: float|null}|null $khs
 */
abstract class AcademicRecordPage extends Component
{
    #[Url(as: 'semester')]
    public ?int $semesterId = null;

    abstract protected function student(): ?Student;

    /**
     * Semesters in which the mahasantri was enrolled in a class, newest first
     * (history stays reachable).
     *
     * @return Collection<int, Semester>
     */
    #[Computed]
    public function semesters(): Collection
    {
        $student = $this->student();

        if ($student === null) {
            return new Collection;
        }

        return Semester::query()
            ->with('academicYear')
            ->whereHas('classEnrollments', fn ($query) => $query->where('student_id', $student->id))
            ->orderByDesc('academic_year_id')->orderByDesc('term')
            ->get();
    }

    #[Computed]
    public function semester(): ?Semester
    {
        return $this->semesters->firstWhere('id', $this->semesterId)
            ?? $this->semesters->firstWhere('status.value', 'active')
            ?? $this->semesters->first();
    }

    /**
     * @return array{rows: \Illuminate\Support\Collection<int, array{enrollment_id: int, code: string, name: string, credits: int, score: string|null, letter: string|null, point: string|null, final: bool}>, credits: int, quality: float, ip: float|null}|null
     */
    #[Computed]
    public function khs(): ?array
    {
        $student = $this->student();

        return $student && $this->semester ? app(AcademicRecord::class)->khs($student, $this->semester) : null;
    }

    /**
     * @return array<int, array{hadir: int, izin: int, sakit: int, alpa: int, total: int, percent: int|null}>
     */
    #[Computed]
    public function attendance(): array
    {
        return $this->khs === null ? [] : app(AcademicRecord::class)->attendance(
            array_values($this->khs['rows']->map(fn (array $row) => $row['enrollment_id'])->all()),
        );
    }

    #[Computed]
    public function minimum(): int
    {
        return (int) Setting::get('attendance.min_percent', 75);
    }
}
