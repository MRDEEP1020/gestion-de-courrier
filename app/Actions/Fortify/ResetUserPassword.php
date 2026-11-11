<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        // 2026-11-11 — choisi par la personne : le délai d'expiration repart
        // d'ici (voir App\Http\Middleware\ExigerMotDePasseAJour).
        $user->forceFill([
            'password' => $input['password'],
            'mot_de_passe_change_le' => now(),
        ])->save();
    }
}
