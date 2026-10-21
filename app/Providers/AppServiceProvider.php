<?php

namespace App\Providers;

use App\Listeners\EnregistrerDerniereConnexion;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Composants Livewire GEC sous app/Livewire/Backend (voir DECISIONS.md) —
        // Livewire ne connaît par défaut que App\Livewire (config('livewire.class_namespace')),
        // il faut donc lui déclarer explicitement ce second emplacement de classes.
        Livewire::addLocation(classNamespace: 'App\\Livewire\\Backend');

        // Vues des composants Livewire GEC (resources/views/livewire/frontend) —
        // séparées du reste du starter kit (resources/views/livewire/*), voir DECISIONS.md.
        View::addNamespace('frontend', resource_path('views/livewire/frontend'));

        // Page "Utilisateurs & Accès" (2026-09-21) — colonne "Dernière
        // connexion" : voir EnregistrerDerniereConnexion.
        Event::listen(Login::class, EnregistrerDerniereConnexion::class);
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
