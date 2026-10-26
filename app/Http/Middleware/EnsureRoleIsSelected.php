<?php

namespace App\Http\Middleware;

use App\Support\ActiveRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes sure the user is acting as one of their roles: selects it
 * automatically when they have one, otherwise asks them to choose.
 */
class EnsureRoleIsSelected
{
    public function handle(Request $request, Closure $next): Response
    {
        if (ActiveRole::get() !== null) {
            return $next($request);
        }

        $roles = $request->user()->roleList();

        abort_if($roles === [], Response::HTTP_FORBIDDEN, __('No role has been assigned to this account.'));

        if (count($roles) > 1) {
            return redirect()->route('role.select');
        }

        ActiveRole::set($roles[0]);

        return $next($request);
    }
}
