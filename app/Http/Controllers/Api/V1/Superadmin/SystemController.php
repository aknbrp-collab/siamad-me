<?php

namespace App\Http\Controllers\Api\V1\Superadmin;

use App\Actions\Letters\ManageLetters;
use App\Actions\People\ManageUserAccounts;
use App\Actions\Superadmin\ManageSystem;
use App\Enums\Gender;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DocumentTemplate;
use App\Models\LetterType;
use App\Models\Setting;
use App\Models\Signatory;
use App\Models\Stamp;
use App\Models\User;
use App\Support\ActiveRole;
use App\Support\ApiFiles;
use App\Support\Branding;
use App\Support\Templates;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Superadmin: users and roles, system settings, institution identity and app
 * appearance, signatories and stamps, letter types and numbering, managed
 * templates, and the audit log (read). Maintenance mode, backups and the CMS
 * page stay on the web (v6 D41).
 */
#[Group('Superadmin', weight: 17)]
class SystemController extends Controller
{
    /**
     * Users.
     *
     * Every login account: `search` (login ID, name, email), `role`, `status` (active, inactive); paginated.
     */
    public function users(Request $request): JsonResponse
    {
        $request->validate(['search' => ['nullable', 'string'], 'role' => ['nullable', Rule::enum(Role::class)], 'status' => ['nullable', Rule::in(['active', 'inactive'])]]);
        $term = '%'.mb_strtolower(trim((string) $request->input('search'))).'%';

        $users = User::query()->with('roles')
            ->when($request->filled('search'), fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('username', 'like', $term)
                ->orWhereRaw('lower(name) like ?', [$term])->orWhereRaw('lower(email) like ?', [$term])))
            ->when($request->filled('role'), fn (Builder $query) => $query->whereHas('roles', fn (Builder $query) => $query->where('role', $request->input('role'))))
            ->when($request->filled('status'), fn (Builder $query) => $query->where('is_active', $request->input('status') === 'active'))
            ->orderBy('name')->paginate(25);

