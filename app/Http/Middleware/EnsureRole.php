<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Support\ActiveRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to users currently acting as one of the given roles,
 * e.g. "role:admin,superadmin".
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $allowed = array_map(fn (string $role) => Role::from($role), $roles);

        abort_unless(in_array(ActiveRole::get(), $allowed, true), Response::HTTP_FORBIDDEN);

        return $next($request);
    }
}
