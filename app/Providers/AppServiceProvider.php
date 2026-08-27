<?php

namespace App\Providers;

use App\Http\Middleware\EnsureAnyPermission;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\ResolveCurrentCompany;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentCompany::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Livewire::addPersistentMiddleware([
            ResolveCurrentCompany::class,
            EnsurePasswordChanged::class,
            EnsureModuleEnabled::class,
            EnsurePermission::class,
            EnsureAnyPermission::class,
        ]);

        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
