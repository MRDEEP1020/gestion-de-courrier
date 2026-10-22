<?php

namespace App\Livewire\Backend;

use App\Models\CourrierBrouillon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

// Module 1/2 — "Dossier surveillé" dans l'administration (2026-09-24,
// demande explicite de l'utilisateur : "put that démarrer surveiller in the
// admin menu as config", voir DECISIONS.md "Dossier surveillé :
// configuration dans l'administration"). Le choix du dossier et
// l'activation se font ICI ; Numérisation et Nouveau courrier importent
// ensuite automatiquement (mode 'execution' de resources/js/scan-watcher.js).
// La configuration elle-même vit dans le NAVIGATEUR (API File System Access
// + IndexedDB, par poste et par profil navigateur) — rien n'est stocké en
// base : ce composant ne fait que garder la page et afficher le suivi des
// derniers imports.
#[Title('Dossier surveillé')]
class DossierSurveille extends Component
{
    public function mount(): void
    {
        abort_unless(Auth::user()->hasPrivilege('administration.dossier_surveille'), 403);
    }

    // Derniers imports reçus via le dossier surveillé, tous postes confondus
    // (Règle n°3 : limité, eager loading) — pour vérifier d'un coup d'œil
    // que la configuration fonctionne.
    #[Computed]
    public function derniersImports()
    {
        return CourrierBrouillon::query()
            ->where('source', CourrierBrouillon::SOURCE_DOSSIER_SURVEILLE)
            ->with('creePar:id,name')
            ->latest()
            ->limit(5)
            ->get(['id', 'nom_original', 'ocr_statut', 'created_at', 'cree_par_id']);
    }

    public function render()
    {
        return view('frontend::dossierSurveille');
    }
}
