<?php

namespace App\Livewire\Backend;

use App\Models\Courrier;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

// Module 1/4/8 — "Courriers enregistrés" (maquette GPT, 2026-09-16, voir
// DECISIONS.md "Navigation (navbar + sidebar)") : clarifié avec
// l'utilisateur — PAS un simple lien de plus vers CourrierList, une vraie
// page reprenant la logique des 3 onglets de MesCourriers (en attente / en
// cours / transféré), mais à l'échelle de ce que l'utilisateur peut voir
// (même périmètre que CourrierList::resultats()), pas limitée à ses
// PROPRES courriers comme MesCourriers. Vue seule — pas de transfert en
// masse ici (ça reste le rôle de MesCourriers pour la réceptionniste sur
// ses propres courriers).
#[Title('Courriers enregistrés')]
class CourriersEnregistres extends Component
{
    use WithPagination;

    // 'suivi' (2026-09-24, demande explicite de l'utilisateur : "after he
    // transfer he has to see the courier update / follow without working on
    // it") : TOUS les courriers entrants déjà sortis du transfert, quel que
    // soit leur statut actuel (affecté, en traitement, traité, archivé…) —
    // l'onglet "Transféré" seul les perdait dès leur affectation. Même
    // périmètre (visiblePar) : pour une DGA, ce qu'elle a transféré.
    public const ONGLETS = ['en_attente_de_transfert', 'en_cours_de_transfert', 'enregistre', 'suivi'];

    private const STATUTS_AVANT_TRANSFERT = ['en_attente_de_transfert', 'en_cours_de_transfert'];

    #[Url]
    public string $onglet = 'en_attente_de_transfert';

    public function mount(): void
    {
        // Privilège de lecture dédié courriers.voir_enregistres (2026-09-23).
        $this->authorize('voirEnregistres', Courrier::class);

        if (! in_array($this->onglet, self::ONGLETS, true)) {
            $this->onglet = 'en_attente_de_transfert';
        }
    }

    public function changerOnglet(string $onglet): void
    {
        if (! in_array($onglet, self::ONGLETS, true)) {
            return;
        }

        $this->onglet = $onglet;
        $this->resetPage();
    }

    // Même périmètre que CourrierPolicy::view() via Courrier::scopeVisiblePar()
    // (2026-09-23 — remplace une copie locale qui ignorait le niveau de
    // confidentialité, l'accès dossier et le périmètre organisationnel).
    // 'entrant' uniquement : les 3 sous-statuts de
    // transfert n'existent que pour un courrier entrant (un sortant démarre
    // directement à 'enregistre'), inclure le sortant noierait l'onglet
    // "Transféré" sous des courriers qui n'ont jamais été "transférés".
    #[Computed]
    public function courriers()
    {
        $suivi = $this->onglet === 'suivi';

        return Courrier::query()
            ->visiblePar(Auth::user())
            ->where('sens', 'entrant')
            ->when(
                $suivi,
                fn ($q) => $q->whereNotIn('statut', self::STATUTS_AVANT_TRANSFERT),
                fn ($q) => $q->where('statut', $this->onglet),
            )
            // Règle n°3 — eager loading de ce que l'onglet Suivi affiche en plus.
            ->with($suivi ? ['service', 'affectationCourante.collaborateur'] : ['service'])
            // Suivi : dernier mouvement en tête (ce qui vient de bouger).
            ->when($suivi, fn ($q) => $q->latest('updated_at'), fn ($q) => $q->latest('date_mouvement'))
            ->paginate(10);
    }

    public function render()
    {
        return view('frontend::courriersEnregistres');
    }
}
