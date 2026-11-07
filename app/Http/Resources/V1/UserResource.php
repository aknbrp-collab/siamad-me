<?php

namespace App\Http\Resources\V1;

use App\Enums\Role;
use App\Models\User;
use App\Support\ActiveRole;
use App\Support\ApiFiles;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in user as the app needs it at start: who they are, which roles
 * they hold (the app sends one as X-Role) and what they must do first.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;
        $user->loadMissing(['student', 'teacher', 'guardian']);
        $role = ActiveRole::get();

        $photo = match (true) {
            $user->student?->photo_path !== null => ApiFiles::url($user, $role, 'student-photo', $user->student->id),
            $user->teacher?->photo_path !== null => ApiFiles::url($user, $role, 'teacher-photo', $user->teacher->id),
            default => null,
        };

        return [
            'id' => $user->id,
            /** NIM, NIDM, guardian code or staff login ID. */
            'username' => $user->username,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => array_map(fn (Role $held) => ['value' => $held->value, 'label' => $held->label()], $user->roleList()),
            /** The role this request acts in (X-Role, or the only role); null when the app must ask. */
            'active_role' => $role?->value,
            'must_change_password' => (bool) $user->must_change_password,
            'two_factor_enabled' => $user->hasEnabledTwoFactorAuthentication(),
            /** A signed link valid for a few minutes. */
            'photo_url' => $photo,
            'student' => $user->student === null ? null : ['id' => $user->student->id, 'nim' => $user->student->nim],
            'teacher' => $user->teacher === null ? null : ['id' => $user->teacher->id, 'nidm' => $user->teacher->nidm],
            'guardian' => $user->guardian === null ? null : ['id' => $user->guardian->id, 'code' => $user->guardian->code],
        ];
    }
}
