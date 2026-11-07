<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Actions\Teaching\ImportScores;
use App\Actions\Teaching\ManageGrades;
use App\Http\Controllers\Api\V1\Teacher\Concerns\TeachesOffering;
use App\Http\Controllers\Controller;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\GradeComponent;
use App\Models\GradeScale;
use App\Support\ActiveRole;
use App\Support\ApiFiles;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Nilai: grade components (weights add up to 100), scores per mahasantri,
 * the Excel import and finalising (the grades then appear on the KHS).
 */
#[Group('Muhadhir', weight: 11)]
class GradeController extends Controller
{
    use TeachesOffering;

    /**
     * Grade sheet.
     *
     * Components, each mahasantri's scores (0–100 per component) with the weighted final score and letter
     * (null while a score is missing), whether the sheet can still be changed, and the import template link.
     */
    public function show(Request $request, CourseOffering $offering, ManageGrades $grades): JsonResponse
    {
        $this->authorizeOffering($request, $offering);
        $offering->loadMissing('semester');
        $components = $offering->components()->get();
        $scale = GradeScale::ordered();

        $enrollments = $offering->enrollments()->with(['student.user', 'scores'])->get()->sortBy('student.user.name')->values();

        return response()->json(['data' => [
            'finalized' => $offering->isFinalized(),
            'editable' => ! $offering->semester->isLocked() && ! $offering->isFinalized(),
            'components' => $components->map(fn (GradeComponent $component) => ['id' => $component->id, 'name' => $component->name, 'weight' => $component->weight])->values()->all(),
            'mahasantri' => $enrollments->map(function (CourseEnrollment $enrollment) use ($components, $grades, $scale) {
                $final = $grades->finalScore($enrollment, $components);

                return [
                    'enrollment_id' => $enrollment->id,
                    'nim' => $enrollment->student->nim,
                    'name' => $enrollment->student->user->name,
                    /** {component id: score} */
                    'scores' => $enrollment->scores->mapWithKeys(fn ($score) => [$score->grade_component_id => $score->score])->all(),
                    'final_score' => $final,
                    'final_letter' => $final === null ? null : GradeScale::resolve($final, $scale)['letter'],
                ];
            })->all(),
            'import_template_url' => ApiFiles::download($request->user(), ActiveRole::get(), 'score-template', ['offering' => $offering->id]),
        ]]);
    }

    /**
     * Save the components.
     *
     * `components`: [{id?, name, weight}] in order; weights add up to 100. A component with scores cannot be
     * removed.
     */
    public function components(Request $request, CourseOffering $offering, ManageGrades $grades): JsonResponse
    {
        $this->authorizeOffering($request, $offering);
        $request->validate(['components' => ['required', 'array'], 'components.*.name' => ['required', 'string'], 'components.*.weight' => ['required', 'integer']]);

        $grades->saveComponents($offering, array_values((array) $request->input('components')));

        return $this->show($request, $offering, $grades);
    }

    /**
     * Save scores.
     *
     * `scores`: {enrollment id: {component id: score}}; an empty value removes a score.
     */
    public function scores(Request $request, CourseOffering $offering, ManageGrades $grades): JsonResponse
    {
        $this->authorizeOffering($request, $offering);
        $request->validate(['scores' => ['required', 'array']]);

        $grades->saveScores($offering, (array) $request->input('scores'));

        return $this->show($request, $offering, $grades);
    }

    /**
     * Import scores from Excel.
     *
     * Multipart `file` (the template filled in, xlsx or csv). Without `apply` it only checks each row;
     * with `apply=1` it saves the scores and returns how many mahasantri changed.
     */
    public function import(Request $request, CourseOffering $offering, ImportScores $import): JsonResponse
    {
        $this->authorizeOffering($request, $offering);
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:2048'], 'apply' => ['nullable', 'boolean']]);

        $file = $request->file('file');
        $extension = strtolower((string) $file?->getClientOriginalExtension()) === 'csv' ? 'csv' : 'xlsx';
        $path = (string) $file?->getRealPath();

        if ($request->boolean('apply')) {
            return response()->json(['data' => ['changed' => $import->apply($offering, $path, $extension)]]);
        }

        return response()->json(['data' => ['rows' => array_map(
            fn (array $row) => ['row' => $row['row'], 'nim' => $row['nim'], 'name' => $row['name'], 'status' => $row['status'], 'errors' => $row['errors']],
            $import->preview($offering, $path, $extension),
        )]]);
    }

    /**
     * Finalise the grades.
     *
     * Every mahasantri needs every score. The grades then appear on the KHS; only the Admin can reopen them.
     */
    public function finalize(Request $request, CourseOffering $offering, ManageGrades $grades): JsonResponse
    {
        $this->authorizeOffering($request, $offering);
        $grades->finalize($offering, $request->user());

        return $this->show($request, $offering->refresh(), $grades);
    }
}
