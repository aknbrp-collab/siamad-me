<?php

namespace App\Livewire\Concerns;

use App\Models\Semester;
use App\Models\Student;
use App\Support\StudentPicker;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;

/**
 * A mahasantri picker on a page (v7 D43): all active mahasantri at once, filtered by class, Putra/Putri and a
 * NIM or name search, 50 at a time. The page shows the rows with its own action and puts
 * `<x-student-picker-filters>` above them and `<x-student-picker-more>` below.
 */
trait PicksStudents
{
    public string $studentSearch = '';

    public string $pickerClass = '';

    public string $pickerGender = '';

    public int $pickerLimit = StudentPicker::PAGE;

    /** How many mahasantri match, for "50 of 312"; set when the list is read. */
    public int $pickerTotal = 0;

    /**
     * The semester whose classes the class filter means (the active one unless the page says otherwise).
     */
    protected function pickerSemester(): ?Semester
    {
        return Semester::active();
    }

    /**
     * The gender every row must have whatever the filter says (a Putra class only takes putra).
     */
    protected function pickerFixedGender(): ?string
    {
        return null;
    }

    /**
     * The mahasantri matching the filters, a page at a time.
     *
     * @param  (Closure(Builder<Student>): mixed)|null  $scope  the page's own rule, e.g. not yet in the group
     * @return Collection<int, Student>
     */
    protected function pickStudents(?Closure $scope = null, bool $activeOnly = true): Collection
    {
        $semester = $this->pickerSemester();
        $query = StudentPicker::filter(
            StudentPicker::query($semester),
            $this->studentSearch,
            $this->pickerClass,
            $this->pickerFixedGender() ?? $this->pickerGender,
            $semester,
            $activeOnly,
        );

        if ($scope !== null) {
            $scope($query);
        }

        $this->pickerTotal = (clone $query)->count();

        return $query->orderBy('nim')->limit(max(StudentPicker::PAGE, $this->pickerLimit))->get();
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    #[Computed]
    public function pickerClasses(): array
    {
        return StudentPicker::classes($this->pickerSemester(), $this->pickerFixedGender());
    }

    public function morePickerStudents(): void
    {
        $this->pickerLimit += StudentPicker::PAGE;
    }

    /**
     * Back to the first page whenever a filter changes.
     */
    public function updatedStudentSearch(): void
    {
        $this->pickerLimit = StudentPicker::PAGE;
    }

    public function updatedPickerClass(): void
    {
        $this->pickerLimit = StudentPicker::PAGE;
    }

    public function updatedPickerGender(): void
    {
        $this->pickerLimit = StudentPicker::PAGE;
    }

    protected function resetStudentPicker(): void
    {
        $this->reset('studentSearch', 'pickerClass', 'pickerGender', 'pickerLimit');
    }
}
