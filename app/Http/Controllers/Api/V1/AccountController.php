<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\ChangeOwnPassword;
use App\Actions\People\UpdateOwnProfile;
use App\Actions\UpdateUserProfile;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PasswordRequest;
use App\Http\Requests\Api\V1\ProfileRequest;
use App\Http\Resources\V1\UserResource;
use App\Models\User;
use App\Support\ActiveRole;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;

/**
 * The signed-in user: who they are, their profile (Profil saya) and password.
 */
#[Group('Account', weight: 3)]
class AccountController extends Controller
{
    /**
     * The signed-in user.
     *
     * Call it after login and at app start: roles, the role this request acts in, and whether the password
     * must be changed first.
     */
    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    /**
     * Profile (Profil saya).
     *
     * Which profile depends on the role: mahasantri, muhadhir, wali santri, or staff (name and email).
     * `editable` lists the fields `PATCH /profile` accepts.
     */
    public function profile(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        [$kind, $editable] = $this->kind($user);

        $data = match ($kind) {
            'student' => [
                'name' => $user->name,
                'nim' => $user->student?->nim,
                'gender' => $user->student?->gender->value,
                ...Arr::except($user->student?->only(UpdateOwnProfile::STUDENT_FIELDS) ?? [], ['photo']),
            ],
            'teacher' => [
                'name' => $user->name,
                'email' => $user->email,
                'nidm' => $user->teacher?->nidm,
                ...Arr::except($user->teacher?->only(UpdateOwnProfile::TEACHER_FIELDS) ?? [], ['photo', 'name', 'email']),
            ],
            'guardian' => [
                'name' => $user->name,
                'email' => $user->email,
                'code' => $user->guardian?->code,
                'relationship' => $user->guardian?->relationship,
                'phone' => $user->guardian?->phone,
            ],
            default => ['name' => $user->name, 'email' => $user->email],
        };

        $data = array_map(fn ($value) => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value, $data);

        return response()->json(['data' => ['kind' => $kind, 'editable' => $editable, 'fields' => $data]]);
    }

    /**
     * Change the profile.
     *
     * Only the fields in `editable` of `GET /profile`; others are ignored. A new photo is sent as multipart
     * field `photo` (JPG/PNG).
     */
    public function updateProfile(ProfileRequest $request, UpdateOwnProfile $own, UpdateUserProfile $staff): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        [$kind] = $this->kind($user);
        $input = $request->all();

        match ($kind) {
            'student' => $own->student($user->student, $input),
            'teacher' => $own->teacher($user->teacher, $input),
            'guardian' => $own->guardian($user->guardian, $input),
            default => $staff->handle($user, Arr::only($input, ['name', 'email'])),
        };

        $user->refresh();

        return $this->profile($request);
    }

    /**
     * Change the password.
     *
     * Also replaces a temporary password. Other devices are signed out; this one stays signed in.
     */
    public function password(PasswordRequest $request, ChangeOwnPassword $change): Response
    {
        $user = $request->user();
        $change->handle($user, $request->only(['current_password', 'password', 'password_confirmation']), $user->currentAccessToken()->getKey());

        return response()->noContent();
    }

    /**
     * The profile kind for the role this request acts in, and its editable fields.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function kind(User $user): array
    {
        $user->loadMissing(['student', 'teacher', 'guardian']);
        $role = ActiveRole::get();

        return match (true) {
            in_array($role, [Role::Student, Role::PaymentCollector], true) && $user->student !== null => ['student', UpdateOwnProfile::STUDENT_FIELDS],
            in_array($role, [Role::Teacher, Role::AcademicMonitor], true) && $user->teacher !== null => ['teacher', UpdateOwnProfile::TEACHER_FIELDS],
            $role === Role::Guardian && $user->guardian !== null => ['guardian', UpdateOwnProfile::GUARDIAN_FIELDS],
            default => ['staff', $user->student !== null ? ['email'] : ['name', 'email']],
        };
    }
}
