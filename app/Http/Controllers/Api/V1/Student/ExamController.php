<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Actions\Exams\ExamEligibility;
use App\Http\Controllers\Api\V1\Student\Concerns\ResolvesStudent;
use App\Http\Controllers\Controller;
use App\Models\ExamPeriod;
use App\Models\Semester;
use App\Support\ActiveRole;
use App\Support\ApiFiles;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Mahasantri', weight: 9)]
class ExamController extends Controller
{
    use ResolvesStudent;

    /**
     * Exams and exam card.
     *
     * Published exam periods of this semester with the schedule, my seat, and whether I may sit them
     * (bills paid or a dispensation, enough attendance per subject). `card_url` (PDF) only when eligible.
     */
    public function index(Request $request, ExamEligibility $eligibility): JsonResponse
    {
        $student = $this->student($request);
        $periods = ExamPeriod::query()->where('semester_id', Semester::active()?->id)->whereNotNull('published_at')->orderBy('starts_on')->get();

        return response()->json(['data' => $periods->map(function (ExamPeriod $period) use ($eligibility, $student, $request) {
            $status = $eligibility->check($period, $student);
            $status['seat']?->loadMissing('room');

            return [
                'id' => $period->id,
                'label' => $period->label(),
                'type' => $period->type->value,
                'starts_on' => $period->starts_on->toDateString(),
                'ends_on' => $period->ends_on->toDateString(),
                'eligible' => $status['eligible'],
                'payment_ok' => $status['payment_ok'],
                'outstanding' => $status['outstanding'],
                'dispensation' => $status['dispensation'] === null ? null : [
                    'letter_number' => $status['dispensation']->letter_number,
                    'note' => $status['dispensation']->note,
                ],
                'seat' => $status['seat'] === null ? null : ['room' => $status['seat']->room->name, 'number' => $status['seat']->seat_number],
                'subjects' => array_map(fn (array $subject) => [
                    'subject_name' => $subject['session']->courseOffering->subject_name,
                    'held_on' => $subject['session']->held_on->toDateString(),
                    'starts_at' => substr($subject['session']->starts_at, 0, 5),
                    'ends_at' => substr($subject['session']->ends_at, 0, 5),
                    'attendance_percent' => $subject['attendance'],
                    'ok' => $subject['ok'],
                ], $status['subjects']),
                'card_url' => $status['eligible'] ? ApiFiles::download($request->user(), ActiveRole::get(), 'exam-card', ['period' => $period->id, 'student' => $student->id]) : null,
            ];
        })->values()->all()]);
    }
}
