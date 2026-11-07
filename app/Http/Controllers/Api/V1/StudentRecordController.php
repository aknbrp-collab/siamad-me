<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Evaluations\ManageEvaluations;
use App\Actions\Finance\ConfirmTransfer;
use App\Actions\Teaching\AcademicRecord;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\BillResource;
use App\Models\ActivityLog;
use App\Models\Bill;
use App\Models\Semester;
use App\Models\Setting;
use App\Models\Student;
use App\Support\ActiveRole;
use App\Support\ApiFiles;
use App\Support\StudentAccess;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One mahasantri's records: KHS, transcript, bills and pesantren activities.
 * The mahasantri reads their own (their student id is in GET /me), a wali
 * santri their children's (GET /guardian/children).
 */
#[Group('Records', weight: 8)]
class StudentRecordController extends Controller
{
    /**
     * KHS.
     *
     * Grades of one semester (`?semester=`; default the active one, else the latest) with attendance per
     * subject, the IPS and the semesters to choose from. `waits_for_evaluation`: the mahasantri must first
     * evaluate their muhadhir (v6 D35); the grades and PDF are then left out.
     */
    public function khs(Request $request, Student $student, AcademicRecord $record, ManageEvaluations $evaluations): JsonResponse
    {
        $this->authorizeStudent($request, $student);
        $request->validate(['semester' => ['nullable', 'integer']]);

        $semesters = Semester::query()->with('academicYear')
            ->whereHas('classEnrollments', fn ($query) => $query->where('student_id', $student->id))
            ->orderByDesc('academic_year_id')->orderByDesc('term')->get();
        $semester = $semesters->firstWhere('id', $request->integer('semester'))
            ?? $semesters->firstWhere('status.value', 'active')
            ?? $semesters->first();

        $waits = $semester !== null && $student->user_id === $request->user()->id && $evaluations->blocksKhs($student, $semester->id);
        $khs = $semester === null || $waits ? null : $record->khs($student, $semester);
        $attendance = $khs === null ? [] : $record->attendance(array_values($khs['rows']->map(fn (array $row) => $row['enrollment_id'])->all()));

        return response()->json(['data' => [
            'student' => ['id' => $student->id, 'nim' => $student->nim, 'name' => $student->user->name],
            'semesters' => $semesters->map(fn (Semester $item) => ['id' => $item->id, 'label' => $item->label(), 'active' => $item->status->value === 'active'])->values()->all(),
            'semester' => $semester === null ? null : ['id' => $semester->id, 'label' => $semester->label()],
            'waits_for_evaluation' => $waits,
            'min_attendance_percent' => (int) Setting::get('attendance.min_percent', 75),
            'subjects' => $khs === null ? [] : $khs['rows']->map(fn (array $row) => [
                ...$this->subject($row),
                /** hadir, izin, sakit, alpa, total and percent of the meetings so far. */
                'attendance' => $attendance[$row['enrollment_id']] ?? null,
            ])->values()->all(),
            'credits' => $khs['credits'] ?? 0,
            'ips' => $khs['ip'] ?? null,
            'pdf_url' => $khs === null ? null : ApiFiles::pdf($request->user(), ActiveRole::get(), 'khs', ['student' => $student->id, 'semester' => $semester->id]),
        ]]);
    }

    /**
     * Transcript.
     *
     * Every final grade per semester, the IPK and total credits. A repeated subject counts once, with the
     * best grade.
     */
    public function transcript(Request $request, Student $student, AcademicRecord $record): JsonResponse
    {
        $this->authorizeStudent($request, $student);
        $transcript = $record->transcript($student);

        return response()->json(['data' => [
            'student' => ['id' => $student->id, 'nim' => $student->nim, 'name' => $student->user->name],
            'semesters' => $transcript['semesters']->map(fn (array $entry) => [
                'semester' => ['id' => $entry['semester']->id, 'label' => $entry['semester']->label()],
                'subjects' => $entry['rows']->map(fn (array $row) => $this->subject($row))->values()->all(),
                'credits' => $entry['credits'],
                'ips' => $entry['ip'],
            ])->values()->all(),
            'credits' => $transcript['credits'],
            'ipk' => $transcript['ipk'],
            'pdf_url' => ApiFiles::pdf($request->user(), ActiveRole::get(), 'transcript', ['student' => $student->id]),
        ]]);
    }

    /**
     * Bills.
     *
     * Every bill of every semester, newest first, with lines, payments (receipts as PDF links) and what a
     * transfer confirmation may be. `bank_accounts`: where to transfer to.
     */
    public function bills(Request $request, Student $student): JsonResponse
    {
        $this->authorizeStudent($request, $student);

        $bills = $student->bills()->with(['semester.academicYear', 'lines', 'payments'])->orderByDesc('semester_id')->orderByDesc('id')->get();
        $accounts = Setting::get('payment.bank_accounts');

        return response()->json(['data' => [
            'bank_accounts' => is_string($accounts) && trim($accounts) !== '' ? trim($accounts) : null,
            'outstanding' => (int) $bills->filter(fn (Bill $bill) => in_array($bill->status->value, ['unpaid', 'partial'], true))->sum(fn (Bill $bill) => $bill->net_amount - $bill->paid_amount),
            'bills' => BillResource::collection($bills)->resolve($request),
        ]]);
    }

    /**
     * Confirm a transfer.
     *
     * The payer (the mahasantri or their wali) reports a bank transfer with a photo of the receipt
     * (multipart `proof`); the finance office checks it against the bank statement.
     */
    public function confirmTransfer(Request $request, Bill $bill, ConfirmTransfer $confirm): BillResource
    {
        $request->validate([
            'amount' => ['required', 'integer'],
            'paid_on' => ['required', 'date'],
            'reference' => ['required', 'string', 'max:255'],
            /** Photo or PDF of the transfer receipt. */
            'proof' => ['nullable', 'file'],
        ]);
        abort_unless($confirm->canPay($request->user(), $bill), 403);

        $confirm->handle($bill, $request->user(), [
            'amount' => $request->integer('amount'),
            'paid_on' => $request->input('paid_on'),
            'reference' => $request->input('reference'),
            'proof' => $request->file('proof'),
        ]);

        return new BillResource($bill->refresh()->load(['semester.academicYear', 'lines', 'payments']));
    }

    /**
     * Pesantren activities.
     *
     * Activities recorded by the academic office (latest 20), for monitoring.
     */
    public function activities(Request $request, Student $student): JsonResponse
    {
        $this->authorizeStudent($request, $student);

        return response()->json(['data' => $student->activityLogs()->limit(20)->get()->map(fn (ActivityLog $log) => [
            'id' => $log->id,
            'category' => $log->category->value,
            'category_label' => $log->category->label(),
            'happened_on' => $log->happened_on->toDateString(),
            'title' => $log->title,
            'notes' => $log->notes,
        ])->values()->all()]);
    }

    private function authorizeStudent(Request $request, Student $student): void
    {
        abort_unless(StudentAccess::canView($request->user(), ActiveRole::get(), $student), 403);
        $student->loadMissing('user');
    }

    /**
     * @param  array{enrollment_id: int, code: string, name: string, credits: int, score: string|null, letter: string|null, point: string|null, final: bool}  $row
     * @return array<string, mixed>
     */
    private function subject(array $row): array
    {
        return [
            'code' => $row['code'],
            'name' => $row['name'],
            'credits' => $row['credits'],
            'score' => $row['score'],
            'letter' => $row['letter'],
            'point' => $row['point'],
            /** Only final grades count toward the IPS / IPK. */
            'final' => $row['final'],
        ];
    }
}
