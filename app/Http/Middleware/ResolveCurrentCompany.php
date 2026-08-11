<?php

namespace App\Http\Middleware;

use App\Enums\CompanyStatus;
use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Support\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveCurrentCompany
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $membershipId = $request->header('X-Membership-ID') ?? $request->session()->get('current_membership_id');

        abort_if($user === null || ! is_numeric($membershipId), Response::HTTP_FORBIDDEN);

        $membership = Membership::query()
            ->withoutGlobalScope('company')
            ->with('company')
            ->whereKey((int) $membershipId)
            ->where('user_id', $user->getKey())
            ->where('status', MembershipStatus::Active)
            ->first();

        abort_if(
            $membership === null || $membership->company->status !== CompanyStatus::Active,
            Response::HTTP_FORBIDDEN,
        );

        app(CurrentCompany::class)->set($membership);

        return $next($request);
    }
}
