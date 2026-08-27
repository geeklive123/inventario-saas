<?php

namespace App\Http\Middleware;

use App\Support\Authorization\CompanyAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAnyPermission
{
    public function __construct(private CompanyAccess $access) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$permissionCodes): Response
    {
        $user = $request->user();

        abort_if($user === null || $permissionCodes === [], Response::HTTP_FORBIDDEN);
        abort_unless(
            collect($permissionCodes)->contains(
                fn (string $permissionCode): bool => $this->access->allowsCurrent(
                    $user,
                    $permissionCode,
                    mutation: false,
                ),
            ),
            Response::HTTP_FORBIDDEN,
        );

        return $next($request);
    }
}
