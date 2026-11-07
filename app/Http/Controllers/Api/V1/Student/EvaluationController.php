<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Actions\Evaluations\ManageEvaluations;
use App\Http\Controllers\Api\V1\Student\Concerns\ResolvesStudent;
use App\Http\Controllers\Controller;
use App\Models\CourseOffering;
use App\Models\EvaluationQuestion;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Evaluasi muhadhir (v6 D35): rate each class kuliah once, anonymously, while
 * the period is open.
 */
#[Group('Mahasantri', weight: 9)]
class EvaluationController extends Controller
{
    use ResolvesStudent;

    /**
     * Classes to evaluate.
     *
     * Classes in semesters whose evaluation is open, whether each is done, and the statements to score
     * (1 = strongly disagree … 5 = strongly agree) with the closing question.
     */
    public function index(Request $request, ManageEvaluations $evaluations): JsonResponse
    {
        $rows = $evaluations->forStudent($this->student($request));
        $semesterIds = $rows->map(fn (array $row) => $row['offering']->semester_id)->unique()->values()->all();
        $questions = EvaluationQuestion::query()->whereIn('semester_id', $semesterIds)->where('is_active', true)->orderBy('position')->orderBy('id')->get()->groupBy('semester_id');

        return response()->json(['data' => $rows->map(fn (array $row) => [
            'class_id' => $row['offering']->id,
            'subject_name' => $row['offering']->subject_name,
            'teacher' => $row['offering']->teacher->user->name,
            'done' => $row['done'],
            'closes_on' => $row['period']->closes_on->toDateString(),
            'comment_prompt' => $row['period']->comment_prompt,
            'statements' => ($questions[$row['offering']->semester_id] ?? collect())
                ->map(fn (EvaluationQuestion $question) => ['id' => $question->id, 'group' => $question->group, 'statement' => $question->question])->values()->all(),
        ])->values()->all()]);
    }

    /**
     * Evaluate a class.
     *
     * `scores`: statement id => 1–5 for every statement; `comment` optional. Stored anonymously (only
     * totals are kept); a class can be evaluated once.
     */
    public function store(Request $request, CourseOffering $offering, ManageEvaluations $evaluations): Response
    {
        $request->validate(['scores' => ['required', 'array'], 'comment' => ['nullable', 'string', 'max:2000']]);

        $evaluations->submit($this->student($request), $offering, (array) $request->input('scores'), $request->input('comment'));

        return response()->noContent();
    }
}
