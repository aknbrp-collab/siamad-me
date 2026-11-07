<?php

namespace App\Http\Middleware\Api;

use App\Enums\Role;
use App\Models\User;
use App\Support\ActiveRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The role an API request acts in (v6 D37): the X-Role header, checked
 * against the user's roles as on the web. Users with one role may leave it
 * out. Routes that need a role then use the usual "role:" middleware.
 */
class ResolveApiRole
{
    public const HEADER = 'X-Role';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $header = trim((string) $request->header(self::HEADER));
        $roles = $user->roleList();

        if ($header !== '') {
            $role = Role::tryFrom($header);

            if ($role === null || ! in_array($role, $roles, true)) {
                return response()->json([
                    'message' => __('You do not hold this role.'),
                    'code' => 'role_not_held',
                ], Response::HTTP_FORBIDDEN);
            }
        } else {
            $role = count($roles) === 1 ? $roles[0] : null;
        }

        if ($role !== null) {
            $request->attributes->set(ActiveRole::REQUEST_KEY, $role);
        }

        return $next($request);
    }
}
