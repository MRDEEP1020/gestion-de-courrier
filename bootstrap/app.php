<?php

use App\Http\Middleware\SetLocale;
use App\Jobs\ArchiverCourriersTraitesJob;
use App\Jobs\RefreshDashboardStatsJob;
use App\Jobs\SendMailAlertJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Bascule FR/EN demandée par l'utilisateur (2026-09-07) — voir
        // App\Http\Middleware\SetLocale et DECISIONS.md.
        $middleware->web(append: [SetLocale::class]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        // Module 9 — PREMIÈRE tâche planifiée de tout ce projet (voir
        // DECISIONS.md : le Scheduler n'avait jamais été câblé avant ce
        // changement, "Dossiers & Archives", 2026-09-21). Nécessite un vrai
        // cron `* * * * * php artisan schedule:run` en production (ou
        // `php artisan schedule:work` en développement) — sans ça, rien ne
        // se déclenche, y compris en production.
        $schedule->job(new ArchiverCourriersTraitesJob)
            ->everyFifteenMinutes()
            ->name('archiver-courriers-traites')
            ->withoutOverlapping();

        // Module 5/7 — alertes SLA "bientôt en retard"/"en retard" + relances
        // (2026-09-23, voir DECISIONS.md "SLA et alertes"). Toutes les
        // heures : le SLA se compte en jours, l'anti-doublon est dans le job.
        $schedule->job(new SendMailAlertJob)
            ->hourly()
            ->name('alertes-sla')
            ->withoutOverlapping();

        // Module 10 — délai moyen de traitement du tableau de bord.
        $schedule->job(new RefreshDashboardStatsJob)
            ->everyFifteenMinutes()
            ->name('statistiques-tableau-de-bord')
            ->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
