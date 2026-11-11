<?php

use App\Http\Middleware\ExigerMotDePasseAJour;
use App\Http\Middleware\SetLocale;
use App\Jobs\ArchiverCourriersTraitesJob;
use App\Jobs\CalculerOccupationStockageJob;
use App\Models\OccupationStockage;
use App\Jobs\ConvertirArchivesPdfaJob;
use App\Jobs\PreparerApercusManquantsJob;
use App\Jobs\RecalculerConservationJob;
use App\Jobs\RefreshDashboardStatsJob;
use App\Jobs\RefreshStatistiquesJob;
use App\Jobs\SauvegarderDocumentsLocalementJob;
use App\Jobs\SendMailAlertJob;
use App\Jobs\VerifierIntegriteFichiersJob;
use App\Services\EtatSysteme;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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
        // 2026-11-11 — ExigerMotDePasseAJour : mot de passe expiré ou jamais
        // choisi, renouvellement obligatoire avant toute page.
        $middleware->web(append: [SetLocale::class, ExigerMotDePasseAJour::class]);
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

        // Module 10 étendu (2026-10-30) — cubes de la page « Dashboard statistiques ».
        $schedule->job(new RefreshStatistiquesJob)
            ->everyFifteenMinutes()
            ->name('statistiques-page')
            ->withoutOverlapping();

        // Module 9 — conformité de l'archivage (2026-10-30, voir DECISIONS.md) :
        // intégrité des fichiers chaque nuit, échéances de conservation chaque
        // nuit, rattrapage des copies PDF/A chaque heure.
        $schedule->job(new VerifierIntegriteFichiersJob)
            ->dailyAt('02:00')
            ->name('integrite-fichiers')
            ->withoutOverlapping();

        $schedule->job(new RecalculerConservationJob)
            ->dailyAt('03:00')
            ->name('conservation-archives')
            ->withoutOverlapping();

        // Rattrapage (2026-11-09) : une tâche de nuit manquée (machine éteinte
        // à 02:00/03:00) n'est jamais rejouée par le planificateur — constaté
        // du 30/10 au 05/11 sur le poste de développement. Si le dernier
        // passage date de plus de 26 h (ou n'a jamais eu lieu), elle repart à
        // la demi-heure suivante ; sur un serveur allumé la nuit, la
        // condition n'est jamais vraie. À la demi-heure pour ne jamais tomber
        // en même temps que 02:00/03:00.
        $enRetard = fn (string $cle) => fn () => ($dernier = Cache::get($cle)) === null
            || Carbon::parse($dernier)->lt(now()->subHours(26));

        $schedule->job(new VerifierIntegriteFichiersJob)
            ->hourlyAt(30)
            ->when($enRetard(VerifierIntegriteFichiersJob::CLE_DERNIERE_VERIFICATION))
            ->name('integrite-fichiers-rattrapage')
            ->withoutOverlapping();

        $schedule->job(new RecalculerConservationJob)
            ->hourlyAt(30)
            ->when($enRetard(RecalculerConservationJob::CLE_DERNIER_PASSAGE))
            ->name('conservation-archives-rattrapage')
            ->withoutOverlapping();

        $schedule->job(new ConvertirArchivesPdfaJob)
            ->hourly()
            ->name('archives-pdfa')
            ->withoutOverlapping();

        // 2026-11-09 — occupation du stockage (Sécurité & Audit › Stockage) :
        // chaque nuit, avec le même rattrapage que les tâches ci-dessus.
        $schedule->job(new CalculerOccupationStockageJob)
            ->dailyAt('04:00')
            ->name('occupation-stockage')
            ->withoutOverlapping();

        $schedule->job(new CalculerOccupationStockageJob)
            ->hourlyAt(45)
            ->when(fn () => ! OccupationStockage::where('calcule_le', '>', now()->subHours(26))->exists())
            ->name('occupation-stockage-rattrapage')
            ->withoutOverlapping();

        // 2026-11-09 — pages de l'aperçu sécurisé des documents antérieurs
        // (voir App\Services\ApercuSecurise), par lots.
        $schedule->job(new PreparerApercusManquantsJob)
            ->hourlyAt(15)
            ->when(fn () => (bool) config('services.archivage.apercu_auto'))
            ->name('apercus-securises')
            ->withoutOverlapping();

        // 2026-11-11 — copie de secours sur le PC, rangée par dossier de
        // classement (ZIP chiffrés, voir App\Services\SauvegardeLocale) :
        // incrémentale, chaque heure ; rien tant qu'elle n'est pas configurée.
        $schedule->job(new SauvegarderDocumentsLocalementJob)
            ->hourlyAt(50)
            ->name('sauvegarde-locale')
            ->withoutOverlapping();

        // Battement du planificateur (2026-10-30) : s'il cesse (aucun
        // schedule:run / schedule:work), alertes, archivage et statistiques
        // s'arrêtent sans erreur visible — constaté du 20 au 30/10. Sécurité
        // & Audit et un bandeau administrateur le signalent (voir
        // App\Services\EtatSysteme).
        $schedule->call(fn () => Cache::forever(EtatSysteme::CLE_BATTEMENT, now()->toIso8601String()))
            ->everyMinute()
            ->name('battement-planificateur');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
