<?php

namespace Tests\Feature\Courriers;

use App\Livewire\Backend\CourrierList;
use App\Models\Affectation;
use App\Models\Courrier;
use App\Models\CourrierHistorique;
use App\Models\DossierClassement;
use App\Models\OrganizationUnit;
use App\Models\Privilege;
use App\Models\Profil;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CourrierListTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateur(string $nomProfil, ?Service $service = null): User
    {
        return User::factory()->create([
            'profil_id' => Profil::firstOrCreate(['nom' => $nomProfil])->id,
            'service_id' => $service?->id,
        ]);
    }

    private function courrier(Service $service, array $attributs = []): Courrier
    {
        return Courrier::create(array_merge([
            'numero_reference' => 'GEC-'.now()->year.'-TST-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'sens' => 'entrant',
            'date_mouvement' => now(),
            'objet' => 'Courrier',
            'type_document' => 'Lettre',
            'mode_reception' => 'email',
            'service_id' => $service->id,
        ], $attributs));
    }

    // 2026-09-24 — retour réel de l'utilisateur (capture d'écran) :
    // "Aperçu du courrier" affichait le CODE JS DU PANNEAU comme texte
    // visible sur la page. Cause trouvée : un commentaire à l'intérieur de
    // l'attribut x-data="{ ... }" (délimité par des guillemets DROITS)
    // contenait lui-même un guillemet droit — pour le navigateur, ce
    // guillemet referme prématurément l'attribut HTML, et tout le reste du
    // JS (jusqu'au prochain guillemet droit trouvé par accident) fuit comme
    // texte de page. Règle : AUCUN guillemet droit " dans un commentaire ou
    // une chaîne à l'intérieur d'un x-data="..." — guillemets français «»
    // uniquement (voir notes internes). Ce test extrait le
    // bloc x-data du panneau d'aperçu et vérifie qu'il n'en contient aucun.
    public function test_lattribut_xdata_du_panneau_apercu_ne_contient_aucun_guillemet_droit(): void
    {
        $admin = $this->utilisateur('Administrateur');
        $service = Service::factory()->create(['code' => 'TST']);
        $courrier = $this->courrier($service, ['fichier_path' => 'courriers/2026/TST/GEC-TEST.pdf']);
        $this->actingAs($admin);

        $html = Livewire::test(CourrierList::class)
            ->call('ouvrirApercu', $courrier->id)
            ->html();

        $ancrage = strpos($html, 'activerDeplacement');
        $this->assertNotFalse($ancrage, 'Le panneau "Aperçu du courrier" ne s\'est pas affiché.');

        $debut = strrpos(substr($html, 0, $ancrage), 'x-data="{');
        $finAttribut = strpos($html, '}"', $ancrage);
        $bloc = substr($html, $debut + strlen('x-data="'), $finAttribut - ($debut + strlen('x-data="')) + 1);

        $this->assertStringNotContainsString('"', $bloc, 'Un guillemet droit dans le bloc x-data casse l\'attribut HTML.');
        $this->assertSame(1, substr_count($html, 'Voir en plein écran'), 'Le bouton de la modale agrandie ne s\'est pas rendu correctement.');
    }

    // 2026-10-02 — demande explicite de l'utilisateur ("remove the time
    // just leave the bientot and enretand", puis précisé "only on the tout
    // les courier page the rest shouldn't change") : <x-chronometre> cache
    // désormais son badge compte à rebours (icône horloge + chiffres) SUR
    // CETTE PAGE SEULEMENT, via :masquer-temps="true" — ne garde que les
    // badges d'état "Bientôt en retard"/"En retard". Les autres pages
    // (ShowCourrier, Dashboard, Dossiers & Archives, EditForm) continuent
    // d'afficher le compte à rebours normalement (non touchées). Ce test
    // vérifie les deux côtés à la fois : absent ici, toujours présent sur
    // ShowCourrier pour le MÊME courrier.
    public function test_le_compte_a_rebours_est_masque_ici_mais_pas_sur_la_fiche_courrier(): void
    {
        $admin = $this->utilisateur('Administrateur');
        $service = Service::factory()->create(['code' => 'TST']);
        $courrier = $this->courrier($service, [
            'statut' => 'affecte',
            'chrono_debut_le' => now()->subDays(10),
            'chrono_fin_le' => now()->subDay(),
        ]);
        $this->actingAs($admin);

        $htmlListe = Livewire::test(CourrierList::class)->html();
        $this->assertStringContainsString(__('En retard'), $htmlListe);
        $this->assertStringNotContainsString('tabular-nums', $htmlListe, 'Le compte à rebours ne doit pas apparaître sur "Tous les courriers".');

        $htmlFiche = Livewire::test(\App\Livewire\Backend\ShowCourrier::class, ['courrierId' => $courrier->id])->html();
        $this->assertStringContainsString('tabular-nums', $htmlFiche, 'Le compte à rebours doit rester affiché sur la fiche courrier.');
    }

    // 2026-09-24 — retour réel de l'utilisateur (capture d'écran) : le
    // squelette de chargement du tableau ("loading animation... not the
    // loading sp[inner]") restait affiché EN PERMANENCE, empilé au-dessus
    // des vraies lignes, au lieu d'être caché hors chargement. Cause
    // trouvée en lisant vendor/livewire/livewire/src/Mechanisms/
    // FrontendAssets/FrontendAssets.php : wire:loading.table-row-group
    // n'est pas un modificateur que Livewire reconnaît — son <style> injecté
    // ne pré-cache (display:none) qu'une liste FIXE de valeurs
    // (.block/.grid/.flex/.table/.inline/...), jamais .table-row-group.
    // Corrigé une première fois avec une classe "hidden" directement sur le
    // <tbody>, PUIS remplacé le 2026-09-24 (même jour, "am not seeing
    // animation... in page") par le mécanisme définitif : une SENTINELLE
    // dédiée (jamais affichée elle-même) que resources/js/app.js observe
    // via MutationObserver pour piloter un état Alpine `visible` PARTAGÉ
    // (x-show) avec durée minimale garantie — une requête locale trop
    // rapide ne laissait sinon aucune chance de percevoir le balayage du
    // squelette. Ce test vérifie que la sentinelle existe bien, porte
    // "hidden" par défaut et le bon wire:loading, et que le <tbody>
    // squelette est piloté par x-show (jamais par un wire:loading direct
    // avec un modificateur non reconnu comme table-row-group).
    public function test_le_squelette_du_tableau_est_cache_par_defaut(): void
    {
        $service = Service::factory()->create(['code' => 'TST']);
        $this->courrier($service);
        $this->actingAs($this->utilisateur('Administrateur'));

        $html = Livewire::test(CourrierList::class)->html();

        $posSentinelle = strpos($html, 'x-ref="sentinelle"');
        $this->assertNotFalse($posSentinelle, 'La sentinelle du squelette de tableau doit exister.');

        $blocSentinelle = substr($html, max(0, $posSentinelle - 150), 300);
        $this->assertStringContainsString('class="hidden"', $blocSentinelle, 'La sentinelle doit porter la classe "hidden" par défaut.');
        $this->assertStringContainsString('wire:loading.class.remove="hidden"', $blocSentinelle, 'La sentinelle doit révéler son état pendant le chargement via wire:loading.class.remove, pas un modificateur de display non reconnu par Livewire (ex. table-row-group).');

        $this->assertStringNotContainsString('wire:loading.table-row-group', $html, 'wire:loading.table-row-group n\'est pas reconnu par Livewire — le squelette resterait affiché en permanence.');

        $posTbodySquelette = strpos($html, 'x-show="visible" x-cloak class="divide-y divide-zinc-200');
        $this->assertNotFalse($posTbodySquelette, 'Le <tbody> squelette doit être piloté par x-show="visible" (état partagé avec la sentinelle), pas par un wire:loading direct.');
    }

    // Module 3/8 — "résultats filtrés selon les droits d'accès de
    // l'utilisateur", même périmètre que CourrierPolicy::view().

    public function test_un_administrateur_voit_tous_les_courriers(): void
    {
        $serviceA = Service::factory()->create(['code' => 'AAA']);
        $serviceB = Service::factory()->create(['code' => 'BBB']);
        $courrierA = $this->courrier($serviceA);
        $courrierB = $this->courrier($serviceB);

        $this->actingAs($this->utilisateur('Administrateur'));

        Livewire::test(CourrierList::class)
            ->assertSee($courrierA->numero_reference)
            ->assertSee($courrierB->numero_reference);
    }

    public function test_un_responsable_de_service_ne_voit_que_les_courriers_de_son_service(): void
    {
        $responsable = $this->utilisateur('Responsable de service');
        $monService = Service::factory()->create(['responsable_id' => $responsable->id, 'code' => 'TST']);
        $autreService = Service::factory()->create(['code' => 'AUT']);

        $lemien = $this->courrier($monService);
        $pasLeMien = $this->courrier($autreService);

        $this->actingAs($responsable);

        Livewire::test(CourrierList::class)
            ->assertSee($lemien->numero_reference)
            ->assertDontSee($pasLeMien->numero_reference);
    }

    public function test_un_agent_ne_voit_que_les_courriers_quil_a_lui_meme_enregistres(): void
    {
        $service = Service::factory()->create(['code' => 'TST']);
        $agent = $this->utilisateur('Agent');
        $autreAgent = $this->utilisateur('Agent');

        $lemien = $this->courrier($service);
        CourrierHistorique::create(['courrier_id' => $lemien->id, 'auteur_id' => $agent->id, 'action' => 'creation']);

        $pasLeMien = $this->courrier($service);
        CourrierHistorique::create(['courrier_id' => $pasLeMien->id, 'auteur_id' => $autreAgent->id, 'action' => 'creation']);

        $this->actingAs($agent);

        Livewire::test(CourrierList::class)
            ->assertSee($lemien->numero_reference)
            ->assertDontSee($pasLeMien->numero_reference);
    }

    public function test_un_collaborateur_ne_voit_que_les_courriers_qui_lui_sont_affectes(): void
    {
        $service = Service::factory()->create(['code' => 'TST']);
        $collaborateur = $this->utilisateur('Collaborateur', $service);
        $autreCollaborateur = $this->utilisateur('Collaborateur', $service);

        $lemien = $this->courrier($service);
        Affectation::create(['courrier_id' => $lemien->id, 'user_id' => $collaborateur->id]);

        $pasLeMien = $this->courrier($service);
        Affectation::create(['courrier_id' => $pasLeMien->id, 'user_id' => $autreCollaborateur->id]);

        $this->actingAs($collaborateur);

        Livewire::test(CourrierList::class)
            ->assertSee($lemien->numero_reference)
            ->assertDontSee($pasLeMien->numero_reference);
    }

    // Filtres — un par critère de recherche (spec Module 3/8).

    public function test_le_filtre_numero_fonctionne(): void
    {
        $service = Service::factory()->create(['code' => 'TST']);
        $administrateur = $this->utilisateur('Administrateur');
        $cherche = $this->courrier($service, ['numero_reference' => 'GEC-2026-TST-000042']);
        $autre = $this->courrier($service, ['numero_reference' => 'GEC-2026-TST-000099']);

        $this->actingAs($administrateur);

        Livewire::test(CourrierList::class)
            ->set('numero', '000042')
            ->assertSee($cherche->numero_reference)
            ->assertDontSee($autre->numero_reference);
    }

    // Module 8/10 — barre de recherche unique de la navbar (2026-09-16, voir
    // DECISIONS.md "Navigation (navbar + sidebar)") : distincte des filtres
    // avancés numero/objet/expediteur, cherche dans les trois à la fois.
    public function test_le_parametre_q_de_la_navbar_cherche_dans_numero_objet_et_expediteur(): void
    {
        $service = Service::factory()->create(['code' => 'TST']);
        $administrateur = $this->utilisateur('Administrateur');
        $parNumero = $this->courrier($service, ['numero_reference' => 'GEC-2026-TST-000042']);
        $parObjet = $this->courrier($service, ['objet' => 'Réclamation ABCDEF']);
        $parExpediteur = $this->courrier($service, ['expediteur_organisation' => 'ABCDEF Sarl']);
        $sansRapport = $this->courrier($service, ['numero_reference' => 'GEC-2026-TST-000099', 'objet' => 'Autre chose', 'expediteur_organisation' => 'Autre société']);

        $this->actingAs($administrateur);

        Livewire::test(CourrierList::class)
            ->set('q', 'ABCDEF')
            ->assertSee($parObjet->numero_reference)
            ->assertSee($parExpediteur->numero_reference)
            ->assertDontSee($sansRapport->numero_reference);

        Livewire::test(CourrierList::class)
            ->set('q', '000042')
            ->assertSee($parNumero->numero_reference)
            ->assertDontSee($sansRapport->numero_reference);
    }

    public function test_le_filtre_objet_fonctionne(): void
    {
        $service = Service::factory()->create(['code' => 'TST']);
        $cherche = $this->courrier($service, ['objet' => 'Réclamation sinistre auto']);
        $autre = $this->courrier($service, ['objet' => 'Demande de renseignement']);

        $this->actingAs($this->utilisateur('Administrateur'));

        Livewire::test(CourrierList::class)
            ->set('objet', 'sinistre')
            ->assertSee($cherche->numero_reference)
            ->assertDontSee($autre->numero_reference);
    }

    public function test_le_filtre_expediteur_cherche_dans_le_nom_et_lorganisation(): void
    {
        $service = Service::factory()->create(['code' => 'TST']);
        $parNom = $this->courrier($service, ['expediteur_nom' => 'Jean Dupont']);
        $parOrganisation = $this->courrier($service, ['expediteur_organisation' => 'ITSC Sarl']);
        $sansRapport = $this->courrier($service, ['expediteur_nom' => 'Marie Curie']);

        $this->actingAs($this->utilisateur('Administrateur'));

        Livewire::test(CourrierList::class)
            ->set('expediteur', 'Dupont')
            ->assertSee($parNom->numero_reference)
            ->assertDontSee($sansRapport->numero_reference)
            ->assertDontSee($parOrganisation->numero_reference);

        Livewire::test(CourrierList::class)
            ->set('expediteur', 'ITSC')
            ->assertSee($parOrganisation->numero_reference)
            ->assertDontSee($sansRapport->numero_reference);
    }

    public function test_le_filtre_contenu_cherche_dans_le_texte_ocr(): void
    {
        // Demande explicite de l'utilisateur (2026-09-08) : retrouver un
        // courrier par un fragment vu sur le document (ici, le capital
        // social d'une organisation), même en ayant oublié
        // l'expéditeur/objet exact. La suite de tests tourne sur SQLite
        // (phpunit.xml) : ce test exerce donc le repli LIKE, pas le vrai
        // MATCH()/AGAINST() MySQL de production (voir CourrierList::
        // resultats() et la migration associée) — vérifié séparément à la
        // main contre une vraie base MySQL.
        $service = Service::factory()->create(['code' => 'TST']);
        $cherche = $this->courrier($service, [
            'texte_ocr' => "SAPDIST SARL\nSté au Capital de 990 000 FCFA\nRC N° : CM-DLA-02-2025-B-00827",
        ]);
        $sansRapport = $this->courrier($service, ['texte_ocr' => 'Un tout autre document, sans rapport.']);

        $this->actingAs($this->utilisateur('Administrateur'));

        Livewire::test(CourrierList::class)
            ->set('contenu', '990 000 FCFA')
            ->assertSee($cherche->numero_reference)
            ->assertDontSee($sansRapport->numero_reference)
            // Extrait affiché pour confirmer pourquoi ce courrier remonte.
            ->assertSee('990 000 FCFA');
    }

    // PENTEST (2026-09-24, "now total courier go throughtly even pentest
    // it") — extraitTexteOcr() prend un Courrier TYPÉ en paramètre d'action
    // Livewire : Livewire le résout via resolveRouteBinding() (un simple
    // Courrier::find($id)), JAMAIS via Courrier::visiblePar() ni une
    // policy — contrairement à courrierApercu()/supprimerCourrier()
    // ci-dessus, qui revérifient explicitement l'ID reçu (Règle n°6). Un
    // Responsable de service (courriers.voir_texte_ocr par défaut, voir
    // PrivilegeSeeder) pouvait donc appeler extraitTexteOcr() directement
    // avec l'id d'un courrier HORS de son service — en contournant
    // entièrement l'UI, qui ne l'affiche jamais dans le tableau — et lire
    // un extrait de son texte OCR (potentiellement confidentiel). La
    // policy avait déjà la bonne habileté pour ça (voirTexteOcr(), qui
    // inclut view()) ; elle n'était simplement pas appelée ici.
    public function test_extrait_texte_ocr_refuse_un_courrier_hors_du_perimetre_de_lutilisateur(): void
    {
        $responsable = $this->utilisateur('Responsable de service');
        Service::factory()->create(['responsable_id' => $responsable->id, 'code' => 'TST']);
        $autreService = Service::factory()->create(['code' => 'AUT']);

        $horsPerimetre = $this->courrier($autreService, [
            'texte_ocr' => 'Un secret confidentiel appartenant à un autre service.',
        ]);

        $this->actingAs($responsable);

        $composant = Livewire::test(CourrierList::class)->set('contenu', 'secret confidentiel');

        $this->assertNull(
            $composant->instance()->extraitTexteOcr($horsPerimetre),
            'Un utilisateur a pu lire un extrait du texte OCR d\'un courrier hors de son périmètre.'
        );
    }

    public function test_le_filtre_type_document_fonctionne(): void
    {
        // Demande explicite de l'utilisateur (2026-09-08) : "type document
        // (catégorie de document (confidentiel etc))" — texte libre saisi à
        // l'enregistrement (CourrierForm), donc un filtre LIKE comme
        // objet/expéditeur.
        $service = Service::factory()->create(['code' => 'TST']);
        $facture = $this->courrier($service, ['type_document' => 'Facture fournisseur']);
        $lettre = $this->courrier($service, ['type_document' => 'Lettre de motivation']);

        $this->actingAs($this->utilisateur('Administrateur'));

        Livewire::test(CourrierList::class)
            ->set('typeDocument', 'Facture')
            ->assertSee($facture->numero_reference)
            ->assertDontSee($lettre->numero_reference);
    }

    public function test_trier_par_bascule_la_direction_et_reordonne_les_resultats(): void
    {
        // Maquette "Tous les courriers" (2026-09-18, icône ⇕ sur "N°
        // Courrier") : tri réel, pas décoratif — colonne whitelistée
        // (COLONNES_TRIABLES) pour ne jamais passer un nom de colonne
        // arbitraire à orderBy() (Règle n°6).
        $service = Service::factory()->create(['code' => 'TST']);
        $premier = $this->courrier($service, ['numero_reference' => 'GEC-2026-TST-000001']);
        $second = $this->courrier($service, ['numero_reference' => 'GEC-2026-TST-000002']);

        $this->actingAs($this->utilisateur('Administrateur'));

        $composant = Livewire::test(CourrierList::class)
            ->assertSet('tri', 'date_mouvement')
            ->assertSet('direction', 'desc')
            ->call('trierPar', 'numero_reference')
            ->assertSet('tri', 'numero_reference')
            ->assertSet('direction', 'asc');

        $this->assertTrue(
            $composant->get('resultats')->pluck('id')->search($premier->id)
                < $composant->get('resultats')->pluck('id')->search($second->id)
        );

        $composant->call('trierPar', 'numero_reference')
            ->assertSet('direction', 'desc');
    }

    public function test_trier_par_ignore_une_colonne_non_whitelistee(): void
    {
        $this->actingAs($this->utilisateur('Administrateur'));

        Livewire::test(CourrierList::class)
            ->call('trierPar', 'objet')
            ->assertSet('tri', 'date_mouvement')
            ->assertSet('direction', 'desc');
    }

    public function test_le_filtre_confidentialite_fonctionne(): void
    {
        // Même demande — "catégorie de document (confidentiel etc)" : liste
        // fermée à 5 niveaux NUMÉRIQUES (2026-09-21, "numbers 1,2,3,4,5
        // etc", voir CourrierForm::rules()), donc un filtre à choix comme
        // statut/sens.
        $service = Service::factory()->create(['code' => 'TST']);
        $confidentiel = $this->courrier($service, ['confidentialite' => 2]);
        $normal = $this->courrier($service, ['confidentialite' => 1]);

        $this->actingAs($this->utilisateur('Administrateur'));

        Livewire::test(CourrierList::class)
            ->set('confidentialite', '2')
            ->assertSee($confidentiel->numero_reference)
            ->assertDontSee($normal->numero_reference)
            // Signalé visuellement dans les résultats, pas seulement filtrable
            // — libellé "Niveau :n" (2026-09-21, "THE LABEL SHOULD BE
            // NIVEAU 1 OR LEVEL 1"), jamais un chiffre nu.
            ->assertSee('Niveau 2');
    }

    public function test_le_filtre_date_fonctionne_par_plage(): void
    {
        $service = Service::factory()->create(['code' => 'TST']);
        $dansLaPlage = $this->courrier($service, ['date_mouvement' => '2026-07-15']);
        $avantLaPlage = $this->courrier($service, ['date_mouvement' => '2026-06-01']);
        $apresLaPlage = $this->courrier($service, ['date_mouvement' => '2026-08-01']);

        $this->actingAs($this->utilisateur('Administrateur'));

        Livewire::test(CourrierList::class)
            ->set('dateDebut', '2026-07-01')
            ->set('dateFin', '2026-07-31')
            ->assertSee($dansLaPlage->numero_reference)
            ->assertDontSee($avantLaPlage->numero_reference)
            ->assertDontSee($apresLaPlage->numero_reference);
    }

    // Module 1/4 — transfert en masse (2026-09-22, demande explicite de
    // l'utilisateur : "no button to transfer courier in bulk why") — même
    // action que MesCourriers::transfererSelection(), reciblée sur cette
    // page. Le bouton lui-même est gardé par un privilège (jamais un rôle en
    // dur, "each functionality is a button right if yes the should be
    // preveliges") : un profil sans AUCUN privilège de transfert ne le voit
    // même pas.
    public function test_le_bouton_de_transfert_en_masse_est_cache_sans_aucun_privilege_de_transfert(): void
    {
        $service = Service::factory()->create(['code' => 'TST']);
        $courrier = $this->courrier($service, ['statut' => 'en_attente_de_transfert']);

        // Administrateur a "rechercher" mais on retire explicitement les deux
        // privilèges de transfert pour isoler ce cas précis.
        $utilisateur = $this->utilisateur('Administrateur');
        Privilege::where('cle', 'courriers.transferer_tout')->firstOrFail()->users()->detach();
        Privilege::where('cle', 'courriers.transferer_tout')->firstOrFail()->profils()->detach();
        Privilege::where('cle', 'courriers.transferer_propre')->firstOrFail()->users()->detach();
        Privilege::where('cle', 'courriers.transferer_propre')->firstOrFail()->profils()->detach();

        $this->actingAs($utilisateur);

        // Avec une sélection : seul le privilège peut expliquer l'absence du
        // bouton (sans sélection, la barre d'actions est de toute façon masquée).
        Livewire::test(CourrierList::class)
            ->set('selectionnes', [$courrier->id])
            ->assertDontSee('Transférer la sélection');
    }

    // 2026-09-23 — "make the two button transfer and classe appear only when
    // we have selected" : barre d'actions absente sans sélection, présente dès
    // une case cochée, de nouveau absente après "Désélectionner".
    public function test_les_boutons_transferer_et_classer_napparaissent_quavec_une_selection(): void
    {
        $service = Service::factory()->create(['code' => 'TST']);
        $courrier = $this->courrier($service, ['statut' => 'en_attente_de_transfert']);
        $admin = $this->utilisateur('Administrateur');
        DossierClassement::create(['nom' => 'Dossier test', 'cree_par_id' => $admin->id]);

        $this->actingAs($admin);

        Livewire::test(CourrierList::class)
            ->assertDontSee('Transférer la sélection')
            ->assertDontSee('Classer la sélection')
            ->set('selectionnes', [$courrier->id])
            ->assertSee('Transférer la sélection')
            ->assertSee('Classer la sélection')
            ->assertSee('1 courrier(s) sélectionné(s)')
            ->set('selectionnes', [])
            ->assertDontSee('Transférer la sélection')
            ->assertDontSee('Classer la sélection');
    }

    // 2026-10-23 (voir DECISIONS.md "Transfert direct au service et
    // acceptation") — transfert groupé vers UN service choisi dans la cascade
    // (plus vers une personne). Département racine ponté au service cible.
    private function departementPontePourTransfert(): array
    {
        $responsable = $this->utilisateur('Responsable de service');
        $cible = Service::factory()->create(['code' => 'DST', 'responsable_id' => $responsable->id]);
        $departement = OrganizationUnit::factory()->create([
            'type' => OrganizationUnit::TYPE_DEPARTMENT,
            'parent_id' => null,
            'service_id' => $cible->id,
        ]);

        return [$cible, $departement];
    }

    public function test_le_transfert_en_masse_transfere_plusieurs_courriers_selectionnes(): void
    {
        $service = Service::factory()->create(['code' => 'TST']);
        $premier = $this->courrier($service, ['statut' => 'en_attente_de_transfert', 'service_id' => null]);
        $second = $this->courrier($service, ['statut' => 'en_attente_de_transfert', 'service_id' => null]);
        [$cible, $departement] = $this->departementPontePourTransfert();

        $this->actingAs($this->utilisateur('Administrateur'));

        Livewire::test(CourrierList::class)
            ->set('selectionnes', [$premier->id, $second->id])
            ->set('departementSelectionneId', $departement->id)
            ->call('transfererSelection')
            ->assertHasNoErrors();

        foreach ([$premier, $second] as $courrier) {
            $courrier->refresh();
            $this->assertSame('en_cours_de_transfert', $courrier->statut);
            $this->assertSame($cible->id, $courrier->service_id);
        }
        $this->assertSame(2, CourrierHistorique::whereIn('courrier_id', [$premier->id, $second->id])->where('action', 'transfert')->count());
    }

    // Règle n°6 — le privilège REND le bouton visible, mais chaque courrier
    // reste revérifié individuellement : "transferer_propre" (limité à ses
    // propres courriers) ne transfère jamais celui d'un autre agent, même
    // sélectionné.
    public function test_le_transfert_en_masse_ignore_un_courrier_hors_du_perimetre_de_lutilisateur(): void
    {
        $service = Service::factory()->create(['code' => 'TST']);
        $agent = $this->utilisateur('Agent');
        $autreAgent = $this->utilisateur('Agent');
        [, $departement] = $this->departementPontePourTransfert();

        $lemien = $this->courrier($service, ['statut' => 'en_attente_de_transfert', 'service_id' => null]);
        CourrierHistorique::create(['courrier_id' => $lemien->id, 'auteur_id' => $agent->id, 'action' => 'creation']);

        $pasLeMien = $this->courrier($service, ['statut' => 'en_attente_de_transfert', 'service_id' => null]);
        CourrierHistorique::create(['courrier_id' => $pasLeMien->id, 'auteur_id' => $autreAgent->id, 'action' => 'creation']);

        $this->actingAs($agent);

        Livewire::test(CourrierList::class)
            ->set('selectionnes', [$lemien->id, $pasLeMien->id])
            ->set('departementSelectionneId', $departement->id)
            ->call('transfererSelection');

        $this->assertSame('en_cours_de_transfert', $lemien->refresh()->statut);
        $this->assertSame('en_attente_de_transfert', $pasLeMien->refresh()->statut, 'le courrier d\'un autre agent ne doit pas être transféré par transferer_propre');
    }

    public function test_le_transfert_en_masse_exige_un_service_choisi(): void
    {
        $service = Service::factory()->create(['code' => 'TST']);
        $courrier = $this->courrier($service, ['statut' => 'en_attente_de_transfert', 'service_id' => null]);

        $this->actingAs($this->utilisateur('Administrateur'));

        Livewire::test(CourrierList::class)
            ->set('selectionnes', [$courrier->id])
            ->call('transfererSelection')
            ->assertHasErrors('uniteSelectionneeId');

        $this->assertSame('en_attente_de_transfert', $courrier->refresh()->statut);
    }

    // Un courrier en attente d'acceptation ne doit pas être trouvable par son
    // CONTENU par le service destinataire (même règle que son aperçu).
    public function test_la_recherche_dans_le_contenu_ignore_un_courrier_pas_encore_accepte(): void
    {
        $responsable = $this->utilisateur('Responsable de service');
        $service = Service::factory()->create(['code' => 'TST', 'responsable_id' => $responsable->id]);
        $enAttente = $this->courrier($service, ['statut' => 'en_cours_de_transfert', 'texte_ocr' => 'Montant du préjudice', 'ocr_statut' => 'reussi']);
        $accepte = $this->courrier($service, ['statut' => 'enregistre', 'texte_ocr' => 'Montant du préjudice', 'ocr_statut' => 'reussi']);

        $this->actingAs($responsable);

        Livewire::test(CourrierList::class)
            ->set('contenu', 'préjudice')
            ->assertSee($accepte->numero_reference)
            ->assertDontSee($enAttente->numero_reference);
    }

    // Module 3/9 — classement en masse (2026-09-22, "les trois" points
    // d'entrée pour ranger un courrier dans un dossier). Contrairement au
    // transfert, pas de privilège fixe : le bouton dépend seulement de
    // l'existence d'au moins un dossier accessible.
    public function test_le_bouton_classer_en_masse_est_cache_sans_aucun_dossier_accessible(): void
    {
        $service = Service::factory()->create(['code' => 'TST']);
        $courrier = $this->courrier($service);

        $this->actingAs($this->utilisateur('Agent'));

        Livewire::test(CourrierList::class)
            ->set('selectionnes', [$courrier->id])
            ->assertDontSee('Classer la sélection');
    }

    public function test_le_classement_en_masse_classe_plusieurs_courriers_selectionnes(): void
    {
        $service = Service::factory()->create(['code' => 'TST']);
        $premier = $this->courrier($service);
        $second = $this->courrier($service);

        $utilisateur = $this->utilisateur('Administrateur');
        $dossier = DossierClassement::create(['nom' => 'Sinistres 2026', 'cree_par_id' => $utilisateur->id]);

        $this->actingAs($utilisateur);

        Livewire::test(CourrierList::class)
            ->set('selectionnes', [$premier->id, $second->id])
            ->set('dossierChoisi', $dossier->id)
            ->call('classerSelection');

        $this->assertSame($dossier->id, $premier->refresh()->dossier_classement_id);
        $this->assertSame($dossier->id, $second->refresh()->dossier_classement_id);
    }

    // Règle n°6 — même principe que le transfert : le dossier RENDU visible
    // n'exempte pas chaque courrier d'une revérification individuelle. Ici
    // classer() délègue à view(), donc un Agent limité à voir_propre ne
    // classe jamais le courrier d'un autre agent, même sélectionné.
    public function test_le_classement_en_masse_ignore_un_courrier_hors_du_perimetre_de_lutilisateur(): void
    {
        $service = Service::factory()->create(['code' => 'TST']);
        $agent = $this->utilisateur('Agent');
        $autreAgent = $this->utilisateur('Agent');
        $dossier = DossierClassement::create(['nom' => 'Mon dossier', 'cree_par_id' => $agent->id]);

        $lemien = $this->courrier($service);
        CourrierHistorique::create(['courrier_id' => $lemien->id, 'auteur_id' => $agent->id, 'action' => 'creation']);

        $pasLeMien = $this->courrier($service);
        CourrierHistorique::create(['courrier_id' => $pasLeMien->id, 'auteur_id' => $autreAgent->id, 'action' => 'creation']);

        $this->actingAs($agent);

        Livewire::test(CourrierList::class)
            ->set('selectionnes', [$lemien->id, $pasLeMien->id])
            ->set('dossierChoisi', $dossier->id)
            ->call('classerSelection');

        $this->assertSame($dossier->id, $lemien->refresh()->dossier_classement_id);
        $this->assertNull($pasLeMien->refresh()->dossier_classement_id, 'le courrier d\'un autre agent ne doit pas être classé');
    }

    public function test_le_classement_en_masse_refuse_un_dossier_non_accessible(): void
    {
        $service = Service::factory()->create(['code' => 'TST']);
        $courrier = $this->courrier($service);

        $utilisateur = $this->utilisateur('Responsable de service');
        $autreUtilisateur = $this->utilisateur('Responsable de service');
        // Dossier créé par un autre utilisateur, jamais partagé.
        $dossierEtranger = DossierClassement::create(['nom' => 'Dossier étranger', 'cree_par_id' => $autreUtilisateur->id]);

        $this->actingAs($utilisateur);

        Livewire::test(CourrierList::class)
            ->set('selectionnes', [$courrier->id])
            ->set('dossierChoisi', $dossierEtranger->id)
            ->call('classerSelection')
            ->assertHasErrors('dossierChoisi');

        $this->assertNull($courrier->refresh()->dossier_classement_id);
    }

    public function test_le_filtre_service_fonctionne(): void
    {
        $serviceA = Service::factory()->create(['code' => 'AAA']);
        $serviceB = Service::factory()->create(['code' => 'BBB']);
        $courrierA = $this->courrier($serviceA);
        $courrierB = $this->courrier($serviceB);

        $this->actingAs($this->utilisateur('Administrateur'));

        Livewire::test(CourrierList::class)
            ->set('serviceId', $serviceA->id)
            ->assertSee($courrierA->numero_reference)
            ->assertDontSee($courrierB->numero_reference);
    }

    public function test_le_filtre_statut_fonctionne(): void
    {
        $service = Service::factory()->create(['code' => 'TST']);
        $enregistre = $this->courrier($service, ['statut' => 'enregistre']);
        $traite = $this->courrier($service, ['statut' => 'traite']);

        $this->actingAs($this->utilisateur('Administrateur'));

        Livewire::test(CourrierList::class)
            ->set('statut', 'traite')
            ->assertSee($traite->numero_reference)
            ->assertDontSee($enregistre->numero_reference);
    }

    public function test_le_filtre_sens_fonctionne(): void
    {
        $service = Service::factory()->create(['code' => 'TST']);
        $entrant = $this->courrier($service, ['sens' => 'entrant']);
        $sortant = $this->courrier($service, ['sens' => 'sortant']);

        $this->actingAs($this->utilisateur('Administrateur'));

        Livewire::test(CourrierList::class)
            ->set('sens', 'sortant')
            ->assertSee($sortant->numero_reference)
            ->assertDontSee($entrant->numero_reference);
    }

    public function test_reinitialiser_vide_tous_les_filtres(): void
    {
        $service = Service::factory()->create(['code' => 'TST']);
        $courrier = $this->courrier($service, ['objet' => 'Un objet bien précis']);

        $this->actingAs($this->utilisateur('Administrateur'));

        Livewire::test(CourrierList::class)
            ->set('objet', 'introuvable-XYZ')
            ->assertDontSee($courrier->numero_reference)
            ->call('reinitialiser')
            ->assertSet('objet', '')
            ->assertSee($courrier->numero_reference);
    }

    public function test_un_profil_inconnu_narrive_pas_a_la_page(): void
    {
        $utilisateur = User::factory()->create(['profil_id' => null]);

        $this->actingAs($utilisateur);

        Livewire::test(CourrierList::class)->assertForbidden();
    }
}
