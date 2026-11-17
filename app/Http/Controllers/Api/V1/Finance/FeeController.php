<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Actions\Finance\AddFeeToBills;
use App\Actions\Finance\CopyFeeRates;
use App\Actions\Finance\ManageFees;
use App\Actions\Finance\ManageScholarshipAwards;
use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Scholarship;
use App\Models\ScholarshipAward;
use App\Models\Semester;
use App\Models\Setting;
use App\Models\Student;
use App\Support\StudentPicker;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Fees and scholarships (Admin Keuangan, Superadmin): fee types, rates per
 * academic year, adding a new fee to bills already made, scholarship
 * programmes and who receives them.
 */
#[Group('Keuangan', weight: 16)]
class FeeController extends Controller
{
    /**
     * Fee types and rates.
     *
     * All fee types, and the rates of an academic year (`?year=`, default the latest) with their class or entry
     * year when they apply to only some mahasantri.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['year' => ['nullable', 'integer']]);
        $yearId = $request->integer('year') ?: AcademicYear::query()->orderByDesc('name')->value('id');

        return response()->json(['data' => [
            'academic_year_id' => $yearId,
            'fee_types' => FeeType::query()->orderBy('name')->get()->map(fn (FeeType $type) => [
                ...$type->only(['id', 'name', 'is_active']),
                'frequency' => $type->frequency->value,
                /** How many payments the fee may be paid in (after-review D55). */
                'max_installments' => $type->max_installments,
                /** Months billed a semester, for a monthly fee (D56). */
                'months_per_semester' => $type->months_per_semester,
            ])->values()->all(),
            'rates' => FeeRate::query()->with(['feeType', 'classGroup'])->where('academic_year_id', $yearId)->get()
                ->sortBy(fn (FeeRate $rate) => $rate->feeType->name.'|'.$rate->class_group_id.'|'.$rate->entry_year)->values()
                ->map(fn (FeeRate $rate) => [
                    ...$rate->only(['id', 'fee_type_id', 'class_group_id', 'entry_year', 'amount']),
                    'fee_type' => $rate->feeType->name,
                    'class_name' => $rate->classGroup?->name,
                ])->all(),
        ]]);
    }

    /**
     * Add or change a fee type.
     *
     * `name`, `frequency` (per_semester, monthly, once), `max_installments` (1–12), `months_per_semester`
     * (1–6, for a monthly fee), `is_active`. Bills already made keep their amounts, instalment limits and months.
     */
    public function saveFeeType(Request $request, ManageFees $fees): JsonResponse
    {
        $request->validate(['id' => ['nullable', 'integer'], 'name' => ['required', 'string'], 'frequency' => ['required', 'string'], 'is_active' => ['nullable', 'boolean'],
            'max_installments' => ['nullable', 'integer'], 'months_per_semester' => ['nullable', 'integer']]);
        $existing = $request->filled('id') ? FeeType::query()->findOrFail($request->integer('id')) : null;
        $type = $fees->saveFeeType($existing, [
            ...$request->only(['name', 'frequency']),
            // Left out: kept as it is (or the default for a new fee).
            'max_installments' => $request->input('max_installments', $existing->max_installments ?? (int) Setting::get('payment.max_installments', 3)),
            'months_per_semester' => $request->input('months_per_semester', $existing->months_per_semester ?? 6),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return response()->json(['data' => ['id' => $type->id]]);
    }

    /**
     * Add or change a rate.
     *
     * `academic_year_id`, `fee_type_id`, `amount`, and optionally `class_group_id` or `entry_year` for a rate
     * that applies to only those mahasantri. It applies to bills made from now on.
     */
    public function saveRate(Request $request, ManageFees $fees): JsonResponse
    {
        $request->validate(['id' => ['nullable', 'integer'], 'academic_year_id' => ['required', 'integer'], 'fee_type_id' => ['required', 'integer'], 'class_group_id' => ['nullable', 'integer'], 'entry_year' => ['nullable', 'integer'], 'amount' => ['required', 'integer']]);
        $rate = $fees->saveRate($request->filled('id') ? FeeRate::query()->findOrFail($request->integer('id')) : null, $request->only(['academic_year_id', 'fee_type_id', 'class_group_id', 'entry_year', 'amount']));

        return response()->json(['data' => ['id' => $rate->id]]);
    }

    /**
     * Remove a rate.
     */
    public function deleteRate(FeeRate $rate, ManageFees $fees): Response
    {
        $fees->deleteRate($rate);

        return response()->noContent();
    }

    /**
     * Copy rates to the next year.
     *
     * From `from_year_id` to `to_year_id`; rates that already exist there are kept.
     */
    public function copyRates(Request $request, CopyFeeRates $copy): JsonResponse
    {
        $request->validate(['from_year_id' => ['required', 'integer'], 'to_year_id' => ['required', 'integer']]);

        return response()->json(['data' => $copy->handle(AcademicYear::query()->findOrFail($request->integer('from_year_id')), AcademicYear::query()->findOrFail($request->integer('to_year_id')))]);
    }

    /**
     * Add a fee to existing bills.
     *
     * For a fee added after the bills of `semester_id` were made. Without `apply: true` it only previews what
     * each bill would get; with it, the bills get the line (mahasantri and wali are notified).
     */
    public function addToBills(Request $request, AddFeeToBills $add): JsonResponse
    {
        $request->validate(['fee_type_id' => ['required', 'integer'], 'semester_id' => ['required', 'integer'], 'apply' => ['nullable', 'boolean']]);
        $type = FeeType::query()->findOrFail($request->integer('fee_type_id'));
        $semester = Semester::query()->findOrFail($request->integer('semester_id'));

        if (! $request->boolean('apply')) {
            return response()->json(['data' => ['preview' => $add->preview($type, $semester)]]);
        }

        return response()->json(['data' => ['changed' => $add->handle($type, $semester, $request->user())]]);
    }

    /**
     * Scholarships.
     *
     * The programmes of an academic year (`?year=`), each with how many receive it; `?scholarship=` also
     * lists its recipients.
     */
    public function scholarships(Request $request): JsonResponse
    {
        $request->validate(['year' => ['nullable', 'integer'], 'scholarship' => ['nullable', 'integer']]);
        $yearId = $request->integer('year') ?: AcademicYear::query()->orderByDesc('name')->value('id');

        $awards = ! $request->filled('scholarship') ? null : ScholarshipAward::query()->with(['student.user', 'semester.academicYear', 'billLine'])
            ->where('scholarship_id', $request->integer('scholarship'))->orderBy('semester_id')->get()
            ->map(fn (ScholarshipAward $award) => [
                'id' => $award->id,
                'student_id' => $award->student_id,
                'nim' => $award->student->nim,
                'name' => $award->student->user->name,
                'semester' => $award->semester->label(),
                'note' => $award->note,
                'discount' => $award->billLine === null ? null : -$award->billLine->amount,
            ])->values()->all();

        return response()->json(['data' => [
            'academic_year_id' => $yearId,
            'scholarships' => Scholarship::query()->withCount('awards')->where('academic_year_id', $yearId)->orderBy('name')->get()
                ->map(fn (Scholarship $scholarship) => [
                    ...$scholarship->only(['id', 'name', 'funding_source', 'value', 'quota', 'is_active']),
                    /** full | percent | fixed */
                    'type' => $scholarship->type->value,
                    'awards_count' => (int) $scholarship->getAttribute('awards_count'),
                ])->values()->all(),
            'awards' => $awards,
        ]]);
    }

    /**
     * Add or change a scholarship.
     *
     * `academic_year_id`, `name`, `funding_source`, `type` (full, percent, fixed), `value`, `quota`.
     */
    public function saveScholarship(Request $request, ManageFees $fees): JsonResponse
    {
        $request->validate(['id' => ['nullable', 'integer'], 'academic_year_id' => ['required', 'integer'], 'name' => ['required', 'string'], 'funding_source' => ['required', 'string'], 'type' => ['required', 'string'], 'value' => ['nullable', 'integer'], 'quota' => ['nullable', 'integer'], 'is_active' => ['nullable', 'boolean']]);
        $scholarship = $fees->saveScholarship($request->filled('id') ? Scholarship::query()->findOrFail($request->integer('id')) : null, [
            ...$request->only(['academic_year_id', 'name', 'funding_source', 'type', 'value', 'quota']),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return response()->json(['data' => ['id' => $scholarship->id]]);
    }

    /**
     * Mahasantri to award.
     *
     * All active mahasantri, 50 a page, narrowed by `search` (NIM or name), `class_id` (class in the active
     * semester, or `none`) and `gender`.
     */
    public function candidates(Request $request): JsonResponse
    {
        $request->validate(StudentPicker::rules());
        $semester = Semester::active();

        $students = StudentPicker::fromRequest(StudentPicker::query($semester), $request, $semester)->orderBy('nim')->paginate(StudentPicker::PAGE);

        return response()->json([
            'data' => collect($students->items())->map(fn (Student $student) => [
                'id' => $student->id,
                'nim' => $student->nim,
                'name' => $student->user->name,
                'gender' => $student->gender->value,
                'class' => $student->classEnrollments->first()?->class_name,
            ])->values()->all(),
            'meta' => ['current_page' => $students->currentPage(), 'last_page' => $students->lastPage(), 'total' => $students->total()],
        ]);
    }

    /**
     * Award a scholarship.
     *
     * To `student_id` for `semester_id`; their bill of that semester gets the discount (or the next bill made).
     */
    public function award(Request $request, Scholarship $scholarship, ManageScholarshipAwards $awards): JsonResponse
    {
        $request->validate(['student_id' => ['required', 'integer'], 'semester_id' => ['required', 'integer'], 'note' => ['nullable', 'string']]);
        $award = $awards->award($scholarship, Student::query()->findOrFail($request->integer('student_id')), Semester::query()->findOrFail($request->integer('semester_id')), $request->user(), $request->input('note'));

        return response()->json(['data' => ['id' => $award->id]], 201);
    }

    /**
     * Revoke an award.
     *
     * Only while its bill has no payments.
     */
    public function revoke(ScholarshipAward $award, ManageScholarshipAwards $awards): Response
    {
        $awards->revoke($award);

        return response()->noContent();
    }
}
