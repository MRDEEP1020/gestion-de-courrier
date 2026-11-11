<?php

namespace App\Providers;

use App\Listeners\EnregistrerDerniereConnexion;
use App\Models\Courrier;
use App\Models\DossierClassement;
use App\Models\PieceJointe;
use App\Models\User;
use App\Services\ChiffrementFichiers;
use App\Services\SauvegardeLocale;
use App\Services\StockageChiffre;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use League\Flysystem\Filesystem;
use Illuminate\Support\Facades\Vite;
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
        $this->app->singleton(ChiffrementFichiers::class, fn () => ChiffrementFichiers::depuisConfiguration());
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

        $this->declarerLeDisqueChiffre();

        $this->tenirLaCopieDuPcAJour();

        $this->servirLesAssetsCompilesAuxVisiteurs();
    }

    // 2026-11-11 (« est-ce qu'elle serait créée automatiquement chaque fois
    // qu'un utilisateur va créer un dossier ») — copie de secours sur le PC
    // (App\Services\SauvegardeLocale) : un dossier créé, renommé, déplacé ou
    // supprimé, un courrier enregistré, classé ou supprimé, une pièce jointe
    // ajoutée → mise à jour dans les secondes qui suivent.
    private function tenirLaCopieDuPcAJour(): void
    {
        $signaler = fn () => SauvegardeLocale::signalerChangement();

        foreach ([DossierClassement::class, Courrier::class, PieceJointe::class] as $modele) {
            $modele::saved($signaler);
            $modele::deleted($signaler);
        }

        DossierClassement::restored($signaler);
        Courrier::restored($signaler);

        // Copie personnelle : mot de passe de sauvegarde défini ou retiré,
        // compte activé/désactivé, nom ou accès changé — pas à chaque
        // enregistrement d'un compte (la date de dernière connexion change
        // à chaque connexion).
        User::saved(function (User $user) use ($signaler) {
            if ($user->wasChanged(['mot_de_passe_sauvegarde', 'actif', 'name', 'profil_id', 'service_id', 'niveau_confidentialite'])) {
                $signaler();
            }
        });
        User::deleted($signaler);
    }

    // 2026-11-09, voir DECISIONS.md « Fichiers : chiffrement, aperçu
    // sécurisé, aucun téléchargement » — pilote « s3-chiffre » des disques
    // s3 et s3_backup : le même S3/MinIO, enveloppé par StockageChiffre.
    private function declarerLeDisqueChiffre(): void
    {
        Storage::extend('s3-chiffre', function ($app, array $config) {
            $s3 = $app['filesystem']->createS3Driver($config);
            $chiffre = new StockageChiffre($s3->getAdapter(), $app->make(ChiffrementFichiers::class));

            return new FilesystemAdapter(
                new Filesystem($chiffre, Arr::only($config, ['directory_visibility', 'disable_asserts', 'retain_visibility', 'temporary_url', 'url', 'visibility'])),
                $chiffre,
                $config,
            );
        });
    }

    // 2026-10-27, voir DECISIONS.md "Partage local : assets compilés pour les
    // visiteurs" — le serveur Vite de `php artisan dev` n'est joignable que
    // depuis ce poste (https://gecs.test:5173) : un visiteur via Expose ou
    // le réseau du bureau (http://<IP>:8090) recevait une page sans CSS. Tout
    // hôte autre que celui d'APP_URL ignore donc public/hot et reçoit
    // public/build (à régénérer par `npm run build` après une modification
    // de style). Local uniquement : aucun fichier hot n'existe en production.
    // Même traitement pour un fichier hot VIDE (constaté plusieurs fois le
    // 2026-10-27 : deux serveurs Vite concurrents, celui de Herd et un
    // `npm run dev` lancé à la main) — sinon page sans CSS ni JS même sur
    // https://gecs.test.
    private function servirLesAssetsCompilesAuxVisiteurs(): void
    {
        if (! $this->app->environment('local') || $this->app->runningInConsole()) {
            return;
        }

        $fichierHot = public_path('hot');
        $hotVide = is_file($fichierHot) && trim((string) file_get_contents($fichierHot)) === '';

        if ($hotVide || request()->getHost() !== parse_url((string) config('app.url'), PHP_URL_HOST)) {
            Vite::useHotFile(storage_path('framework/vite-hot-ignore'));
        }
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
