<?php

namespace App\Concerns;

use App\Models\OrganizationUnit;
use App\Models\Service;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;

// Module 1/4 (2026-10-23, voir DECISIONS.md "Transfert direct au service et
// acceptation") — choix du service destinataire d'un transfert par la
// cascade Site → Direction → Département → Service/Unité (Module
// "Organisation" v2, spec §16). Extrait de ShowCourrier (ancien panneau
// "Valider le service" de la DGA) pour être partagé par les trois points
// d'entrée du transfert : la fiche, "Mes courriers" et "Tous les courriers".
// Vue associée : resources/views/partials/cascade-service.blade.php.
//
// La résolution finale passe toujours par le PONT
// organization_units.service_id : seul un VRAI `services.id` est transmis
// à WorkflowService::transfererAuService(), jamais un id d'unité posté
// par le client (Règle n°6).
trait ChoixServiceCascade
{
    public ?int $siteSelectionneId = null;

    // Niveau Direction (OrganizationUnit::TYPE_DIRECTION), OPTIONNEL comme le Site.
    public ?int $directionSelectionneeId = null;

    public ?int $departementSelectionneId = null;

    public ?int $uniteSelectionneeId = null;

    // 2026-10-30 (« la direction doit être automatique selon où se trouve
    // l'utilisateur dans le site… il ne va plus choisir que les autres
    // niveaux », précisé : le Site) — Site de la personne qui transfère,
    // trouvé en remontant son entité (celle qu'il dirige, son rattachement
    // principal, sinon l'entité de son service, voir User::entitePrincipale())
    // jusqu'au niveau Site. Sans rattachement : le seul Site actif s'il n'y
    // en a qu'un. Null = plusieurs Sites et aucun rattachement → le menu
    // « Site » reste proposé.
    #[Computed]
    public function siteAutomatique(): ?OrganizationUnit
    {
        $noeud = Auth::user()?->entitePrincipale();

        for ($profondeur = 0; $noeud !== null && $profondeur < 10; $profondeur++) {
            if ($noeud->type === OrganizationUnit::TYPE_SITE) {
                return $noeud;
            }

            $noeud = $noeud->parent_id ? OrganizationUnit::find($noeud->parent_id) : null;
        }

        return $this->sitesDisponibles->count() === 1 ? $this->sitesDisponibles->first() : null;
    }

    // Appliqué à chaque requête (après mount() et à chaque réhydratation) et
    // après chaque réinitialisation : jamais un autre Site que le sien, même
    // si la requête est modifiée côté navigateur (Règle n°6). Publique :
    // Livewire appelle les hooks de trait depuis l'extérieur du composant (et
    // interdit lui-même leur appel depuis le navigateur).
    public function bootedChoixServiceCascade(): void
    {
        $this->appliquerSiteAutomatique();
    }

    protected function appliquerSiteAutomatique(): void
    {
        if (($site = $this->siteAutomatique) && $this->siteSelectionneId !== $site->id) {
            $this->siteSelectionneId = $site->id;
            $this->reset('directionSelectionneeId', 'departementSelectionneId', 'uniteSelectionneeId');
        }
    }

    // Changer un niveau vide les niveaux dépendants : jamais un choix
    // incohérent laissé affiché après un changement de niveau supérieur.
    public function updatedSiteSelectionneId(): void
    {
        $this->reset('directionSelectionneeId', 'departementSelectionneId', 'uniteSelectionneeId');
    }

    public function updatedDirectionSelectionneeId(): void
    {
        $this->reset('departementSelectionneId', 'uniteSelectionneeId');
    }

    public function updatedDepartementSelectionneId(): void
    {
        $this->reset('uniteSelectionneeId');
    }

