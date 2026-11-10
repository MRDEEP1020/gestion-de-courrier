<?php

use App\Http\Controllers\ApercuManifesteController;
use App\Http\Controllers\ApercuModeleDechargeController;
use App\Http\Controllers\ApercuPageController;
use App\Http\Controllers\CourrierAccuseReceptionController;
use App\Http\Controllers\CourrierBordereauController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\TransfertDechargeController;
use App\Http\Controllers\UtilisateurPhotoController;
use App\Livewire\Backend\CourrierCalendar;
use App\Livewire\Backend\CourrierList;
use App\Livewire\Backend\CourriersEnregistres;
use App\Livewire\Backend\Dashboard;
use App\Livewire\Backend\DossierClassementList;
use App\Livewire\Backend\DossierSurveille;
use App\Livewire\Backend\EditForm;
use App\Livewire\Backend\MesCourriers;
use App\Livewire\Backend\Statistiques;
use App\Livewire\Backend\NotificationsIndex;
use App\Livewire\Backend\OrganisationIndex;
use App\Livewire\Backend\ParametreSysteme;
use App\Livewire\Backend\ProfilList;
use App\Livewire\Backend\RegistrationForm;
use App\Livewire\Backend\RegleList;
use App\Livewire\Backend\ScanForm;
use App\Livewire\Backend\ScanPremier;
use App\Livewire\Backend\SecuriteAudit;
use App\Livewire\Backend\ShowCourrier;
use App\Livewire\Backend\UserList;
use App\Services\ApercuSecurise;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

// Bascule FR/EN demandée par l'utilisateur (2026-09-07) — voir
// App\Http\Middleware\SetLocale et DECISIONS.md. Hors du groupe
// auth/verified : un visiteur non connecté doit pouvoir changer de langue
// aussi.
Route::get('langue/{locale}', LocaleController::class)->name('langue.changer');

