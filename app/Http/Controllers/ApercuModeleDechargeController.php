<?php

namespace App\Http\Controllers;

use App\Models\Courrier;
use App\Models\Parametre;
use App\Models\Service;
use App\Models\TransfertService;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

class ApercuModeleDechargeController extends Controller
{
    // 2026-10-28 (« je veux qu'elle soit modifiable à partir de l'interface ») —
    // aperçu PDF du modèle de décharge tel qu'il est saisi dans Paramètres
    // système, AVANT enregistrement, sur un courrier d'exemple jamais
    // enregistré en base. Même privilège que la page (administration.sla).
    public function __invoke(Request $request): Response
    {
        abort_unless($request->user()->hasPrivilege('administration.sla'), 403);

        $regles = collect(Parametre::reglesModeleDecharge())->mapWithKeys(fn ($regle, $champ) => ["modele.{$champ}" => $regle])->all();
        $modele = array_merge(Parametre::modeleDechargeParDefaut(), Validator::make($request->all(), $regles)->validate()['modele'] ?? []);

        $agent = new User(['name' => 'Agent Accueil (exemple)']);
        $responsable = new User(['name' => 'Responsable du service (exemple)']);

        $courrier = new Courrier([
            'numero_reference' => 'GEC-'.now()->year.'-000123',
            'sens' => 'entrant',
            'date_mouvement' => now(),
            'expediteur_organisation' => 'Société Exemple SARL',
            'objet' => 'Courrier d\'exemple pour l\'aperçu du modèle',
            'type_document' => 'Lettre',
        ]);
        $courrier->setRelation('piecesJointes', collect());

        $transfert = new TransfertService([
            'numero_decharge' => 'ACC-'.now()->year.'-000123',
            'statut' => TransfertService::ACCEPTE,
            'decide_le' => now(),
        ]);
        $transfert->created_at = now()->subHour();
        $transfert->setRelation('service', new Service(['nom' => 'Service Exemple']));
        $transfert->setRelation('transferePar', $agent);
        $transfert->setRelation('decidePar', $responsable);
        $transfert->setRelation('originalRemisPar', null);
        $transfert->setRelation('receptionSigneePar', null);

        return Pdf::loadView('pdf.decharge-remise', [
            'transfert' => $transfert,
            'courrier' => $courrier,
            'modele' => array_map(fn ($valeur) => (string) $valeur, $modele),
            'titreChef' => 'Responsable',
        ])->setPaper('a4')->stream('apercu-modele-decharge.pdf');
    }
}
