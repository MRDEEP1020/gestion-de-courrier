<?php

namespace Tests\Feature\Broadcasting;

use App\Models\Courrier;
use App\Models\CourrierHistorique;
use App\Models\Profil;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CanalCourrierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Le driver `null` des tests n'applique pas les autorisations de canal ;
        // le driver reverb (Pusher) les vérifie et signe la réponse hors ligne.
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'cle-de-test',
            'broadcasting.connections.reverb.secret' => 'secret-de-test',
            'broadcasting.connections.reverb.app_id' => 'app-de-test',
        ]);

        // Les canaux de routes/channels.php ont été enregistrés au démarrage sur
        // le driver `null` ; on les ré-enregistre sur le driver choisi ci-dessus.
        require base_path('routes/channels.php');
    }

    private function utilisateurAvecProfil(string $nomProfil): User
    {
        $profil = Profil::firstOrCreate(['nom' => $nomProfil]);

        return User::factory()->create(['profil_id' => $profil->id]);
    }

    private function courrierEnregistrePar(User $auteur): Courrier
    {
        $courrier = Courrier::create([
            'numero_reference' => 'GEC-'.now()->year.'-TST-000001',
            'sens' => 'entrant',
            'date_mouvement' => now(),
            'objet' => 'Courrier de test',
            'type_document' => 'Lettre',
            'mode_reception' => 'email',
            'service_id' => Service::factory()->create(['code' => 'TST'])->id,
        ]);

        CourrierHistorique::create([
            'courrier_id' => $courrier->id,
            'auteur_id' => $auteur->id,
            'action' => 'creation',
        ]);

        return $courrier;
    }

    private function autoriser(User $user, int $courrierId)
    {
        return $this->actingAs($user)->post('/broadcasting/auth', [
            'channel_name' => "private-courrier.{$courrierId}",
            'socket_id' => '1234.5678',
        ]);
    }

    public function test_le_createur_peut_ecouter_le_canal_de_son_courrier(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($agent);

        $this->autoriser($agent, $courrier->id)->assertOk();
    }

    public function test_un_agent_tiers_ne_peut_pas_ecouter_le_canal(): void
    {
        $createur = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($createur);

        $autreAgent = User::factory()->create(['profil_id' => $createur->profil_id]);

        $this->autoriser($autreAgent, $courrier->id)->assertForbidden();
    }

    public function test_un_courrier_inexistant_est_refuse(): void
    {
        $admin = $this->utilisateurAvecProfil('Administrateur');

        $this->autoriser($admin, 999)->assertForbidden();
    }
}
