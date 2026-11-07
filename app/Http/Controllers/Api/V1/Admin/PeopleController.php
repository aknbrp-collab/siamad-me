<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\People\CreateStudent;
use App\Actions\People\CreateTeacher;
use App\Actions\People\ResetUserPassword;
use App\Actions\People\SaveGuardian;
use App\Actions\People\UpdateStudent;
use App\Actions\People\UpdateTeacher;
use App\Enums\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\Teacher;
use App\Models\TeacherDocument;
use App\Models\User;
use App\Support\ActiveRole;
use App\Support\ApiFiles;
use App\Support\CreatedAccount;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * People (Admin, Superadmin): mahasantri, muhadhir and wali santri, and
 * their login (temporary password, on/off).
 */
#[Group('Admin akademik', weight: 15)]
class PeopleController extends Controller
{
    /** Fields a mahasantri record takes (photo as multipart). */
    private const STUDENT_FIELDS = ['nim', 'name', 'email', 'study_program_id', 'entry_year', 'gender', 'birth_place', 'birth_date', 'address', 'phone',
        'father_name', 'father_is_alive', 'mother_name', 'mother_is_alive', 'guardian_name', 'photo', 'status', 'graduated_at'];

    /** Fields a muhadhir record takes. */
    private const TEACHER_FIELDS = ['nidm', 'name', 'email', 'nik', 'title', 'gender', 'birth_place', 'birth_date', 'address', 'phone',
        'expertise', 'scopus_id', 'google_scholar_url', 'photo', 'status'];

