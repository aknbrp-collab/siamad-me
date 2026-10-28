<?php

namespace App\Actions\Teaching;

use App\Imports\Importers;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\GradeComponent;
use App\Support\SpreadsheetDownload;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Score entry from Excel: the teacher downloads a template pre-filled with
 * the enrolled mahasantri and one column per component, fills it offline and
 * uploads it. Blank cells leave the score unchanged.
 */
class ImportScores
{
    public function __construct(private readonly ManageGrades $grades) {}

    public function template(CourseOffering $offering): BinaryFileResponse
    {
        $components = $offering->components()->get();
        $scores = $this->currentScores($offering);

        $rows = $this->enrollments($offering)->map(function (CourseEnrollment $enrollment) use ($components, $scores) {
            $row = ['nim' => $enrollment->student->nim, 'name' => $enrollment->student->user->name];

            foreach ($components as $component) {
                $row[$component->name] = $scores[$enrollment->id][$component->id] ?? '';
            }

            return $row;
        });

        $name = Str::slug($offering->subject_code.' '.$offering->class_name);

        return SpreadsheetDownload::make("scores-{$name}.xlsx", $rows);
    }

    /**
     * Compare the upload with the stored scores.
     *
     * @return list<array{row: int, nim: string, name: string, enrollment_id: int|null, status: string, values: array<int, float>, errors: list<string>}>
     */
    public function preview(CourseOffering $offering, string $path, string $extension): array
    {
        $this->grades->ensureEditable($offering);

        $components = $offering->components()->get();

        if ($components->isEmpty()) {
            throw ValidationException::withMessages(['file' => __('Set the grade components before importing scores.')]);
        }

        $rows = Importers::read($path, $extension);

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => __('The file has no data rows.')]);
        }

        $columns = $components->mapWithKeys(fn (GradeComponent $component) => [Str::snake(trim($component->name)) => $component]);
        $unknown = array_diff(array_keys($rows[0]), ['nim', 'name', ...$columns->keys()->all()]);

        if ($unknown !== []) {
            throw ValidationException::withMessages(['file' => __('Unknown columns: :columns. Use the downloaded template.', ['columns' => implode(', ', $unknown)])]);
        }

        $enrollments = $this->enrollments($offering)->keyBy(fn (CourseEnrollment $enrollment) => Str::lower($enrollment->student->nim));
        $current = $this->currentScores($offering);
        $result = [];

        foreach ($rows as $index => $row) {
            $nim = trim($row['nim'] ?? '');
            $enrollment = $enrollments->get(Str::lower($nim));
            $values = [];
            $errors = [];

            if ($enrollment === null) {
                $errors[] = __('NIM :nim is not enrolled in this offering.', ['nim' => $nim ?: '—']);
            }

            foreach ($columns as $key => $component) {
                $cell = $row[$key] ?? '';

                if (trim($cell) === '') {
                    continue;
                }

                $score = $this->grades->parseScore($cell);

                if ($score === null) {
                    $errors[] = __(':component: ":value" is not a score from 0 to 100.', ['component' => $component->name, 'value' => $cell]);
                } else {
                    $values[$component->id] = $score;
                }
            }

            $changed = $enrollment !== null && collect($values)
                ->contains(fn (float $score, int $componentId) => (float) ($current[$enrollment->id][$componentId] ?? -1) !== $score);

            $result[] = [
                'row' => $index + 2,
                'nim' => $nim,
                'name' => $enrollment?->student->user->name ?? ($row['name'] ?? ''),
                'enrollment_id' => $enrollment?->id,
                'status' => match (true) {
                    $errors !== [] => 'error',
                    $values === [] || ! $changed => 'unchanged',
                    collect($current[$enrollment->id] ?? [])->isEmpty() => 'new',
                    default => 'changed',
                },
                'values' => $values,
                'errors' => $errors,
            ];
        }

        return $result;
    }

    /**
     * Apply the rows that are new or changed. Returns how many mahasantri changed.
     */
    public function apply(CourseOffering $offering, string $path, string $extension): int
    {
        $rows = array_filter(
            $this->preview($offering, $path, $extension),
            fn (array $row) => in_array($row['status'], ['new', 'changed'], true),
        );

        $this->grades->saveScores($offering, array_column($rows, 'values', 'enrollment_id'));

        return count($rows);
    }

    /**
     * @return Collection<int, CourseEnrollment>
     */
    private function enrollments(CourseOffering $offering): Collection
    {
        return $offering->enrollments()->with('student.user')->get()->sortBy('student.nim')->values();
    }

    /**
     * @return array<int, array<int, string>> enrollment id => component id => score
     */
    private function currentScores(CourseOffering $offering): array
    {
        $current = [];

        foreach ($offering->enrollments()->with('scores')->get() as $enrollment) {
            foreach ($enrollment->scores as $score) {
                $current[$enrollment->id][$score->grade_component_id] = $score->score;
            }
        }

        return $current;
    }
}
