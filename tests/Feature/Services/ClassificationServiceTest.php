<?php

namespace Tests\Feature\Services;

use App\Models\Courrier;
use App\Models\RegleClassement;
use App\Models\Service;
use App\Services\ClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function courrier(array $attributs = []): Courrier
    {
        return Courrier::create(array_merge([
            'numero_reference' => 'GEC-'.now()->year.'-TST-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'sens' => 'entrant',
            'date_mouvement' => now(),
            'objet' => 'Courrier',
            'type_document' => 'Lettre',
            'mode_reception' => 'email',
            'service_id' => Service::factory()->create()->id,
        ], $attributs));
    }

    public function test_une_regle_correspond_sans_tenir_compte_de_la_casse_ni_des_accents(): void
    {
        $sinistres = Service::factory()->create(['code' => 'SIN']);
        $regle = RegleClassement::factory()->create([
            'mots_cles' => ['déclaration de sinistre'],
            'type_document_propose' => 'Réclamation',
            'service_propose_id' => $sinistres->id,
            'tags' => ['Sinistre'],
        ]);

        $resultat = (new ClassificationService)->classer($this->courrier([
            'objet' => 'DECLARATION DE SINISTRE automobile',
        ]));

        $this->assertSame('Réclamation', $resultat['type_document']);
        $this->assertSame($sinistres->id, $resultat['service_id']);
        $this->assertSame($regle->id, $resultat['regle_id']);
        $this->assertSame(['sinistre'], $resultat['tags']);
    }

    public function test_aucune_regle_ne_correspond(): void
    {
        RegleClassement::factory()->create(['mots_cles' => ['sinistre']]);

        $resultat = (new ClassificationService)->classer($this->courrier(['objet' => 'Demande de devis']));

        $this->assertNull($resultat['type_document']);
        $this->assertNull($resultat['regle_id']);
        $this->assertSame([], $resultat['tags']);
    }

    public function test_la_priorite_la_plus_basse_lemporte_et_les_tags_se_cumulent(): void
    {
        RegleClassement::factory()->create(['nom' => 'Générique', 'mots_cles' => ['contrat'], 'type_document_propose' => 'Courrier contractuel', 'tags' => ['contrat'], 'priorite' => 200]);
        $precise = RegleClassement::factory()->create(['nom' => 'Résiliation', 'mots_cles' => ['résiliation'], 'type_document_propose' => 'Résiliation', 'tags' => ['résiliation'], 'priorite' => 10]);

        $resultat = (new ClassificationService)->classer($this->courrier(['objet' => 'Résiliation du contrat n°4521']));

        $this->assertSame('Résiliation', $resultat['type_document']);
        $this->assertSame($precise->id, $resultat['regle_id']);
        $this->assertEqualsCanonicalizing(['résiliation', 'contrat'], $resultat['tags']);
    }

    public function test_une_regle_limitee_a_un_champ_ignore_les_autres(): void
    {
        RegleClassement::factory()->create(['mots_cles' => ['sinistre'], 'champs' => ['objet'], 'type_document_propose' => 'Réclamation']);

        $service = new ClassificationService;

        $this->assertNull($service->classer($this->courrier(['objet' => 'Bonjour', 'texte_ocr' => 'sinistre survenu hier']))['type_document']);
        $this->assertSame('Réclamation', $service->classer($this->courrier(['objet' => 'Sinistre']))['type_document']);
    }

    public function test_une_regle_inactive_est_ignoree(): void
    {
        RegleClassement::factory()->create(['mots_cles' => ['sinistre'], 'actif' => false]);

        $this->assertNull((new ClassificationService)->classer($this->courrier(['objet' => 'Sinistre']))['regle_id']);
    }

    public function test_les_mots_cles_ocr_sont_les_mots_significatifs_les_plus_frequents(): void
    {
        $texte = "Bonjour Monsieur,\nSuite à votre réclamation concernant le contrat 4521, la réclamation est prise en compte. Contrat 4521 : indemnisation sous 30 jours. Cordialement.";

        $mots = (new ClassificationService)->extraireMotsCles($texte, 3);

        // Fréquence décroissante (contrat = réclamation = 2), puis ordre alphabétique
        $this->assertSame(['contrat', 'réclamation', 'compte'], $mots);
        $this->assertSame([], (new ClassificationService)->extraireMotsCles(null));
    }

    public function test_les_mots_cles_ocr_ignorent_elisions_dates_references_et_mots_trop_longs(): void
    {
        $texte = "Le 03-09-2026, l'assuré indique qu'il n'est pas d'accord : c'est l'objet d'une réclamation. Réf. 2026/4521 DLA777 de77 CCA-Bank — https://exemple.test/".str_repeat('a', 80);

        $mots = (new ClassificationService)->extraireMotsCles($texte);

        $this->assertContains('assuré', $mots);
        $this->assertContains('réclamation', $mots);
        $this->assertContains('indique', $mots);
        $this->assertContains('accord', $mots);
        $this->assertContains('cca-bank', $mots, 'un trait d\'union à l\'intérieur d\'un mot est conservé');

        foreach ($mots as $mot) {
            $this->assertStringNotContainsString("'", $mot, 'aucune élision conservée');
            $this->assertMatchesRegularExpression('/^[\p{L}-]+$/u', $mot, 'lettres uniquement : ni dates, ni références, ni bruit OCR (dla777)');
            $this->assertLessThanOrEqual(ClassificationService::LONGUEUR_MAX_MOT_CLE, mb_strlen($mot));
        }
    }

    public function test_la_regle_retenue_est_celle_qui_propose_type_ou_service_pas_une_regle_de_tags(): void
    {
        $tagsSeulement = RegleClassement::factory()->create(['nom' => 'Urgent', 'priorite' => 1, 'mots_cles' => ['urgent'], 'type_document_propose' => null, 'tags' => ['urgent']]);
        $reclamations = RegleClassement::factory()->create(['nom' => 'Réclamations', 'priorite' => 10, 'mots_cles' => ['réclamation'], 'type_document_propose' => 'Réclamation', 'tags' => ['réclamation']]);

        $resultat = (new ClassificationService)->classer($this->courrier(['objet' => 'Réclamation urgente']));

        $this->assertSame($reclamations->id, $resultat['regle_id']);
        $this->assertSame('Réclamations', $resultat['regle_nom']);
        $this->assertSame('Réclamation', $resultat['type_document']);
        $this->assertEqualsCanonicalizing(['urgent', 'réclamation'], $resultat['tags']);

        $resultat = (new ClassificationService)->classer($this->courrier(['objet' => 'Urgent']));

        $this->assertNull($resultat['regle_id'], 'une règle de tags seuls ne constitue pas un classement');
        $this->assertSame(['urgent'], $resultat['tags']);
        $this->assertNotNull($tagsSeulement);
    }

    public function test_lhistorique_dun_expediteur_deja_route_propose_le_meme_service(): void
    {
        $sinistres = Service::factory()->create(['code' => 'SIN']);
        $this->courrier([
            'expediteur_nom' => 'Jean DUPONT',
            'expediteur_organisation' => 'ACME Assurances',
            'service_id' => $sinistres->id,
            'classement_statut' => 'valide',
        ]);

        $resultat = (new ClassificationService)->classer($this->courrier(['expediteur_nom' => 'jean dupont']));

        $this->assertSame($sinistres->id, $resultat['service_id']);
        $this->assertSame('historique', $resultat['service_source']);
        $this->assertSame("Historique de l'expéditeur", $resultat['regle_nom']);
    }

    public function test_lhistorique_dexpediteur_prime_sur_la_regle_mots_cles_en_cas_de_conflit(): void
    {
        $generique = Service::factory()->create(['code' => 'GEN']);
        $sinistres = Service::factory()->create(['code' => 'SIN']);

        RegleClassement::factory()->create(['nom' => 'Sinistres', 'mots_cles' => ['sinistre'], 'type_document_propose' => 'Sinistre', 'service_propose_id' => $generique->id]);
        $this->courrier([
            'expediteur_organisation' => 'ACME Assurances',
            'service_id' => $sinistres->id,
            'classement_statut' => 'valide',
        ]);

        $resultat = (new ClassificationService)->classer($this->courrier(['objet' => 'Déclaration de sinistre', 'expediteur_organisation' => 'Acme Assurances']));

        $this->assertSame('Sinistre', $resultat['type_document'], 'le type vient toujours de la règle mots-clés');
        $this->assertSame($sinistres->id, $resultat['service_id'], 'le service vient de l\'historique, prioritaire');
        $this->assertSame('historique', $resultat['service_source']);
    }

    public function test_seul_un_classement_valide_alimente_lhistorique_dexpediteur(): void
    {
        $this->courrier([
            'expediteur_organisation' => 'ACME Assurances',
            'service_id' => Service::factory()->create(['code' => 'SIN'])->id,
            'classement_statut' => 'propose', // jamais confirmé par un agent
        ]);

        $resultat = (new ClassificationService)->classer($this->courrier(['expediteur_organisation' => 'ACME Assurances']));

        $this->assertNull($resultat['service_id']);
        $this->assertNull($resultat['service_source']);
    }
}