Route::middleware(['auth', 'verified'])->group(function () {
    // Module 10 — tableau de bord (maquette GPT, 2026-09-16, voir
    // DECISIONS.md "Tableau de bord") : remplace le placeholder statique du
    // starter-kit par un vrai composant Livewire (statistiques/actions
    // rapides filtrées par privilège).
    Route::livewire('dashboard', Dashboard::class)->name('dashboard');

    // Module 7 — centre de notifications in-app (2026-10-05) : page complète
    // derrière la cloche de l'en-tête (resources/views/components live wire
    // notification-bell), qui ne montre qu'un aperçu des 8 dernières.
    Route::livewire('notifications', NotificationsIndex::class)->name('notifications.index');

    Route::livewire('courriers/nouveau', RegistrationForm::class)->name('courriers.nouveau');
    // Module 9 — aperçu sécurisé (2026-11-09, voir DECISIONS.md « Fichiers :
    // chiffrement, aperçu sécurisé, aucun téléchargement ») : pages images
    // filigranées d'un courrier, d'une pièce jointe ou d'un brouillon. AUCUNE
    // route ne sert plus le fichier original (anciennes routes
    // courriers.document, courriers.document.apercu, pieces-jointes.telecharger,
    // brouillons.apercu et brouillons.telecharger supprimées).
    Route::get('apercus/{type}/{id}', ApercuManifesteController::class)
        ->whereIn('type', ApercuSecurise::TYPES)->whereNumber('id')->name('apercus.manifeste');
    Route::get('apercus/{type}/{id}/{page}', ApercuPageController::class)
        ->whereIn('type', ApercuSecurise::TYPES)->whereNumber(['id', 'page'])->name('apercus.page');
    // Photo de profil servie par l'application (bucket privé, voir
    // UtilisateurPhotoController) — 2026-10-26.
    Route::get('utilisateurs/{user}/photo', UtilisateurPhotoController::class)->name('utilisateurs.photo');
    // Module 1 — "Cas particulier : courrier confidentiel" (specifications-modules-GEC.md).
    // 2026-10-07 — sert désormais le MÊME composant que courriers.nouveau
    // (voir DECISIONS.md "fusion explicitement demandée") : ce nom de
    // route reste distinct (liens existants du tableau de bord/sidebar
    // inchangés) mais RegistrationForm::mount() détecte
    // request()->routeIs('courriers.confidentiel') pour initialiser
    // $modeConfidentiel à true ET revérifier authorize('creerConfidentiel', ...)
    // à cet instant précis — un accès direct sans le privilège reste donc
    // un vrai 403, comme avant la fusion.
    Route::livewire('courriers/confidentiel', RegistrationForm::class)->name('courriers.confidentiel');
    // Module 1/2 — flux "scan d'abord" (voir DECISIONS.md "Flux scan-first").
    Route::livewire('courriers/numeriser', ScanPremier::class)->name('courriers.numeriser-nouveau');
    // Module 4 — ancienne file d'attente "Transferts" (WorkflowQueue),
    // supprimée le 2026-09-24 à la demande de l'utilisateur (doublon de
    // "Tous les courriers") : l'adresse redirige vers la liste filtrée sur
    // les courriers encore en circuit, pour ne casser aucun lien existant.
    Route::redirect('courriers/a-traiter', '/courriers/rechercher?statut=actifs')->name('courriers.a-traiter');
    // Module 3/8 — recherche multi-critères ; DOIT rester avant
    // courriers/{courrierId} ci-dessous, sinon "rechercher" serait capturé
    // comme un ID de courrier par la route générique.
    Route::livewire('courriers/rechercher', CourrierList::class)->name('courriers.rechercher');
    // Vue calendrier des échéances SLA (2026-10-05) — DOIT rester avant la
    // route générique courriers/{courrierId}, même raison que ci-dessus.
    Route::livewire('courriers/calendrier', CourrierCalendar::class)->name('courriers.calendrier');
    // Module 1/4 — page dédiée réceptionniste (2026-09-15, "new page for
    // receptionist") : ses courriers entrant, par sous-statut de transfert.
    // Même raison que ci-dessus : DOIT rester avant la route générique.
    Route::livewire('courriers/mes-courriers', MesCourriers::class)->name('courriers.mes-courriers');
    // Module 10 étendu (2026-10-30) — « Dashboard statistiques ».
    Route::livewire('statistiques', Statistiques::class)->name('statistiques.index');
    // Module 1/4/8 — "Courriers enregistrés" (maquette GPT, 2026-09-16,
    // voir DECISIONS.md "Navigation (navbar + sidebar)") : mêmes 3 onglets
    // que "Mes courriers" ci-dessus, mais à l'échelle de ce que
    // l'utilisateur peut voir (périmètre CourrierList), pas limité à ses
    // propres courriers. Même raison que ci-dessus : DOIT rester avant la
    // route générique.
    Route::livewire('courriers/enregistres', CourriersEnregistres::class)->name('courriers.enregistres');
    Route::livewire('courriers/{courrierId}/modifier', EditForm::class)->name('courriers.modifier');
    Route::livewire('courriers/{courrierId}/numeriser', ScanForm::class)->name('courriers.numeriser');
    Route::get('courriers/{courrier}/bordereau', CourrierBordereauController::class)->name('courriers.bordereau');
    Route::get('courriers/{courrier}/accuse-reception', CourrierAccuseReceptionController::class)->name('courriers.accuse-reception');
    // Décharge imprimable de la remise de l'original au service (2026-10-27).
    Route::get('transferts/{transfert}/decharge', TransfertDechargeController::class)->name('transferts.decharge');
    Route::livewire('courriers/{courrierId}', ShowCourrier::class)->name('courriers.show');

    // Module 3/9 — "Dossiers & Archives" (arborescence de classement +
    // archivage automatique, 2026-09-21). Garde via mount() ->
    // $this->authorize('viewAny', DossierClassement::class).
    Route::livewire('dossiers-classement', DossierClassementList::class)->name('dossiers-classement.index');

    // Module 3 — paramétrage des règles de classement (Administrateur, voir RegleClassementPolicy)
    Route::livewire('admin/regles-classement', RegleList::class)->name('admin.regles');
    // Module "Organisation" v2 (2026-09-22, spec technique complète fournie
    // par l'utilisateur) — remplace la page plate "Services" : hiérarchie
    // dynamique Company/Site/Department/Service/Sub-service, rattachement
    // utilisateur, cascade de transfert de courrier, périmètre de permission.
    Route::livewire('admin/organisation', OrganisationIndex::class)->name('admin.organisation');
    // Page séparée (2026-09-15) : assignation des privilèges PAR PROFIL.
    Route::livewire('admin/profils', ProfilList::class)->name('admin.profils');
    // Page "Utilisateurs & Accès" reconstruite le 2026-09-21 depuis la
    // maquette fournie par l'utilisateur — table + modales
    // ajouter/modifier/gérer les permissions PAR UTILISATEUR. Le catalogue
    // de privilèges (ex-/admin/privileges) est désormais fixe, plus de page
    // dédiée (voir DECISIONS.md, "Système de privilèges").
    Route::livewire('admin/utilisateurs', UserList::class)->name('admin.utilisateurs');
    // Module 1/5/7 — "Paramètres système" (2026-09-23, voir DECISIONS.md
    // "Paramètres système configurables") : remplace l'entrée sidebar
    // "SLA & Alertes" (jusque-là "Bientôt disponible") par une vraie page ;
    // numéro de référence + SLA, éditables sans redéploiement.
    Route::livewire('admin/parametres', ParametreSysteme::class)->name('admin.parametres');
    // Aperçu PDF du modèle de décharge saisi, avant enregistrement (2026-10-28).
    Route::post('admin/parametres/decharge/apercu', ApercuModeleDechargeController::class)->name('admin.parametres.decharge.apercu');
    // Module 1/2 — configuration du dossier surveillé (2026-09-24, voir
    // DECISIONS.md "Dossier surveillé : configuration dans l'administration").
    Route::livewire('admin/dossier-surveille', DossierSurveille::class)->name('admin.dossier-surveille');
    // Module 9 — Sécurité & Audit (2026-10-30, voir DECISIONS.md "Archivage :
    // conformité") : journal des consultations, intégrité, conservation.
    Route::livewire('admin/securite-audit', SecuriteAudit::class)->name('admin.securite-audit');
    // 2026-10-23 — page "Délégation DGA" retirée avec le routage DGA (voir
    // DECISIONS.md "Transfert direct au service et acceptation").
});

require __DIR__.'/settings.php';
