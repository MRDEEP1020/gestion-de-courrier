<?php

namespace Tests\Feature\Admin;

use App\Livewire\Backend\ParametreSysteme;
use App\Models\ListeReference;
use App\Models\Parametre;
use App\Models\Privilege;
use App\Models\Profil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Module 1/5/7 — "Paramètres système" (2026-09-23, "those small things
// that usually need to be coded has to [be] done through the UI now",
// format du numéro de référence donné en exemple — voir DECISIONS.md
// "Paramètres système configurables"). Même privilège que l'ancienne
// entrée "SLA & Alertes" (administration.sla, pas de nouvelle clé).
class ParametreSystemeTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateur(string $profil): User
    {
        return User::factory()->create(['profil_id' => Profil::where('nom', $profil)->value('id')]);
    }

    public function test_un_administrateur_peut_ouvrir_la_page(): void
    {
        $this->actingAs($this->utilisateur('Administrateur'));

        Livewire::test(ParametreSysteme::class)
            ->assertOk()
            ->assertSee('GEC-'.now()->year);
    }

    public function test_un_agent_ne_peut_pas_ouvrir_la_page(): void
    {
        $this->actingAs($this->utilisateur('Agent'));

        Livewire::test(ParametreSysteme::class)->assertForbidden();
    }

    // Même privilège que l'ancien menu "SLA & Alertes" — le retirer retire
    // aussi l'accès à cette page, pas de nouvelle clé créée.
    public function test_le_privilege_administration_sla_gouverne_toujours_la_page(): void
    {
        $admin = $this->utilisateur('Administrateur');
        Privilege::where('cle', 'administration.sla')->firstOrFail()->profils()->detach();
        // Garde-fou Administrateur non applicable ici (ce n'est pas privileges.gerer) —
        // un Administrateur SANS ce privilège précis perd bien l'accès.
        $this->actingAs(User::find($admin->id));

        Livewire::test(ParametreSysteme::class)->assertForbidden();
    }

    public function test_enregistrer_le_format_du_numero_de_reference(): void
    {
        $this->actingAs($this->utilisateur('Administrateur'));

        Livewire::test(ParametreSysteme::class)
            ->set('numeroReferencePrefixe', 'nsia')
            ->set('numeroReferenceChiffresSequence', 4)
            ->call('enregistrer')
            ->assertHasNoErrors();

        $parametre = Parametre::actuel();
        $this->assertSame('NSIA', $parametre->numero_reference_prefixe);
        $this->assertSame(4, $parametre->numero_reference_chiffres_sequence);
    }

    public function test_enregistrer_les_delais_sla_et_par_type(): void
    {
        $this->actingAs($this->utilisateur('Administrateur'));

        Livewire::test(ParametreSysteme::class)
            ->set('slaJoursDefaut', 15)
            ->set('slaSeuilRisqueJours', 3)
            ->set('slaRelanceJours', 1)
            ->set('slaParType.Réclamation', '5')
            ->set('slaParType.Sinistre', '')
            ->call('enregistrer')
            ->assertHasNoErrors();

        $parametre = Parametre::actuel();
        $this->assertSame(15, $parametre->sla_jours_defaut);
        $this->assertSame(3, $parametre->sla_seuil_risque_jours);
        $this->assertSame(1, $parametre->sla_relance_jours);
        $this->assertSame(['Réclamation' => 5], $parametre->sla_par_type);
    }

    public function test_le_prefixe_est_valide(): void
    {
        $this->actingAs($this->utilisateur('Administrateur'));

        Livewire::test(ParametreSysteme::class)
            ->set('numeroReferencePrefixe', 'GEC ASSU!')
            ->call('enregistrer')
            ->assertHasErrors(['numeroReferencePrefixe']);
    }

    public function test_laperçu_reflete_le_format_saisi_sans_toucher_a_la_vraie_sequence(): void
    {
        $this->actingAs($this->utilisateur('Administrateur'));

        Livewire::test(ParametreSysteme::class)
            ->set('numeroReferencePrefixe', 'NSIA')
            ->set('numeroReferenceChiffresSequence', 4)
            ->assertSee('NSIA-'.now()->year.'-0001');

        // Rien n'a été enregistré (pas d'appel à enregistrer()) — le
        // préfixe réel reste celui par défaut.
        $this->assertSame('GEC', Parametre::actuel()->numero_reference_prefixe);
    }

    // ===== Group A (2026-09-24) — 5 réglages ex-`const` codées en dur =====

    public function test_enregistrer_les_5_reglages_group_a(): void
    {
        $this->actingAs($this->utilisateur('Administrateur'));

        Livewire::test(ParametreSysteme::class)
            ->set('niveauConfidentialiteMax', 7)
            ->set('scanResolutionMinimale', 800)
            ->set('ocrConfianceMinimale', 60)
            ->set('ocrLongueurMinimaleTexte', 30)
            ->set('dashboardDelaiMoyenPeriodeJours', 120)
            ->call('enregistrer')
            ->assertHasNoErrors();

        $parametre = Parametre::actuel();
        $this->assertSame(7, $parametre->niveau_confidentialite_max);
        $this->assertSame(800, $parametre->scan_resolution_minimale);
        $this->assertSame(60, $parametre->ocr_confiance_minimale);
        $this->assertSame(30, $parametre->ocr_longueur_minimale_texte);
        $this->assertSame(120, $parametre->dashboard_delai_moyen_periode_jours);
    }

    public function test_les_reglages_group_a_sont_bornes(): void
    {
        $this->actingAs($this->utilisateur('Administrateur'));

        Livewire::test(ParametreSysteme::class)
            ->set('ocrConfianceMinimale', 101)
            ->call('enregistrer')
            ->assertHasErrors(['ocrConfianceMinimale']);
    }

    // ===== Group B (2026-09-24) — listes de référence gérables =====

    public function test_un_administrateur_peut_ajouter_une_valeur_a_une_liste(): void
    {
        $this->actingAs($this->utilisateur('Administrateur'));

        Livewire::test(ParametreSysteme::class)
            ->set('nouveauModeReception', 'Courrier interne')
            ->call('ajouterModeReception')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('listes_reference', [
            'type' => ListeReference::MODE_RECEPTION,
            'valeur' => 'Courrier interne',
            'actif' => true,
            'protege' => false,
        ]);
    }

    public function test_un_agent_ne_peut_pas_ajouter_de_valeur(): void
    {
        $this->actingAs($this->utilisateur('Agent'));

        Livewire::test(ParametreSysteme::class)->assertForbidden();
    }

    public function test_on_ne_peut_pas_ajouter_deux_fois_la_meme_valeur(): void
    {
        $this->actingAs($this->utilisateur('Administrateur'));

        Livewire::test(ParametreSysteme::class)
            ->set('nouvellePriorite', 'urgente')
            ->call('ajouterPriorite')
            ->assertHasErrors(['valeur']);
    }

    public function test_renommer_une_valeur_non_protegee(): void
    {
        $this->actingAs($this->utilisateur('Administrateur'));
        $poste = ListeReference::where('type', ListeReference::MODE_RECEPTION)->where('valeur', 'poste')->firstOrFail();

        Livewire::test(ParametreSysteme::class)
            ->call('ouvrirRenommage', $poste->id)
            ->set('renommageListeValeur', 'Courrier postal')
            ->call('enregistrerRenommage')
            ->assertHasNoErrors();

        $this->assertSame('Courrier postal', $poste->fresh()->valeur);
    }

    // "Sinistre" : CourrierForm::estUnSinistre() dépend de la chaîne exacte
    // (comparaison "contient sinistre") — protection appliquée à la fois
    // côté action (défense en profondeur) et côté vue (bouton masqué).
    public function test_une_valeur_protegee_ne_peut_pas_etre_renommee(): void
    {
        $this->actingAs($this->utilisateur('Administrateur'));
        $sinistre = ListeReference::where('type', ListeReference::TYPE_DOCUMENT)->where('valeur', 'Sinistre')->firstOrFail();

        // ouvrirRenommage() refuse silencieusement de préparer l'édition —
        // le formulaire de renommage ne s'ouvre jamais pour une valeur protégée.
        Livewire::test(ParametreSysteme::class)
            ->call('ouvrirRenommage', $sinistre->id)
            ->assertSet('renommageListeId', null);

        $this->assertSame('Sinistre', $sinistre->fresh()->valeur);
    }

    public function test_desactiver_une_valeur_non_protegee(): void
    {
        $this->actingAs($this->utilisateur('Administrateur'));
        $poste = ListeReference::where('type', ListeReference::MODE_RECEPTION)->where('valeur', 'poste')->firstOrFail();

        Livewire::test(ParametreSysteme::class)->call('basculerActif', $poste->id);

        $this->assertFalse($poste->fresh()->actif);
        $this->assertNotContains('poste', ListeReference::valeursActives(ListeReference::MODE_RECEPTION));
    }

    // Empêche un Rule::in([]) qui rejetterait tout nouvel enregistrement —
    // la désactivation ne bloque jamais le renommage/réordonnancement,
    // seulement la mise à zéro totale d'une liste active.
    public function test_on_ne_peut_pas_desactiver_la_derniere_valeur_active_dune_liste(): void
    {
        $this->actingAs($this->utilisateur('Administrateur'));

        foreach (ListeReference::where('type', ListeReference::PRIORITE)->pluck('id') as $id) {
            Livewire::test(ParametreSysteme::class)->call('basculerActif', $id);
        }

        $this->assertSame(1, ListeReference::where('type', ListeReference::PRIORITE)->where('actif', true)->count());
        $this->assertNotEmpty(ListeReference::valeursActives(ListeReference::PRIORITE));
    }

    public function test_une_valeur_protegee_reste_desactivable(): void
    {
        $this->actingAs($this->utilisateur('Administrateur'));
        $fax = ListeReference::where('type', ListeReference::MODE_RECEPTION)->where('valeur', 'fax')->firstOrFail();

        Livewire::test(ParametreSysteme::class)->call('basculerActif', $fax->id);

        $this->assertFalse($fax->fresh()->actif);
        $this->assertTrue($fax->fresh()->protege, 'la protection contre le renommage reste intacte');
    }

    public function test_reordonner_une_liste(): void
    {
        $this->actingAs($this->utilisateur('Administrateur'));
        $lettre = ListeReference::where('type', ListeReference::TYPE_DOCUMENT)->where('valeur', 'Lettre')->firstOrFail();
        $ordreInitial = $lettre->ordre;
        $suivant = ListeReference::where('type', ListeReference::TYPE_DOCUMENT)->where('ordre', $ordreInitial + 1)->firstOrFail();

        Livewire::test(ParametreSysteme::class)->call('deplacerValeur', $lettre->id, 'bas');

        $this->assertSame($ordreInitial + 1, $lettre->fresh()->ordre);
        $this->assertSame($ordreInitial, $suivant->fresh()->ordre);
    }
}
