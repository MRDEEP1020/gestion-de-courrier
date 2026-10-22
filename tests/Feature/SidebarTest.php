<?php

namespace Tests\Feature;

use App\Models\Privilege;
use App\Models\Profil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Régression du bug réel du 2026-09-18 : `:expanded="... ? 'true' : 'false'"`
// passait une CHAÎNE au composant Flux <flux:sidebar.group>, dont le stub
// (vendor/livewire/flux/stubs/.../sidebar/group.blade.php) compare avec
// `$expanded === true` (comparaison STRICTE) — une chaîne "true"/"false"
// n'égale jamais strictement le booléen `true`, donc CHAQUE groupe
// s'affichait replié quelle que soit la route active. Corrigé en passant le
// booléen brut de `request()->routeIs(...)` sans le convertir en chaîne.
class SidebarTest extends TestCase
{
    use RefreshDatabase;

    private function administrateur(): User
    {
        return User::factory()->create([
            'profil_id' => Profil::firstOrCreate(['nom' => 'Administrateur'])->id,
        ]);
    }

    // Isole le bloc <ui-disclosure ...>...</ui-disclosure> qui contient
    // l'intitulé donné, pour vérifier son propre attribut "open" sans être
    // trompé par un autre groupe de la page portant "open" ou non.
    private function blocDisclosure(string $html, string $intitule): string
    {
        $positionIntitule = strpos($html, $intitule);
        $this->assertNotFalse($positionIntitule, "Intitulé « {$intitule} » introuvable dans la page.");

        $debutBloc = strrpos(substr($html, 0, $positionIntitule), '<ui-disclosure');
        $this->assertNotFalse($debutBloc, "Aucun <ui-disclosure> trouvé avant « {$intitule} ».");

        // La balise ouvrante seule (jusqu'au premier ">") porte l'attribut "open".
        $finBalise = strpos($html, '>', $debutBloc);

        return substr($html, $debutBloc, $finBalise - $debutBloc);
    }

    public function test_le_groupe_courriers_est_deplie_sur_une_page_du_groupe_courriers(): void
    {
        $this->actingAs($this->administrateur());

        $html = $this->get(route('courriers.nouveau'))->getContent();

        $this->assertStringContainsString('open', $this->blocDisclosure($html, 'Courriers'));
    }

    public function test_le_groupe_courriers_est_replie_sur_une_page_hors_du_groupe_courriers(): void
    {
        $this->actingAs($this->administrateur());

        $html = $this->get(route('dashboard'))->getContent();

        $this->assertStringNotContainsString('open', $this->blocDisclosure($html, 'Courriers'));
    }

    public function test_le_groupe_administration_est_deplie_sur_une_page_dadministration(): void
    {
        $this->actingAs($this->administrateur());

        $html = $this->get(route('admin.utilisateurs'))->getContent();

        $this->assertStringContainsString('open', $this->blocDisclosure($html, 'Administration'));
    }

    public function test_le_groupe_administration_est_replie_sur_le_tableau_de_bord(): void
    {
        $this->actingAs($this->administrateur());

        $html = $this->get(route('dashboard'))->getContent();

        $this->assertStringNotContainsString('open', $this->blocDisclosure($html, 'Administration'));
    }

    // Module 3/9 — "Dossiers & Archives" (2026-09-21) : les 3 placeholders
    // <x-sidebar-item-a-venir> sont remplacés par de vrais liens.
    public function test_le_groupe_dossiers_et_archives_affiche_de_vrais_liens(): void
    {
        $this->actingAs($this->administrateur());

        $html = $this->get(route('dashboard'))->getContent();

        $this->assertStringContainsString(route('dossiers-classement.index'), $html);
    }

    public function test_le_groupe_dossiers_et_archives_est_masque_sans_privilege_lie(): void
    {
        $agent = User::factory()->create(['profil_id' => Profil::firstOrCreate(['nom' => 'Agent'])->id]);
        // Depuis le 2026-09-23 la page dépend de dossiers_classement.voir
        // (plus de courriers.rechercher).
        Privilege::where('cle', 'dossiers_classement.voir')->firstOrFail()->profils()->detach();

        $this->actingAs($agent);

        $html = $this->get(route('dashboard'))->getContent();

        $this->assertStringNotContainsString(route('dossiers-classement.index'), $html);
    }
}
