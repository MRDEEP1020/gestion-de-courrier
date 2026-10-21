<?php

namespace Tests\Feature\Services;

use App\Models\Parametre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

// Ligne singleton "Paramètres système" (2026-09-23, voir DECISIONS.md
// "Paramètres système configurables").
class ParametreTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_ligne_singleton_existe_deja_apres_migration(): void
    {
        $this->assertDatabaseCount('parametres', 1);
        $this->assertSame(1, Parametre::actuel()->id);
    }

    public function test_actuel_est_mis_en_cache(): void
    {
        Parametre::actuel();

        $this->assertTrue(Cache::has(Parametre::CLE_CACHE));
    }

    // Un ->update() direct sur le modèle laisse le cache PÉRIMÉ tant que
    // invaliderCache() n'est pas appelé explicitement — piège volontaire à
    // connaître (même principe que RefreshDashboardStatsJob : explicite,
    // jamais un observer caché).
    public function test_invalider_cache_est_necessaire_apres_une_modification_directe(): void
    {
        Parametre::actuel();
        Parametre::query()->whereKey(1)->update(['sla_jours_defaut' => 99]);

        $this->assertSame(10, Parametre::actuel()->sla_jours_defaut, 'le cache sert encore l\'ancienne valeur');

        Parametre::invaliderCache();

        $this->assertSame(99, Parametre::actuel()->sla_jours_defaut);
    }

    // Piège réel trouvé en testant contre la vraie base (CACHE_STORE=database,
    // pas 'array' comme en test — voir notes internes) : un objet Eloquent complet
    // mis en cache directement revient parfois en `__PHP_Incomplete_Class`
    // après désérialisation. actuel() ne doit mettre en cache QUE des
    // scalaires (tableau d'attributs bruts, jamais le modèle lui-même) —
    // vérifié ici en repassant la valeur mise en cache par un aller-retour
    // serialize()/unserialize() natif, ce que fait réellement le driver
    // 'database' (le driver 'array' des tests ne sérialise jamais rien,
    // donc ce piège est invisible sans cette vérification explicite).
    public function test_la_valeur_mise_en_cache_est_un_tableau_de_scalaires_serialisable_sans_risque(): void
    {
        Parametre::actuel();
        $brut = Cache::get(Parametre::CLE_CACHE);

        $this->assertIsArray($brut);
        $this->assertSame($brut, unserialize(serialize($brut)));
    }

    public function test_delai_sla_pour_un_type_specifique_puis_repli_sur_le_defaut(): void
    {
        $parametre = Parametre::actuel();
        $parametre->update(['sla_jours_defaut' => 10, 'sla_par_type' => ['Réclamation' => 5]]);

        $this->assertSame(5, $parametre->fresh()->delaiSlaPour('Réclamation'));
        $this->assertSame(10, $parametre->fresh()->delaiSlaPour('Lettre'));
        $this->assertSame(10, $parametre->fresh()->delaiSlaPour(null));
    }

    // "Group A" (2026-09-24, voir DECISIONS.md "Paramètres système
    // configurables — Groupe A/B") : mêmes valeurs par défaut que les
    // anciennes public const remplacées (User::NIVEAU_CONFIDENTIALITE_MAX,
    // ScanForm::RESOLUTION_MINIMALE, ProcessDocumentOcr::CONFIANCE_MINIMALE/
    // LONGUEUR_MINIMALE_TEXTE, RefreshDashboardStatsJob::PERIODE_JOURS).
    public function test_les_5_reglages_group_a_ont_les_memes_valeurs_par_defaut_que_les_anciennes_const(): void
    {
        $parametre = Parametre::actuel();

        $this->assertSame(5, $parametre->niveau_confidentialite_max);
        $this->assertSame(600, $parametre->scan_resolution_minimale);
        $this->assertSame(55, $parametre->ocr_confiance_minimale);
        $this->assertSame(20, $parametre->ocr_longueur_minimale_texte);
        $this->assertSame(90, $parametre->dashboard_delai_moyen_periode_jours);
    }

    public function test_user_niveau_confidentialite_max_lit_le_plafond_configurable(): void
    {
        $this->assertSame(5, User::niveauConfidentialiteMax());

        Parametre::actuel()->update(['niveau_confidentialite_max' => 8]);
        Parametre::invaliderCache();

        $this->assertSame(8, User::niveauConfidentialiteMax());
    }
}
