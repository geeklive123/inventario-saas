<?php

namespace App\Http\Middleware;

use App\Support\Authorization\CompanyAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function __construct(private CompanyAccess $access) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(
        Request $request,
        Closure $next,
        string $permissionCode,
    ): Response {
        $user = $request->user();

        abort_if($user === null, Response::HTTP_FORBIDDEN);
        abort_unless(
            $this->access->allowsCurrent($user, $permissionCode, ! $request->isMethodSafe()),
            Response::HTTP_FORBIDDEN,
        );

        return $next($request);
    }
}
