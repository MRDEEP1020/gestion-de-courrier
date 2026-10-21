<?php

namespace App\Services;

use App\Models\OrganizationUnit;
use App\Models\Service;
use Illuminate\Support\Str;

// Module "Organisation" (2026-09-23, voir DECISIONS.md "Services créés depuis
// l'Organisation") : la page Organisation est le SEUL endroit où l'on gère
// les services — mais tout le reste de l'application (routage DGA, service
// d'un courrier, règles de classement, recherche, responsable qui reçoit les
// courriers et les alertes SLA) lit la table `services`. Sans ce pont, un
// service créé dans l'organigramme n'était sélectionnable nulle part.
//
// Appelé explicitement par OrganisationIndex (pas un événement de modèle :
// les factories/seeders de tests créent des nœuds sans vouloir créer de
// services).
class ServiceReelSynchroniseur
{
    // 2026-10-09 — Département ajouté (voir OrganizationUnit::TYPE_DIRECTION) :
    // depuis l'ajout du niveau Direction, "department" est devenu le niveau
    // réellement opérationnel le plus haut (ex. "Département Informatique",
    // qui reçoit du courrier directement) — Direction/Site/Company restent
    // structurels, jamais pontés.
    public const TYPES_SYNCHRONISES = [OrganizationUnit::TYPE_DEPARTMENT, OrganizationUnit::TYPE_SERVICE, OrganizationUnit::TYPE_SUB_SERVICE];

    // $avant : valeurs du nœud AVANT modification (null à la création), pour
    // ne propager un renommage / un changement de responsable QUE si nœud et
    // service étaient alignés — jamais renommer un service existant relié à
    // la main à un nœud de nom différent (ex. "Sinistre Santé" → "Sinistres").
    public function synchroniser(OrganizationUnit $unite, ?array $avant = null): void
    {
        if (! in_array($unite->type, self::TYPES_SYNCHRONISES, true)) {
            return;
        }

        if ($unite->service_id === null) {
            $this->relierOuCreer($unite);

            return;
        }

        $service = $unite->service;

        if ($service === null) {
            return;
        }

        $modifs = [];

        // Plus de garde-fou "aucun autre service de ce nom" ici (2026-10-05,
        // voir le commentaire de relierOuCreer() ci-dessous) : `services.nom`
        // n'est plus unique globalement depuis le multi-site, deux services
        // de même nom sous deux Sites différents sont attendus. Seul le test
        // "le nom du service est toujours resté aligné sur ce nœud" protège
        // encore un service PARTAGÉ (plusieurs nœuds reliés à la main au même
        // service réel) contre un renommage non voulu depuis un seul d'entre eux.
        $ancienNom = $avant['name'] ?? $unite->name;
        if ($service->nom === $ancienNom && $service->nom !== $unite->name) {
            $modifs['nom'] = $unite->name;
        }

        $ancienResponsable = $avant['responsible_user_id'] ?? null;
        if ($unite->responsible_user_id !== null
            && ($service->responsable_id === null || $service->responsable_id === $ancienResponsable)) {
            $modifs['responsable_id'] = $unite->responsible_user_id;
        }

        $modifs['actif'] = $this->doitEtreActif($service, $unite);

        $service->update($modifs);
    }

