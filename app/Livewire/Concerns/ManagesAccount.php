<?php

namespace App\Livewire\Concerns;

use App\Actions\People\ResetUserPassword;
use App\Models\User;
use App\Support\ActiveRole;
use App\Support\CreatedAccount;
use App\Support\SpreadsheetDownload;
use Flux\Flux;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Account controls shared by the mahasantri, ustadz and wali santri forms:
 * the one-time credentials shown after creating or resetting a login, and
 * enabling / disabling the login.
 */
trait ManagesAccount
{
    /** Login ID and temporary password, shown once in the credentials modal. */
    public ?string $credentialUsername = null;

    public ?string $credentialName = null;

    public ?string $credentialPassword = null;

    abstract protected function accountUser(): ?User;

    protected function showCredentials(CreatedAccount $account): void
    {
        $this->credentialUsername = $account->username;
        $this->credentialName = $account->name;
        $this->credentialPassword = $account->password;

        Flux::modal('credentials')->show();
    }

    public function resetPassword(ResetUserPassword $resetUserPassword): void
    {
        $user = $this->accountUser();
        abort_if($user === null, 404);
        abort_unless($user->accountManageableBy(ActiveRole::get()), 403);

        $this->credentialUsername = $user->username;
        $this->credentialName = $user->name;
        $this->credentialPassword = $resetUserPassword->handle($user);

        Flux::modal('credentials')->show();
    }

    public function toggleActive(): void
    {
        $user = $this->accountUser();
        abort_if($user === null, 404);
        abort_if($user->is(auth()->user()), 403);
        abort_unless($user->accountManageableBy(ActiveRole::get()), 403);

        $user->update(['is_active' => ! $user->is_active]);

        Flux::toast(
            variant: $user->is_active ? 'success' : 'warning',
            text: $user->is_active ? __('Login enabled.') : __('Login disabled. The user is signed out on their next request.'),
        );
    }

    public function downloadCredentials(): BinaryFileResponse
    {
        abort_if($this->credentialPassword === null, 404);

        return SpreadsheetDownload::credentials('login-'.$this->credentialUsername.'.xlsx', [
            new CreatedAccount(auth()->user(), (string) $this->credentialUsername, (string) $this->credentialName, $this->credentialPassword),
        ]);
    }
}
