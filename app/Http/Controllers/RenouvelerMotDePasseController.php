<?php

namespace App\Http\Controllers;

use App\Concerns\PasswordValidationRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

// 2026-11-11 — renouvellement imposé par ExigerMotDePasseAJour. Formulaire
// CLASSIQUE (GET + POST, sans Livewire ni JavaScript) : première version en
// Livewire, dont le bouton « Enregistrer » ne menait nulle part chez
// l'utilisateur ; une page de blocage ne doit dépendre de rien d'autre que
// du navigateur. Redirection directe vers le tableau de bord, jamais vers
// « l'adresse demandée avant » (pouvait être une adresse sans page).
// Le changement libre depuis les paramètres reste retiré (2026-10-26).
class RenouvelerMotDePasseController extends Controller
{
    use PasswordValidationRules;

    public function afficher(Request $request): View|RedirectResponse
    {
        $raison = $request->user()->raisonRenouvellementMotDePasse();

        if ($raison === null) {
            return redirect()->route('dashboard');
        }

        return view('auth.renouveler-mot-de-passe', [
            'raison' => $raison,
            'joursRestants' => $request->user()->joursAvantExpirationMotDePasse(),
        ]);
    }

    public function enregistrer(Request $request): RedirectResponse
    {
        $request->validate([
            'motDePasseActuel' => $this->currentPasswordRules(),
            'password' => $this->passwordRules(),
        ], [], [
            'motDePasseActuel' => __('mot de passe actuel'),
            'password' => __('nouveau mot de passe'),
        ]);

        $user = $request->user();

        if (Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages([
                'password' => __('Choisissez un mot de passe différent de l\'actuel.'),
            ]);
        }

        $user->forceFill([
            'password' => $request->input('password'),
            'mot_de_passe_change_le' => now(),
        ])->save();

        return redirect()->route('dashboard')->with('mot-de-passe-renouvele', true);
    }
}