        return response()->json([
            'data' => collect($users->items())->map(fn (User $user) => $this->summary($user))->values()->all(),
            'meta' => ['current_page' => $users->currentPage(), 'last_page' => $users->lastPage(), 'total' => $users->total()],
        ]);
    }

    /**
     * A user.
     *
     * Roles, the Petugas Pembayaran scope, and the profile it belongs to (mahasantri, muhadhir, wali).
     */
    public function user(User $user): JsonResponse
    {
        $user->load(['roles', 'student', 'teacher', 'guardian']);

        return response()->json(['data' => [
            ...$this->summary($user),
            'collector_scope' => $user->collectorScope()?->value,
            'profile' => match (true) {
                $user->student !== null => ['kind' => 'student', 'id' => $user->student->id],
                $user->teacher !== null => ['kind' => 'teacher', 'id' => $user->teacher->id],
                $user->guardian !== null => ['kind' => 'guardian', 'id' => $user->guardian->id],
                default => null,
            },
            /** Mahasantri and wali accounts cannot hold staff roles. */
            'can_hold_staff_roles' => ! $user->hasRole(Role::Student, Role::Guardian),
        ]]);
    }

    /**
     * Add a staff account.
     *
     * `username`, `name`, `email`, `roles` (staff roles). Returns the temporary password once.
     */
    public function storeUser(Request $request, ManageUserAccounts $accounts): JsonResponse
    {
        $request->validate(['username' => ['required', 'string'], 'name' => ['required', 'string'], 'email' => ['nullable', 'string'], 'roles' => ['required', 'array']]);
        $account = $accounts->create([
            'username' => trim((string) $request->input('username')),
            'name' => trim((string) $request->input('name')),
            'email' => $request->input('email') ?: null,
            'roles' => (array) $request->input('roles'),
        ]);

        return response()->json(['data' => ['id' => $account->model->getKey(), 'username' => $account->username, 'name' => $account->name, 'password' => $account->password]], 201);
    }

    /**
     * Change a user's login ID, name or email.
     */
    public function updateUser(Request $request, User $user, ManageUserAccounts $accounts): JsonResponse
    {
        $request->validate(['username' => ['required', 'string'], 'name' => ['required', 'string'], 'email' => ['nullable', 'string']]);
        $accounts->update($user, ['username' => trim((string) $request->input('username')), 'name' => trim((string) $request->input('name')), 'email' => $request->input('email') ?: null]);

        return $this->user($user->refresh());
    }

    /**
     * Set staff roles.
     *
     * `roles`: the staff roles the user should hold (admin, admin_payment, academic_monitor, superadmin); their
     * mahasantri, muhadhir or wali role stays.
     */
    public function roles(Request $request, User $user, ManageUserAccounts $accounts): JsonResponse
    {
        $request->validate(['roles' => ['present', 'array']]);
        $accounts->syncStaffRoles($user, array_values((array) $request->input('roles')), $request->user());

        return $this->user($user->refresh());
    }

    /**
     * Make a mahasantri or muhadhir a Petugas Pembayaran.
     *
     * `scope`: male (putra) or female (putri) bills, or null to remove the role.
     */
    public function collector(Request $request, User $user, ManageUserAccounts $accounts): JsonResponse
    {
        $request->validate(['scope' => ['nullable', Rule::enum(Gender::class)]]);
        $accounts->setCollector($user, Gender::tryFrom((string) $request->input('scope')));

        return $this->user($user->refresh());
    }

    /**
     * System settings.
     *
     * Payment rules, bank accounts shown to payers, the receipt footer and the attendance minimum.
     */
    public function settings(ManageSystem $system): JsonResponse
    {
        return response()->json(['data' => $system->read(ManageSystem::SETTINGS)]);
    }

    /**
     * Change system settings.
     *
     * Send every field (as returned by GET).
     */
    public function saveSettings(Request $request, ManageSystem $system): JsonResponse
    {
        $system->save(ManageSystem::SETTINGS, $request->all());

        return $this->settings($system);
    }

    /**
     * Institution identity.
     *
     * The details printed on documents, with previews of the institution logo and the name in Arabic
     * calligraphy.
     */
    public function identity(ManageSystem $system): JsonResponse
    {
        return response()->json(['data' => [...$system->read(ManageSystem::IDENTITY), 'images' => $this->images(array_keys(ManageSystem::IDENTITY_IMAGES))]]);
    }

    /**
     * Change the institution identity.
     */
    public function saveIdentity(Request $request, ManageSystem $system): JsonResponse
    {
        $system->save(ManageSystem::IDENTITY, $request->all());

        return $this->identity($system);
    }

    /**
     * App appearance.
     *
     * App name, short name, login texts (empty = the default), and previews of the app logo, icon and login
     * background.
     */
    public function appearance(ManageSystem $system): JsonResponse
    {
        return response()->json(['data' => [...$system->read(ManageSystem::APPEARANCE), 'images' => $this->images(array_keys(ManageSystem::APPEARANCE_IMAGES))]]);
    }

    /**
     * Change the app appearance.
     */
    public function saveAppearance(Request $request, ManageSystem $system): JsonResponse
    {
        $system->save(ManageSystem::APPEARANCE, $request->all());

        return $this->appearance($system);
    }

    /**
     * Upload a brand image.
     *
     * `image`: institution_logo, arabic_name, app_logo, app_icon or login_background; multipart `file` (PNG,
     * JPG, WebP; scaled down).
     */
    public function uploadImage(Request $request, ManageSystem $system): Response
    {
        $request->validate(['image' => ['required', Rule::in(array_keys(Branding::IMAGES))], 'file' => ['required', 'file']]);
        $system->storeImage((string) $request->input('image'), $request->file('file'));

        return response()->noContent();
    }

    /**
     * Remove a brand image.
     *
     * The built-in one is used again (the app logo and icon) or nothing is printed.
     */
    public function removeImage(string $image, ManageSystem $system): Response
    {
        $system->removeImage($image);

        return response()->noContent();
    }

    /**
     * Signatories, stamps and print defaults.
     *
     * Each with a preview of its image, and the default signatory, stamp and whether to print the images per
     * document (transcript, KHS, receipt, …).
     */
    public function signatures(ManageSystem $system): JsonResponse
    {
        return response()->json(['data' => [
            'signatories' => Signatory::query()->orderByDesc('is_active')->orderBy('name')->get()->map(fn (Signatory $signatory) => [
                ...$signatory->only(['id', 'name', 'position', 'office', 'is_active']),
                'signature_preview' => Branding::dataUri($signatory->signature_path),
            ])->values()->all(),
            'stamps' => Stamp::query()->orderByDesc('is_active')->orderBy('name')->get()->map(fn (Stamp $stamp) => [
                ...$stamp->only(['id', 'name', 'is_active']),
                'image_preview' => Branding::dataUri($stamp->image_path),
            ])->values()->all(),
            'defaults' => $system->printDefaults(),
        ]]);
    }

    /**
     * Add or change a signatory.
     *
     * Multipart: `name`, `position`, `office`, `is_active`, `signature` (image).
     */
    public function saveSignatory(Request $request, ManageSystem $system): JsonResponse
    {
        $request->validate(['id' => ['nullable', 'integer']]);
        $signatory = $system->saveSignatory($request->filled('id') ? Signatory::query()->findOrFail($request->integer('id')) : null, [
            ...$request->only(['name', 'position', 'office']),
            'is_active' => $request->boolean('is_active', true),
            'signature' => $request->file('signature'),
        ]);

        return response()->json(['data' => ['id' => $signatory->id]]);
    }

    /**
     * Add or change a stamp.
     *
     * Multipart: `name`, `is_active`, `image` (required for a new stamp).
     */
    public function saveStamp(Request $request, ManageSystem $system): JsonResponse
    {
        $request->validate(['id' => ['nullable', 'integer']]);
        $stamp = $system->saveStamp($request->filled('id') ? Stamp::query()->findOrFail($request->integer('id')) : null, [
            'name' => $request->input('name'),
            'is_active' => $request->boolean('is_active', true),
            'image' => $request->file('image'),
        ]);

        return response()->json(['data' => ['id' => $stamp->id]]);
    }

    /**
     * Set print defaults.
     *
     * `defaults`: {document: {signatory, stamp, images}}.
     */
    public function saveDefaults(Request $request, ManageSystem $system): Response
    {
        $request->validate(['defaults' => ['required', 'array']]);
        $system->savePrintDefaults((array) $request->input('defaults'));

        return response()->noContent();
    }

    /**
     * Letter types and numbering.
     *
     * Every letter type with its template text, signatory and stamp, and the number format.
     */
    public function letterTypes(): JsonResponse
    {
        return response()->json(['data' => [
            'numbering' => [
                'format' => Setting::get('letters.number_format'),
                'padding' => (int) Setting::get('letters.padding', 3),
            ],
            'types' => LetterType::query()->orderBy('name')->get()->map(fn (LetterType $type) => [
                ...$type->only(['id', 'name', 'code', 'requestable', 'template', 'signatory_id', 'stamp_id', 'print_marks', 'is_active']),
                /** none | student | teacher: who the letter is about */
                'subject' => $type->subject->value,
            ])->values()->all(),
        ]]);
    }

    /**
     * Add or change a letter type.
     *
     * `name`, `code`, `subject` (none, student, teacher), `requestable` (mahasantri may ask for it), `template`,
     * `signatory_id`, `stamp_id`, `print_marks`, `is_active`.
     */
    public function saveLetterType(Request $request, ManageLetters $letters): JsonResponse
    {
        $request->validate(['id' => ['nullable', 'integer'], 'name' => ['required', 'string'], 'code' => ['required', 'string'], 'subject' => ['required', 'string'], 'template' => ['required', 'string']]);
        $type = $letters->saveType($request->filled('id') ? LetterType::query()->findOrFail($request->integer('id')) : null, [
            'name' => trim((string) $request->input('name')),
            'code' => strtoupper(trim((string) $request->input('code'))),
            'subject' => $request->input('subject'),
            'requestable' => $request->boolean('requestable'),
            'template' => $request->input('template'),
            'signatory_id' => $request->integer('signatory_id') ?: null,
            'stamp_id' => $request->integer('stamp_id') ?: null,
            'print_marks' => $request->boolean('print_marks', true),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return response()->json(['data' => ['id' => $type->id]]);
    }

    /**
     * Change letter numbering.
     *
     * `format` (placeholders as on the web), `padding`, and the counter's `start_year` and `start_number`.
     */
    public function numbering(Request $request, ManageLetters $letters): Response
    {
        $request->validate(['format' => ['required', 'string'], 'padding' => ['required', 'integer'], 'start_year' => ['required', 'integer'], 'start_number' => ['required', 'integer']]);
        $letters->saveNumbering((string) $request->input('format'), $request->integer('padding'), $request->integer('start_year'), $request->integer('start_number'));

        return response()->noContent();
    }

    /**
     * Templates.
     *
     * Every template people download (imports, teaching documents, letters): whether a replacement is in use,
     * and a link to the current file.
     */
    public function templates(Request $request): JsonResponse
    {
        $current = DocumentTemplate::query()->with('uploader')->get()->groupBy('key')->map(fn ($versions) => $versions->sortByDesc('version')->first());
        $user = $request->user();
        $role = ActiveRole::get();

        return response()->json(['data' => collect(Templates::slots())->map(function (array $slot, string $key) use ($current, $user, $role) {
            /** @var DocumentTemplate|null $template */
            $template = $current->get($key);

            return [
                'key' => $key,
                'label' => $slot['label'],
                'group' => $slot['group'],
                'format' => $slot['format'],
                'replaced' => $template?->file_path !== null,
                'version' => $template?->version,
                'uploaded_by' => $template?->uploader?->name,
                'download_url' => ApiFiles::download($user, $role, 'template', ['key' => $key]),
            ];
        })->values()->all()]);
    }

    /**
     * Replace a template.
     *
     * Multipart `file` (xlsx or docx, as the slot); later downloads use it.
     */
    public function uploadTemplate(Request $request, string $key): Response
    {
        $request->validate(['file' => ['required', 'file']]);
        Templates::upload($key, $request->file('file'), $request->user());

        return response()->noContent();
    }

    /**
     * Go back to the built-in template.
     */
    public function revertTemplate(Request $request, string $key): Response
    {
        Templates::revert($key, $request->user());

        return response()->noContent();
    }

    /**
     * Audit log.
     *
     * Every change with who made it, the old and new values; `event` (created, updated, deleted), `search` (record
     * type or id, user name or login); newest first, paginated.
     */
    public function audit(Request $request): JsonResponse
    {
        $request->validate(['event' => ['nullable', 'string'], 'search' => ['nullable', 'string']]);
        $search = trim((string) $request->input('search'));
        $term = '%'.mb_strtolower($search).'%';

        $logs = AuditLog::query()->with('user:id,name,username')
            ->when($request->filled('event'), fn (Builder $query) => $query->where('event', $request->input('event')))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query->whereRaw('lower(auditable_type) like ?', [$term])
                ->orWhere('auditable_id', $search)
                ->orWhereHas('user', fn (Builder $query) => $query->whereRaw('lower(name) like ?', [$term])->orWhere('username', 'like', $term))))
            ->latest('id')->paginate(25);

        return response()->json([
            'data' => collect($logs->items())->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'event' => $log->event,
                'record' => class_basename($log->auditable_type),
                'record_id' => $log->auditable_id,
                'old' => $log->old_values,
                'new' => $log->new_values,
                'user' => $log->user?->name,
                'ip_address' => $log->ip_address,
                'in_maintenance' => $log->maintenance_session_id !== null,
                'created_at' => $log->created_at->toIso8601String(),
            ])->values()->all(),
            'meta' => ['current_page' => $logs->currentPage(), 'last_page' => $logs->lastPage(), 'total' => $logs->total()],
        ]);
    }

    /**
     * @param  list<string>  $images
     * @return array<string, string|null> image => preview (data URI)
     */
    private function images(array $images): array
    {
        return collect($images)->mapWithKeys(fn (string $image) => [$image => Branding::dataUri(Branding::imagePath($image))])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(User $user): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'name' => $user->name,
            'email' => $user->email,
            'active' => $user->is_active,
            'roles' => array_map(fn (Role $role) => $role->value, $user->roleList()),
            'two_factor_enabled' => $user->hasEnabledTwoFactorAuthentication(),
        ];
    }
}
