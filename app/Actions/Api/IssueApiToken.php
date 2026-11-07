<?php

namespace App\Actions\Api;

use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Laravel\Sanctum\NewAccessToken;

/**
 * Login from the mobile app (v6 D37): ID number and password, plus the
 * two-factor code (or a recovery code) when the account has 2FA. Each device
 * gets its own token, named after the device.
 */
class IssueApiToken
{
    public const PLATFORMS = ['android', 'ios', 'web', 'other'];

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            /** NIM, NIDM, guardian code or staff login ID. */
            'username' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'max:255'],
            /** Shown in the list of devices, e.g. "Samsung A54". */
            'device_name' => ['required', 'string', 'max:100'],
            'platform' => ['nullable', Rule::in(self::PLATFORMS)],
            /** The 6-digit code of the authenticator app, after `two_factor_required`. */
            'code' => ['nullable', 'string', 'max:10'],
            /** Instead of `code`: one of the recovery codes. */
            'recovery_code' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(array $input): NewAccessToken
    {
        $data = Validator::make($input, self::rules(), [], ['username' => __('ID number'), 'device_name' => __('device name')])->validate();

        $user = User::query()->where('username', $data['username'])->first();

        if ($user === null || ! Hash::check($data['password'], $user->password)) {
            $this->fail('username', __('The ID number or password is incorrect.'));
        }

        // Only reveal the account state once the password has been proven.
        if (! $user->is_active) {
            $this->fail('username', __('This account has been deactivated. Please contact the academic office.'));
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            $this->checkTwoFactor($user, $data['code'] ?? null, $data['recovery_code'] ?? null);
        }

        $token = $user->createToken(trim($data['device_name']));
        $token->accessToken->forceFill(['platform' => $data['platform'] ?? null])->save();

        return $token;
    }

    private function checkTwoFactor(User $user, ?string $code, ?string $recoveryCode): void
    {
        if (filled($recoveryCode)) {
            if (! in_array($recoveryCode, $user->recoveryCodes(), true)) {
                $this->fail('recovery_code', __('The provided two factor recovery code was invalid.'));
            }

            $user->replaceRecoveryCode($recoveryCode);

            return;
        }

        // The app then asks for the code and sends the login again with it.
        if (blank($code)) {
            $message = __('Enter the code from your authenticator app.');

            throw new HttpResponseException(response()->json([
                'message' => $message,
                'code' => 'two_factor_required',
                'errors' => ['code' => [$message]],
            ], 422));
        }

        $valid = app(TwoFactorAuthenticationProvider::class)->verify(
            Fortify::currentEncrypter()->decrypt((string) $user->two_factor_secret),
            (string) $code,
        );

        if (! $valid) {
            $this->fail('code', __('The provided two factor authentication code was invalid.'));
        }
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
