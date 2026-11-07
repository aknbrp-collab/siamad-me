<?php

namespace App\Http\Middleware\Api;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The same account rules as the web portal, for API tokens (v6 D37):
 * a deactivated account loses the token, and a temporary password must be
 * changed (PUT /password) before anything else.
 */
class EnsureApiAccount
{
    /** Routes a user with a temporary password may still call. */
    private const BEFORE_PASSWORD_CHANGE = ['api.v1.me', 'api.v1.password', 'api.v1.logout', 'api.v1.app'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if (! $user->is_active) {
            $user->currentAccessToken()->delete();

            return response()->json([
                'message' => __('This account has been deactivated. Please contact the academic office.'),
                'code' => 'account_inactive',
            ], Response::HTTP_UNAUTHORIZED);
        }

        if ($user->must_change_password && ! $request->routeIs(...self::BEFORE_PASSWORD_CHANGE)) {
            return response()->json([
                'message' => __('You are using a temporary password. Choose a new password to continue.'),
                'code' => 'password_change_required',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
