<?php

namespace App\Livewire\Settings;

use App\Models\OrganizationUnit;
use App\Services\AvatarService;
use Flux\Flux;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

// 2026-10-26 (demande de l'utilisateur) — même présentation que la modale
// « Modifier l'utilisateur » de Utilisateurs & Accès (fiche + « Aperçu du
// compte »). Précisé le même jour : nom, prénom, email et téléphone ne sont
// modifiables QUE par l'administrateur. L'utilisateur ne change ici que sa
// photo ; tout le reste est affiché en lecture seule. Aucune propriété
// publique ne porte ces données : rien ne peut être forcé depuis le client
// (Règle n°6).
#[Title('Profile settings')]
class Profile extends Component
{
    use WithFileUploads;

    // Exception à la Règle n°2 (upload natif de Livewire), comme UserList.
    public $photo = null;

    // Enregistrée dès qu'elle est choisie (pas de bouton à part).
    public function updatedPhoto(AvatarService $avatars): void
    {
        $this->validate(
            ['photo' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:2048']],
            [],
            ['photo' => __('photo')],
        );

        $avatars->televerser(Auth::user(), $this->photo);
        $this->reset('photo');

        Flux::toast(variant: 'success', text: __('Photo mise à jour.'));
    }

    public function retirerPhoto(AvatarService $avatars): void
    {
        $avatars->supprimer(Auth::user());
        $this->reset('photo');

        Flux::toast(text: __('Photo retirée.'));
    }

    /**
     * Send an email verification notification to the current user.
     */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Flux::toast(text: __('A new verification link has been sent to your email address.'));
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        $user = Auth::user();

        return $user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail();
    }

    // Même découpage Nom / Prénom que UserList (premier mot = nom).
    #[Computed]
    public function nomPrenom(): array
    {
        $morceaux = explode(' ', Auth::user()->name, 2);

        return ['nom' => $morceaux[0] ?? '', 'prenom' => $morceaux[1] ?? ''];
    }

    // Chemin complet de son entité (ex. « Direction Générale / Direction
    // Financière et Comptable / Service Trésorerie »).
    #[Computed]
    public function cheminEntite(): ?string
    {
        $entite = Auth::user()->entitePrincipale();

        return $entite?->cheminComplet(OrganizationUnit::query()->get(['id', 'parent_id', 'name']));
    }

    // Personnes à qui il peut transférer un courrier (choisies par l'administrateur).
    #[Computed]
    public function destinatairesTransfert()
    {
        return Auth::user()->destinatairesTransfert()->orderBy('name')->pluck('users.name');
    }
}
