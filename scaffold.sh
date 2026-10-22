#!/bin/bash
# scaffold.sh — Crée le squelette de dossiers/fichiers du projet GEC
# Structure volontairement à plat (MVC Laravel classique) — pas de sous-dossiers
# par module. À lancer une seule fois, à la racine du projet Laravel.

set -e

echo "Création du squelette GEC (structure à plat)..."

# --- Dossiers ---
mkdir -p app/Models
mkdir -p app/Livewire
mkdir -p app/Jobs
mkdir -p app/Policies
mkdir -p app/Notifications
mkdir -p tests/Feature
mkdir -p tests/Unit

cat > app/Models/Profil.php << 'EOF'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Profil extends Model
{
    // 4 profils phase 1 : Agent, Collaborateur, Responsable de service, Administrateur
    // Nommé "Profil" (pas "Role") pour rester cohérent avec le vocabulaire métier
    // de Nsia Assurances — voir DECISIONS.md

    protected $fillable = ['nom'];
}
EOF

# --- Fichiers presque vides (skeleton avec juste namespace + classe) ---
# Structure à plat : le nom du fichier identifie le module (voir commentaire).

cat > app/Livewire/EnregistrementForm.php << 'EOF'
<?php

namespace App\Livewire;

use Livewire\Component;

class EnregistrementForm extends Component
{
    // Module 1 — Enregistrement courrier entrant/sortant
    // Voir specifications-modules-GEC.md, section Module 1

    public function render()
    {
        return view('livewire.enregistrement-form');
    }
}
EOF

cat > app/Livewire/ListeCourriers.php << 'EOF'
<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\WithPagination;

class ListeCourriers extends Component
{
    use WithPagination;

    // Rappel ARCHITECTURE-ESSENTIALS.md : pagination + eager loading obligatoires

    public function render()
    {
        return view('livewire.liste-courriers');
    }
}
EOF

cat > app/Livewire/DetailCourrier.php << 'EOF'
<?php

namespace App\Livewire;

use Livewire\Component;

class DetailCourrier extends Component
{
    // Propriété : ID primitif uniquement (pas de modèle Eloquent complet)
    public int $courrierId;

    public function render()
    {
        return view('livewire.detail-courrier');
    }
}
EOF

cat > app/Livewire/CircuitValidation.php << 'EOF'
<?php

namespace App\Livewire;

use Livewire\Component;

class CircuitValidation extends Component
{
    // Module 4 — Circuit de validation (Workflow)
    // Phase 1 : circuit générique unique (voir PRD.md, hors scope : circuits multiples)

    public function render()
    {
        return view('livewire.circuit-validation');
    }
}
EOF

cat > app/Livewire/TableauBord.php << 'EOF'
<?php

namespace App\Livewire;

use Livewire\Component;

class TableauBord extends Component
{
    // Module 10 — données pré-calculées via RefreshDashboardStatsJob + cache Redis
    // Ne JAMAIS recalculer les stats en direct ici

    public function render()
    {
        return view('livewire.tableau-bord');
    }
}
EOF

cat > app/Jobs/ProcessDocumentOcr.php << 'EOF'
<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessDocumentOcr implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    // Module 2 — Numérisation et dématérialisation
    public function handle(): void
    {
        //
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }
}
EOF

cat > app/Jobs/IndexCourrierJob.php << 'EOF'
<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class IndexCourrierJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    // Module 3 — Classement et indexation automatiques
    // Phase 1 : règles simples directement ici (pas de couche Services/ séparée
    // — voir ARCHITECTURE.md, choix de structure à plat)
    public function handle(): void
    {
        //
    }

    private function classer(string $texte): array
    {
        return [
            'service' => null,
            'type' => null,
        ];
    }
}
EOF

cat > app/Jobs/SendMailAlertJob.php << 'EOF'
<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendMailAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    // Module 7 — Alertes et relances automatiques (déclenché par le Scheduler)
    // Module 5 — calcul du statut SLA directement ici (pas de couche Services/)
    public function handle(): void
    {
        //
    }

    private function calculerStatutDelai($courrier): string
    {
        return 'a_temps'; // 'a_temps' | 'a_risque' | 'en_retard'
    }
}
EOF

cat > app/Jobs/RefreshDashboardStatsJob.php << 'EOF'
<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RefreshDashboardStatsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Module 10 — recalcule et met en cache (Redis) les stats du dashboard
    public function handle(): void
    {
        //
    }
}
EOF

cat > app/Policies/CourrierPolicy.php << 'EOF'
<?php

namespace App\Policies;

use App\Models\User;

class CourrierPolicy
{
    // Module 9 — Droits d'accès. Toute action sensible passe par ici,
    // jamais un `if` direct dans une vue ou un composant Livewire.
    // Vérifier $user->profil->nom (ex. 'Administrateur'), jamais $user->role.

    public function view(User $user, $courrier): bool
    {
        return false; // à implémenter selon $user->profil->nom
    }

    public function update(User $user, $courrier): bool
    {
        return false; // à implémenter selon $user->profil->nom
    }

    public function archive(User $user, $courrier): bool
    {
        return false; // à implémenter selon $user->profil->nom — Administrateur uniquement
    }
}
EOF

cat > app/Notifications/CourrierEnRetardNotification.php << 'EOF'
<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class CourrierEnRetardNotification extends Notification
{
    use Queueable;

    // Envoyée via SendMailAlertJob — jamais directement depuis un contrôleur
}
EOF

echo "Squelette créé (structure à plat). Consultez ARCHITECTURE.md pour le détail de chaque fichier."
echo "N'oubliez pas de lancer 'php artisan make:migration' pour les tables du modèle de données."