    // Aucun service choisi à la main : un service de même nom existe déjà
    // DANS LE MÊME SITE → on le relie (pas de doublon au sein d'une même
    // agence) ; sinon on en crée un NOUVEAU, même si une autre agence a déjà
    // un service de ce nom (2026-10-05, demande explicite de l'utilisateur :
    // "EACH CITY HAS HIS OWN DEPARTMENTS... USERS ON IT SHOULD SEE THE DATAS
    // OF THAT SITE NOT THE OTHERS") — deux agences ont chacune leur propre
    // "DSIN", jamais le même courrier ni les mêmes collaborateurs.
    // `services.nom` n'est plus unique en base pour permettre ça (voir
    // migration 2026_10_05_040000) ; `services.code`, lui, reste unique
    // (codeUnique() ci-dessous), donc chaque service reste identifiable sans
    // ambiguïté même si plusieurs partagent le même nom affiché.
    private function relierOuCreer(OrganizationUnit $unite): void
    {
        $siteId = $this->siteAncetreId($unite);
        $service = $this->serviceExistantDansLeMemeSite($unite, $siteId)
            ?? Service::create([
                'nom' => $unite->name,
                'code' => $this->codeUnique($unite->code, $unite->name),
                'responsable_id' => $unite->responsible_user_id,
                'actif' => $unite->estActif(),
            ]);

        if ($service->responsable_id === null && $unite->responsible_user_id !== null) {
            $service->update(['responsable_id' => $unite->responsible_user_id]);
        }

        $unite->update(['service_id' => $service->id]);
    }

    // Un service de même nom existe-t-il déjà, relié à un AUTRE nœud
    // service/sous-service du MÊME Site racine que $unite ? Ne regarde que
    // les nœuds déjà bridés (service_id non null) — jamais un nœud pas
    // encore synchronisé, et jamais $unite elle-même.
    private function serviceExistantDansLeMemeSite(OrganizationUnit $unite, ?int $siteId): ?Service
    {
        $candidats = OrganizationUnit::where('name', $unite->name)
            ->whereKeyNot($unite->id)
            ->whereIn('type', self::TYPES_SYNCHRONISES)
            ->whereNotNull('service_id')
            ->get();

        foreach ($candidats as $candidat) {
            if ($this->siteAncetreId($candidat) === $siteId) {
                return $candidat->service;
            }
        }

        return null;
    }

    // Remonte l'organigramme jusqu'au premier ancêtre de type Site/Agence
    // (le niveau racine, "sans parent" — voir OrganisationIndex). Retourne
    // null si le nœud n'a aucun ancêtre Site (ne devrait pas arriver en
    // usage normal, mais évite de planter sur une arborescence incomplète).
    private function siteAncetreId(OrganizationUnit $unite): ?int
    {
        $courant = $unite;
        $profondeur = 0;

        while ($courant !== null && $courant->type !== OrganizationUnit::TYPE_SITE && $profondeur < 10) {
            $courant = $courant->parent_id !== null ? OrganizationUnit::find($courant->parent_id) : null;
            $profondeur++;
        }

        return $courant?->type === OrganizationUnit::TYPE_SITE ? $courant->id : null;
    }

    // Un service partagé par plusieurs nœuds reste actif tant qu'au moins un
    // nœud actif l'utilise.
    private function doitEtreActif(Service $service, OrganizationUnit $unite): bool
    {
        if ($unite->estActif()) {
            return true;
        }

        return OrganizationUnit::where('service_id', $service->id)
            ->whereKeyNot($unite->id)
            ->where('status', OrganizationUnit::STATUT_ACTIF)
            ->exists();
    }

    // `services.code` : 10 caractères max, unique. Code du nœud s'il est
    // utilisable, sinon initiales du nom (ex. "Sinistre Santé" → "SS"),
    // suffixé -2, -3… en cas de collision.
    private function codeUnique(?string $codeNoeud, string $nom): string
    {
        $base = Str::upper(Str::ascii((string) $codeNoeud));
        $base = preg_replace('/[^A-Z0-9-]/', '', $base);

        if ($base === '' || strlen($base) > 10) {
            $mots = preg_split('/[^A-Za-z0-9]+/', Str::ascii($nom), -1, PREG_SPLIT_NO_EMPTY);
            $base = Str::upper(count($mots) > 1
                ? implode('', array_map(fn ($m) => $m[0], $mots))
                : substr($mots[0] ?? 'SRV', 0, 6));
            $base = substr($base, 0, 6) ?: 'SRV';
        }

        $code = $base;
        $i = 2;

        while (Service::where('code', $code)->exists()) {
            $suffixe = '-'.$i++;
            $code = substr($base, 0, 10 - strlen($suffixe)).$suffixe;
        }

        return $code;
    }
}
