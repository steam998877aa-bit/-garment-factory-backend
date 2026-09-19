<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restrict a route to one or more named roles.
 *
 * Usage: ->middleware('role:Admin,HR')
 */
class EnsureUserHasRole
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): \Symfony\Component\HttpFoundation\Response  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! in_array($user->role?->name, $roles, true)) {
            return response()->json([
                'message' => 'This action is not authorised for your role.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
