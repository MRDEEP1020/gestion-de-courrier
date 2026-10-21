<?php

namespace Database\Seeders;

use App\Models\Profil;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class ComptesTestPiloteSeeder extends Seeder
{
    // Comptes de démonstration pour tester le circuit courrier ENTRANT
    // (2026-09-08, voir DECISIONS.md "Circuit courrier entrant : validation
    // DGA/ADJ du service") à la main dans le navigateur, un compte par étape
    // du circuit. Mot de passe identique pour tous (indiqué à l'écran après
    // le run) — usage pilote/dev uniquement, jamais destiné à un
    // environnement réellement en production (à retirer de DatabaseSeeder
    // avant une vraie mise en ligne). updateOrCreate sur l'email : idempotent,
    // rejouable sans dupliquer ni écraser un vrai compte existant.
    private const MOT_DE_PASSE = 'password';

    public function run(): void
    {
        // Garde-fou : jamais exécuté hors local/testing, même si ce seeder
        // finissait un jour appelé par erreur dans un autre environnement.
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $administrateur = Profil::firstOrCreate(['nom' => 'Administrateur']);
        $agent = Profil::firstOrCreate(['nom' => 'Agent']);
        $dga = Profil::firstOrCreate(['nom' => 'DGA']);
        $responsable = Profil::firstOrCreate(['nom' => 'Responsable de service']);
        $collaborateur = Profil::firstOrCreate(['nom' => 'Collaborateur']);

        $dsin = Service::where('code', 'DSIN')->firstOrFail();
        $di = Service::where('code', 'DI')->firstOrFail();

        $this->compte('Admin Test', 'admin@test.local', $administrateur->id);
        $this->compte('Agent Test', 'agent@test.local', $agent->id);
        $this->compte('DGA Test', 'dga@test.local', $dga->id);

        // Circuit "sinistre confirmé" (saute la validation DGA) : responsable
        // + collaborateur de DSIN.
        $responsableDsin = $this->compte('Responsable DSIN', 'responsable.dsin@test.local', $responsable->id);
        $dsin->update(['responsable_id' => $responsableDsin->id]);
        $this->compte('Collaborateur DSIN', 'collaborateur.dsin@test.local', $collaborateur->id, $dsin->id);

        // Circuit "non-sinistre" (passe par la validation DGA, qui peut
        // router vers n'importe quel service — DI choisi ici comme exemple).
        $responsableDi = $this->compte('Responsable DI', 'responsable.di@test.local', $responsable->id);
        $di->update(['responsable_id' => $responsableDi->id]);
        $this->compte('Collaborateur DI', 'collaborateur.di@test.local', $collaborateur->id, $di->id);

        $this->command?->info('Comptes de test créés — mot de passe pour tous : '.self::MOT_DE_PASSE);
    }

    private function compte(string $nom, string $email, int $profilId, ?int $serviceId = null): User
    {
        return User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $nom,
                'password' => Hash::make(self::MOT_DE_PASSE),
                'email_verified_at' => now(),
                'profil_id' => $profilId,
                'service_id' => $serviceId,
            ],
        );
    }
}