    #[Computed]
    public function sitesDisponibles()
    {
        return OrganizationUnit::query()
            ->where('type', OrganizationUnit::TYPE_SITE)
            ->where('status', OrganizationUnit::STATUT_ACTIF)
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function directionsDisponibles()
    {
        return OrganizationUnit::enfantsActifsDe($this->siteSelectionneId, OrganizationUnit::TYPE_DIRECTION);
    }

    // Site ET Direction optionnels (des Départements réels existent à la
    // racine) : null est un parent_id valide. 2026-10-26 — les Services
    // rattachés directement à la Direction ou au Site y figurent aussi
    // (voir OrganizationUnit::niveauDepartementSous()).
    #[Computed]
    public function departementsDisponibles()
    {
        return OrganizationUnit::niveauDepartementSous($this->directionSelectionneeId ?? $this->siteSelectionneId);
    }

    // Service ET Sous-service : un Sous-service ponté à un vrai service peut
    // aussi être la destination finale (spec §1).
    #[Computed]
    public function unitesDisponibles()
    {
        return OrganizationUnit::unitesSous($this->departementSelectionneId);
    }

    // Nom de la Direction (ou du Site) choisie au-dessus du niveau
    // « Département / Service » — libellé de son option vide.
    #[Computed]
    public function nomParentCascade(): ?string
    {
        $parentId = $this->directionSelectionneeId ?? $this->siteSelectionneId;

        return $parentId ? OrganizationUnit::whereKey($parentId)->value('name') : null;
    }

    // Entité qui recevra le courrier avec la sélection affichée, et son chef
    // — rappelée sous la cascade avant confirmation (2026-10-26 : le Site
    // seul, c.-à-d. la Direction Générale, est désormais un choix valide ;
    // l'utilisateur voit toujours à qui part le courrier).
    #[Computed]
    public function destinataireCascade(): ?OrganizationUnit
    {
        $noeudId = $this->uniteSelectionneeId ?: $this->departementSelectionneId ?: $this->directionSelectionneeId ?: $this->siteSelectionneId;

        return $noeudId ? OrganizationUnit::with('service.responsable')->find($noeudId) : null;
    }

    // Pré-remplit la cascade depuis un `services.id` réel (proposition de
    // classement, Module 3) en remontant le nœud ponté et ses ancêtres.
    // Aucun nœud ponté = cascade vide, l'utilisateur choisit (rien d'inventé).
    protected function preselectionnerCascadeDepuisService(?int $serviceId): void
    {
        if ($serviceId === null) {
            return;
        }

        $selection = OrganizationUnit::selectionCascade(OrganizationUnit::where('service_id', $serviceId)->first());

        // 2026-10-30 — proposition hors du Site de la personne : ignorée (elle
        // ne pourrait pas la choisir), la cascade reste sur son Site.
        if (($site = $this->siteAutomatique) && $selection['site'] !== $site->id) {
            return;
        }

        $this->siteSelectionneId = $selection['site'];
        $this->directionSelectionneeId = $selection['direction'];
        $this->departementSelectionneId = $selection['departement'];
        $this->uniteSelectionneeId = $selection['unite'];
    }

    // Service réel retenu par la cascade, ou null avec une erreur sur
    // 'uniteSelectionneeId'. Nœud retenu : le niveau le plus fin choisi —
    // Service/Unité, sinon Département (ou Service rattaché directement),
    // sinon Direction (2026-10-23 — son responsable le transmettra à l'un de
    // ses départements), sinon le Site lui-même (2026-10-26 — la Direction
    // Générale reçoit le courrier). Le service doit être actif ET avoir un
    // chef : c'est lui qui devra accepter le courrier (Module 6 — un
    // courrier a toujours un responsable identifié).
    protected function serviceChoisiOuEchec(): ?Service
    {
        // Une erreur d'une tentative précédente ne doit pas survivre à un
        // nouveau choix valide.
        $this->resetErrorBag('uniteSelectionneeId');
        $this->appliquerSiteAutomatique();

        $niveaux = array_filter([
            'siteSelectionneId' => $this->siteSelectionneId,
            'directionSelectionneeId' => $this->directionSelectionneeId,
            'departementSelectionneId' => $this->departementSelectionneId,
            'uniteSelectionneeId' => $this->uniteSelectionneeId,
        ]);

        // 2026-10-30 — Site automatique seul (rien choisi en dessous) et ce Site
        // ne reçoit pas de courriers (aucun service relié) : même message clair
        // que si rien n'était choisi. Relié (ex. la Direction Générale), il
        // reste un destinataire valide, affiché avant confirmation.
        $siteAutoSeul = array_keys($niveaux) === ['siteSelectionneId']
            && $this->siteAutomatique?->service_id === null;

        if ($niveaux === [] || $siteAutoSeul) {
            $this->addError('uniteSelectionneeId', __('Choisissez une direction, un département ou un service.'));

            return null;
        }

        $proprieteRetenue = array_key_last($niveaux);
        $unite = OrganizationUnit::find(array_pop($niveaux));

        // Une unité restée en mémoire d'un choix précédent ne doit jamais
        // l'emporter sur le niveau affiché au-dessus d'elle (bug réel du
        // 2026-09-23 : envoyé vers "Sinistre Santé" alors que l'écran
        // montrait "Departement Informatique"). Le parent attendu est le
        // niveau choisi juste au-dessus (aucun = racine).
        $parentAttendu = $niveaux === [] ? null : end($niveaux);

        if ($unite && $unite->parent_id !== $parentAttendu) {
            $this->reset($proprieteRetenue);
            $this->addError('uniteSelectionneeId', __('La sélection a changé — vérifiez le département et le service, puis confirmez à nouveau.'));

            return null;
        }

        $service = $unite?->service_id ? Service::find($unite->service_id) : null;

        if ($service === null || ! $service->actif) {
            $this->addError('uniteSelectionneeId', __('Choisissez une entité déjà reliée à un service réel (configurez le lien depuis Organisation si besoin).'));

            return null;
        }

        if ($service->responsable_id === null) {
            $this->addError('uniteSelectionneeId', __('« :nom » n\'a pas de :titre désigné : personne ne pourrait accepter ce courrier.', [
                'nom' => $unite->name,
                'titre' => mb_strtolower($unite->titreDuChef()),
            ]));

            return null;
        }

        return $service;
    }

    protected function reinitialiserCascade(): void
    {
        $this->reset('siteSelectionneId', 'directionSelectionneeId', 'departementSelectionneId', 'uniteSelectionneeId');
        unset($this->sitesDisponibles, $this->directionsDisponibles, $this->departementsDisponibles, $this->unitesDisponibles, $this->destinataireCascade, $this->nomParentCascade);
        $this->appliquerSiteAutomatique();
    }
}
