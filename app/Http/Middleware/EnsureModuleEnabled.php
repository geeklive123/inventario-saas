<?php

namespace App\Http\Middleware;

use App\Enums\CompanyModuleStatus;
use App\Models\CompanyModule;
use App\Support\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureModuleEnabled
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $moduleCode): Response
    {
        $currentCompany = app(CurrentCompany::class);
        abort_unless($currentCompany->isResolved(), Response::HTTP_FORBIDDEN);

        $companyModule = CompanyModule::query()
            ->where('company_id', $currentCompany->id())
            ->whereHas('module', fn ($query) => $query
                ->where('code', $moduleCode)
                ->where('is_active', true))
            ->first();

        abort_if($companyModule === null, Response::HTTP_FORBIDDEN);

        if (! $request->isMethodSafe()) {
            abort_unless(
                $companyModule->status === CompanyModuleStatus::Enabled,
                Response::HTTP_FORBIDDEN,
            );
        }

        return $next($request);
    }
}
