<?php

namespace App\Actions\Exams;

use App\Enums\BillStatus;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\Bill;
use App\Models\DispensationRequest;
use App\Models\ExamDispensation;
use App\Models\ExamPeriod;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use App\Support\Notify;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Surat permohonan dispensasi (v7 D47). A mahasantri whose bill of the active semester is not paid off
 * writes why, and prints the letter (kop surat, their data, their bills, the reason) for the Mudir to
 * sign by hand. Admin is told, and grants it as an exam dispensation once the letter is signed.
 */
class ManageDispensationRequests
{
    public const MAX_REASON = 1000;

    /**
     * The semester the mahasantri may ask a dispensation for: the active one, while its bill is not paid
     * off. Null when there is nothing to ask for.
     */
    public function openSemester(Student $student): ?Semester
    {
        $semester = Semester::active();

        if ($semester === null) {
            return null;
        }

        $unpaid = $student->bills()
            ->where('semester_id', $semester->id)
            ->whereIn('status', [BillStatus::Unpaid, BillStatus::Partial])
            ->exists();

        return $unpaid ? $semester : null;
    }

    /**
     * The bills the letter lists: every bill still to pay (earlier semesters too), and the semester's own
     * bill, oldest first.
     *
     * @return Collection<int, Bill>
     */
    public function bills(Student $student, Semester $semester): Collection
    {
        return $student->bills()
            ->with('semester.academicYear')
            ->withCount(['payments as verified_payments_count' => fn (Builder $query) => $query->where('status', PaymentStatus::Verified)])
            ->where('status', '!=', BillStatus::Void)
            ->where(fn (Builder $query) => $query
                ->whereIn('status', [BillStatus::Unpaid, BillStatus::Partial])
                ->orWhere('semester_id', $semester->id))
            ->orderBy('semester_id')
            ->orderBy('id')
            ->get();
    }

    /**
     * The mahasantri's request for the semester, if written.
     */
    public function current(Student $student): ?DispensationRequest
    {
        $semester = Semester::active();

        return $semester ? $student->dispensationRequests()->where('semester_id', $semester->id)->first() : null;
    }

    /**
     * Write (or rewrite) the request for the active semester.
     */
    public function submit(Student $student, string $reason, User $by): DispensationRequest
    {
        $semester = $this->openSemester($student);

        if ($semester === null) {
            throw ValidationException::withMessages(['reason' => __('Your bill for this semester is paid off, so no dispensation is needed.')]);
        }

        $data = Validator::make(['reason' => trim($reason)], [
            'reason' => ['required', 'string', 'min:10', 'max:'.self::MAX_REASON],
        ])->validate();

        $outstanding = (int) $this->bills($student, $semester)->sum(fn (Bill $bill) => $bill->outstanding());

        $request = DispensationRequest::query()->updateOrCreate(
            ['student_id' => $student->id, 'semester_id' => $semester->id],
            ['reason' => $data['reason'], 'outstanding' => $outstanding],
        );

        if ($request->wasRecentlyCreated) {
            $student->loadMissing('user');
            Notify::send(
                User::query()->whereHas('roles', fn ($query) => $query->where('role', Role::Admin))->get(),
                'exam',
                __('Dispensation request: :name', ['name' => $student->user->name]),
                __(':name (:nim): :reason', ['name' => $student->user->name, 'nim' => $student->nim, 'reason' => mb_strimwidth($data['reason'], 0, 160, '…')]),
                $this->adminUrl($semester),
                'document-check',
                $by,
                'dispensation-requests',
                __(':count dispensation requests are waiting.'),
            );
        }

        return $request;
    }

    /**
     * Whether Admin has granted it: an exam dispensation for an exam period of that semester.
     */
    public function granted(DispensationRequest $request): bool
    {
        return ExamDispensation::query()
            ->where('student_id', $request->student_id)
            ->whereHas('period', fn (Builder $query) => $query->where('semester_id', $request->semester_id))
            ->exists();
    }

    /**
     * Where Admin handles requests: the semester's latest exam period, else the exam list.
     */
    public function adminUrl(Semester $semester): string
    {
        $period = ExamPeriod::query()->where('semester_id', $semester->id)->latest('id')->first();

        return $period ? route('admin.exams.show', $period) : route('admin.exams.index');
    }
}
