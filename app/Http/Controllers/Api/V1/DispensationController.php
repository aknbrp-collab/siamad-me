<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Exams\ManageDispensationRequests;
use App\Http\Controllers\Controller;
use App\Models\DispensationRequest;
use App\Models\Student;
use App\Support\ActiveRole;
use App\Support\ApiFiles;
use App\Support\StudentAccess;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Surat permohonan dispensasi (v7 D47): a mahasantri whose bill of the active semester is not paid off
 * writes why and prints the letter for the Mudir to sign; Admin grants it as an exam dispensation.
 */
#[Group('Records', weight: 8)]
class DispensationController extends Controller
{
    /**
     * Dispensation request.
     *
     * The request of the active semester (the mahasantri's own, a wali's child, academic and finance
     * staff): whether one may be written (`can_request`, the semester bill is not paid off), the request
     * with a signed link to the letter (`letter_url`), and whether Admin granted it.
     */
    public function show(Request $request, Student $student, ManageDispensationRequests $requests): JsonResponse
    {
        abort_unless(StudentAccess::canViewBills($request->user(), ActiveRole::get(), $student), 403);

        return response()->json(['data' => $this->present($request, $student, $requests)]);
    }

    /**
     * Write a dispensation request.
     *
     * The mahasantri's `reason` (10 to 1000 characters) for the active semester; writing again replaces
     * it. Refused (422) when the semester bill is paid off. Admin is told about a new request.
     */
    public function store(Request $request, ManageDispensationRequests $requests): JsonResponse
    {
        $request->validate(['reason' => ['required', 'string']]);
        $student = $request->user()->student;
        abort_if($student === null, 403);

        $requests->submit($student, (string) $request->input('reason'), $request->user());

        return response()->json(['data' => $this->present($request, $student, $requests)]);
    }

    /**
     * @return array{can_request: bool, request: array<string, mixed>|null}
     */
    private function present(Request $request, Student $student, ManageDispensationRequests $requests): array
    {
        $current = $requests->current($student);

        return [
            'can_request' => $requests->openSemester($student) !== null,
            'request' => $current === null ? null : [
                'id' => $current->id,
                'semester_id' => $current->semester_id,
                'reason' => $current->reason,
                /** Still to pay over all bills when the request was written (rupiah). */
                'outstanding' => $current->outstanding,
                'granted' => $requests->granted($current),
                'written_at' => $current->updated_at->toIso8601String(),
                'letter_url' => self::letterUrl($request, $current),
            ],
        ];
    }

    /**
     * A signed link to the letter PDF, for the app.
     */
    public static function letterUrl(Request $request, DispensationRequest $dispensation): string
    {
        return ApiFiles::download($request->user(), ActiveRole::get(), 'dispensation-letter', ['dispensation' => $dispensation->id]);
    }
}