    /**
     * Mahasantri.
     *
     * `search` (NIM or name), `status` (active, leave, graduated, …), `program` (study program id); paginated,
     * with how many documents are verified.
     */
    public function students(Request $request): JsonResponse
    {
        $request->validate(['search' => ['nullable', 'string'], 'status' => ['nullable', 'string'], 'program' => ['nullable', 'integer']]);
        $term = '%'.mb_strtolower(trim((string) $request->input('search'))).'%';

        $students = Student::query()->with(['user', 'studyProgram'])
            ->withCount(['documents', 'documents as verified_documents_count' => fn (Builder $query) => $query->where('status', DocumentStatus::Verified)])
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->input('status')))
            ->when($request->filled('program'), fn (Builder $query) => $query->where('study_program_id', $request->integer('program')))
            ->when($request->filled('search'), fn (Builder $query) => $query->where(fn (Builder $query) => $query->whereRaw('lower(nim) like ?', [$term])
                ->orWhereHas('user', fn (Builder $query) => $query->whereRaw('lower(name) like ?', [$term]))))
            ->orderBy('nim')->paginate(25);

        return response()->json([
            'data' => collect($students->items())->map(fn (Student $student) => [
                'id' => $student->id,
                'nim' => $student->nim,
                'name' => $student->user->name,
                'gender' => $student->gender->value,
                'status' => $student->status->value,
                'study_program' => $student->studyProgram->code,
                'entry_year' => $student->entry_year,
                'documents' => (int) $student->getAttribute('documents_count'),
                'verified_documents' => (int) $student->getAttribute('verified_documents_count'),
                'login_active' => $student->user->is_active,
            ])->values()->all(),
            'meta' => ['current_page' => $students->currentPage(), 'last_page' => $students->lastPage(), 'total' => $students->total()],
        ]);
    }

    /**
     * A mahasantri.
     *
     * The record, documents, wali santri and pembimbing akademik. Grades: `/students/{id}/khs`.
     */
    public function student(Request $request, Student $student): JsonResponse
    {
        $student->load(['user', 'studyProgram', 'documents', 'guardians.user', 'currentSupervision.teacher.user']);
        $user = $request->user();
        $role = ActiveRole::get();

        return response()->json(['data' => [
            'id' => $student->id,
            'user_id' => $student->user_id,
            ...Arr::except($student->only(self::STUDENT_FIELDS), ['photo', 'name', 'email']),
            'name' => $student->user->name,
            'email' => $student->user->email,
            'gender' => $student->gender->value,
            'status' => $student->status->value,
            'birth_date' => $student->birth_date?->toDateString(),
            'graduated_at' => $student->graduated_at?->toDateString(),
            'login_active' => $student->user->is_active,
            'photo_url' => $student->photo_path === null ? null : ApiFiles::url($user, $role, 'student-photo', $student->id),
            'documents' => $student->documents->map(fn (StudentDocument $document) => $this->document($request, $document))->values()->all(),
            'guardians' => $student->guardians->map(fn (Guardian $guardian) => ['id' => $guardian->id, 'code' => $guardian->code, 'name' => $guardian->user->name])->values()->all(),
            'supervisor' => $student->currentSupervision?->teacher->user->name,
        ]]);
    }

    /**
     * Add a mahasantri.
     *
     * The account gets a temporary password, returned once as `password` (hand it over; it must be changed at
     * the first login).
     */
    public function storeStudent(Request $request, CreateStudent $create): JsonResponse
    {
        $request->validate(['nim' => ['required', 'string'], 'name' => ['required', 'string']]);

        return response()->json(['data' => $this->credentials($create->handle($this->studentInput($request)))], 201);
    }

    /**
     * Change a mahasantri.
     *
     * Multipart when a new `photo` is sent. `status` graduated needs `graduated_at`.
     */
    public function updateStudent(Request $request, Student $student, UpdateStudent $update): JsonResponse
    {
        $request->validate(['nim' => ['required', 'string'], 'name' => ['required', 'string'], 'status' => ['required', 'string']]);
        $update->handle($student, $this->studentInput($request));

        return $this->student($request, $student->refresh());
    }

    /**
     * Muhadhir.
     *
     * `search` (NIDM, name or expertise), `status`; paginated.
     */
    public function teachers(Request $request): JsonResponse
    {
        $request->validate(['search' => ['nullable', 'string'], 'status' => ['nullable', 'string']]);
        $term = '%'.mb_strtolower(trim((string) $request->input('search'))).'%';

        $teachers = Teacher::query()->with('user')
            ->withCount(['documents', 'documents as verified_documents_count' => fn (Builder $query) => $query->where('status', DocumentStatus::Verified)])
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->input('status')))
            ->when($request->filled('search'), fn (Builder $query) => $query->where(fn (Builder $query) => $query->whereRaw('lower(nidm) like ?', [$term])
                ->orWhereRaw('lower(expertise) like ?', [$term])
                ->orWhereHas('user', fn (Builder $query) => $query->whereRaw('lower(name) like ?', [$term]))))
            ->orderBy('nidm')->paginate(25);

        return response()->json([
            'data' => collect($teachers->items())->map(fn (Teacher $teacher) => [
                'id' => $teacher->id,
                'nidm' => $teacher->nidm,
                'name' => $teacher->user->name,
                'title' => $teacher->title,
                'status' => $teacher->status->value,
                'expertise' => $teacher->expertise,
                'documents' => (int) $teacher->getAttribute('documents_count'),
                'verified_documents' => (int) $teacher->getAttribute('verified_documents_count'),
                'login_active' => $teacher->user->is_active,
            ])->values()->all(),
            'meta' => ['current_page' => $teachers->currentPage(), 'last_page' => $teachers->lastPage(), 'total' => $teachers->total()],
        ]);
    }

    /**
     * A muhadhir.
     */
    public function teacher(Request $request, Teacher $teacher): JsonResponse
    {
        $teacher->load(['user', 'documents']);

        return response()->json(['data' => [
            'id' => $teacher->id,
            'user_id' => $teacher->user_id,
            ...Arr::except($teacher->only(self::TEACHER_FIELDS), ['photo', 'name', 'email']),
            'name' => $teacher->user->name,
            'email' => $teacher->user->email,
            'gender' => $teacher->gender->value,
            'status' => $teacher->status->value,
            'birth_date' => $teacher->birth_date?->toDateString(),
            'login_active' => $teacher->user->is_active,
            'photo_url' => $teacher->photo_path === null ? null : ApiFiles::url($request->user(), ActiveRole::get(), 'teacher-photo', $teacher->id),
            'documents' => $teacher->documents->map(fn (TeacherDocument $document) => $this->document($request, $document))->values()->all(),
        ]]);
    }

    /**
     * Add a muhadhir.
     *
     * Returns the temporary password once.
     */
    public function storeTeacher(Request $request, CreateTeacher $create): JsonResponse
    {
        $request->validate(['nidm' => ['required', 'string'], 'name' => ['required', 'string']]);

        return response()->json(['data' => $this->credentials($create->handle($this->teacherInput($request)))], 201);
    }

    /**
     * Change a muhadhir.
     */
    public function updateTeacher(Request $request, Teacher $teacher, UpdateTeacher $update): JsonResponse
    {
        $request->validate(['nidm' => ['required', 'string'], 'name' => ['required', 'string'], 'status' => ['required', 'string']]);
        $update->handle($teacher, $this->teacherInput($request));

        return $this->teacher($request, $teacher->refresh());
    }

    /**
     * Wali santri.
     *
     * `search` on the code, name, or a child's NIM or name; paginated.
     */
    public function guardians(Request $request): JsonResponse
    {
        $request->validate(['search' => ['nullable', 'string']]);
        $term = '%'.mb_strtolower(trim((string) $request->input('search'))).'%';

        $guardians = Guardian::query()->with(['user', 'students.user'])
            ->when($request->filled('search'), fn (Builder $query) => $query->where(fn (Builder $query) => $query->whereRaw('lower(code) like ?', [$term])
                ->orWhereHas('user', fn (Builder $query) => $query->whereRaw('lower(name) like ?', [$term]))
                ->orWhereHas('students', fn (Builder $query) => $query->whereRaw('lower(nim) like ?', [$term])
                    ->orWhereHas('user', fn (Builder $query) => $query->whereRaw('lower(name) like ?', [$term])))))
            ->orderByDesc('id')->paginate(25);

        return response()->json([
            'data' => collect($guardians->items())->map(fn (Guardian $guardian) => $this->guardian($guardian))->values()->all(),
            'meta' => ['current_page' => $guardians->currentPage(), 'last_page' => $guardians->lastPage(), 'total' => $guardians->total()],
        ]);
    }

    /**
     * Add or change a wali santri.
     *
     * `name`, `email`, `relationship`, `phone`, `student_ids` (their children). A new account returns its code
     * and temporary password once.
     */
    public function saveGuardian(Request $request, SaveGuardian $save): JsonResponse
    {
        $request->validate(['id' => ['nullable', 'integer'], 'name' => ['required', 'string'], 'student_ids' => ['present', 'array'], 'student_ids.*' => ['integer']]);
        $guardian = $request->filled('id') ? Guardian::query()->findOrFail($request->integer('id')) : null;

        $account = $save->handle($guardian, [
            'name' => trim((string) $request->input('name')),
            'email' => $request->input('email') ?: null,
            'relationship' => $request->input('relationship') ?: null,
            'phone' => $request->input('phone') ?: null,
            'student_ids' => array_values(array_map('intval', (array) $request->input('student_ids'))),
        ]);

        if ($account !== null) {
            return response()->json(['data' => $this->credentials($account)], 201);
        }

        return response()->json(['data' => $this->guardian($guardian?->refresh()->load(['user', 'students.user']) ?? abort(404))]);
    }

    /**
     * Give a temporary password.
     *
     * For a mahasantri, muhadhir or wali whose password is lost. Returned once; they must change it at
     * login, and their app devices are signed out. Staff accounts: Superadmin only.
     */
    public function resetPassword(Request $request, User $user, ResetUserPassword $reset): JsonResponse
    {
        abort_unless($user->accountManageableBy(ActiveRole::get()), 403);

        return response()->json(['data' => ['username' => $user->username, 'name' => $user->name, 'password' => $reset->handle($user)]]);
    }

    /**
     * Turn a login on or off.
     *
     * `active: false` stops the person from logging in (and ends their app devices on the next call).
     */
    public function setActive(Request $request, User $user): JsonResponse
    {
        $request->validate(['active' => ['required', 'boolean']]);
        abort_if($user->is($request->user()), 403);
        abort_unless($user->accountManageableBy(ActiveRole::get()), 403);

        $user->update(['is_active' => $request->boolean('active')]);

        return response()->json(['data' => ['user_id' => $user->id, 'active' => $user->is_active]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function studentInput(Request $request): array
    {
        $input = Arr::only($request->all(), self::STUDENT_FIELDS);
        $input['photo'] = $request->file('photo');

        foreach (['father_is_alive', 'mother_is_alive'] as $flag) {
            if (array_key_exists($flag, $input) && $input[$flag] !== null) {
                $input[$flag] = filter_var($input[$flag], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return array_map(fn ($value) => $value === '' ? null : $value, $input);
    }

    /**
     * @return array<string, mixed>
     */
    private function teacherInput(Request $request): array
    {
        $input = Arr::only($request->all(), self::TEACHER_FIELDS);
        $input['photo'] = $request->file('photo');

        return array_map(fn ($value) => $value === '' ? null : $value, $input);
    }

    /**
     * @return array<string, mixed>
     */
    private function credentials(CreatedAccount $account): array
    {
        return ['id' => $account->model->getKey(), 'username' => $account->username, 'name' => $account->name, 'password' => $account->password];
    }

    /**
     * @return array<string, mixed>
     */
    private function guardian(Guardian $guardian): array
    {
        return [
            'id' => $guardian->id,
            'user_id' => $guardian->user_id,
            'code' => $guardian->code,
            'name' => $guardian->user->name,
            'email' => $guardian->user->email,
            'relationship' => $guardian->relationship,
            'phone' => $guardian->phone,
            'login_active' => $guardian->user->is_active,
            'children' => $guardian->students->map(fn (Student $student) => ['id' => $student->id, 'nim' => $student->nim, 'name' => $student->user->name])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function document(Request $request, StudentDocument|TeacherDocument $document): array
    {
        return [
            'id' => $document->id,
            'title' => $document->title(),
            'status' => $document->status->value,
            'status_label' => $document->status->label(),
            'rejection_note' => $document->rejection_note,
            'file_url' => $document->file_path === null ? null : ApiFiles::url($request->user(), ActiveRole::get(), $document instanceof StudentDocument ? 'student-document' : 'teacher-document', $document->id),
        ];
    }
}
