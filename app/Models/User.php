<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property int|null $profil_id
 * @property string $name
 * @property string $email
 * @property string|null $telephone
 * @property string|null $poste
 * @property string|null $photo_path
 * @property bool $actif
 * @property Carbon|null $derniere_connexion_le
 * @property int $niveau_confidentialite
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'profil_id', 'service_id', 'telephone', 'poste', 'photo_path', 'actif', 'niveau_confidentialite', 'preferences_notifications'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    // Valeurs par défaut EN MÉMOIRE (2026-09-21, bug trouvé en testant la
    // confidentialité numérique) — la colonne a bien un DEFAULT côté base
    // (migration), mais un `User::factory()->create()` (ou tout `new
    // User()`/`create()` qui n'inclut pas explicitement ce champ) ne
    // relit PAS la ligne après l'INSERT : l'instance en mémoire garde
    // l'attribut absent/`null` jusqu'au prochain `fresh()`. Comme
    // `actingAs()` réutilise CETTE instance telle quelle pour toute la
    // durée du test, `Auth::user()->niveau_confidentialite` valait `null`
    // partout — `null < 1` étant vrai en PHP, CourrierPolicy::view()
    // refusait alors l'accès à absolument tous les courriers pour tout
    // utilisateur de test. Même mécanisme que l'avertissement déjà
    // documenté dans les notes internes : une
    // valeur par défaut SQL seule ne suffit pas, il faut aussi la
    // répliquer ici pour toute instance non rechargée depuis la base.
    protected $attributes = [
        'actif' => true,
        'niveau_confidentialite' => 1,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'actif' => 'boolean',
            'derniere_connexion_le' => 'datetime',
            'niveau_confidentialite' => 'integer',
            'preferences_notifications' => 'array',
        ];
    }

    // Module 9 — les Policies vérifient $user->profil->nom, jamais un $user->role
    public function profil(): BelongsTo
    {
        return $this->belongsTo(Profil::class);
    }

    // Module 4/6 — service d'appartenance (Collaborateur, Responsable de service).
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    // Système de privilèges (2026-09-15, voir DECISIONS.md "Système de
    // privilèges") : privilèges assignés à CET utilisateur précisément, en
    // plus de ceux hérités de son profil — additif uniquement (pas de
    // "retrait" par utilisateur dans cette version).
    public function privilegesDirectes(): BelongsToMany
    {
        return $this->belongsToMany(Privilege::class);
    }

    // Module 1/4 — les personnes que CET utilisateur (agent/réceptionniste)
    // a le droit de choisir dans la modale "Transférer à" — curatée par
    // l'administrateur, pas dérivée d'un profil/privilège (voir
    // DECISIONS.md "Destinataires de transfert").
    public function destinatairesTransfert(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'destinataires_transfert', 'agent_id', 'destinataire_id');
    }

    // Module 1 — pli confidentiel adressé à tout un SERVICE plutôt qu'à une
    // personne nommée (2026-10-07, voir DECISIONS.md "Destinataires de
    // transfert — services"). Table parallèle à destinataires_transfert,
    // même convention de curation par agent.
    public function destinatairesTransfertServices(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'destinataires_transfert_services', 'agent_id', 'service_id');
    }

    // Module "Organisation" v2 (2026-09-22, spec §5) — rattachement de
    // TRAVAIL de cet utilisateur à une ou plusieurs unités organisationnelles.
    public function organizationUnits(): BelongsToMany
    {
        return $this->belongsToMany(OrganizationUnit::class, 'organization_unit_user')
            ->withPivot(['is_primary', 'role_in_unit'])
            ->withTimestamps();
    }

    // Module "Organisation" v2 (spec §19 "Périmètre d'accès") — unités
    // organisationnelles pour lesquelles cet utilisateur a un périmètre de
    // VISIBILITÉ élargi (courriers), distinct de organizationUnits() ci-dessus
    // (rattachement de travail). Voir Courrier::estDansLePerimetreDe().
    public function organizationUnitsPerimetre(): BelongsToMany
    {
        return $this->belongsToMany(OrganizationUnit::class, 'organization_unit_perimetre_user');
    }

    // Remplace les comparaisons `$user->profil?->nom === 'Administrateur'`
    // codées en dur dans les Policies — voir DECISIONS.md. Effectif = union
    // des privilèges du profil ET des privilèges assignés individuellement.
    public function hasPrivilege(string $cle): bool
    {
        return $this->privilegesCles()->contains($cle);
    }

    public function frequenceRelanceSlaJours(): int
    {
        $personnalisee = $this->preferences_notifications['sla_relance_jours'] ?? null;

        return is_numeric($personnalisee)
            ? max(1, min(10, (int) $personnalisee))
            : Parametre::actuel()->sla_relance_jours;
    }

    // once() : mémoïsé pour la durée de la requête — plusieurs abilities
    // sont souvent vérifiées sur la même page (Règle n°3, éviter de
    // re-requêter à chaque hasPrivilege()).
    public function privilegesCles(): Collection
    {
        return once(fn () => ($this->profil?->privileges->pluck('cle') ?? collect())
            ->merge($this->privilegesDirectes->pluck('cle'))
            ->unique());
    }

    // 2026-10-23 (voir DECISIONS.md "Transmission descendante par la
    // Direction") — services des unités situées SOUS une entité dont cet
    // utilisateur est le responsable (ex. le chef d'une Direction suit les
    // courriers de ses départements). Lecture seule : seule la CONSULTATION
    // s'appuie dessus (CourrierPolicy::view(), Courrier::scopeVisiblePar()),
    // jamais l'affectation ni la validation. Mémoïsé (vérifié plusieurs fois
    // par page), même principe que privilegesCles().
    public function idsServicesSupervises(): array
    {
        return once(function () {
            $entitesDirigees = OrganizationUnit::where('responsible_user_id', $this->id)->get();

            return $entitesDirigees->isEmpty() ? [] : OrganizationUnit::idsServicesReelsSousArbre($entitesDirigees);
        });
    }

    // 2026-10-26 — entité affichée dans le menu du compte : celle qu'il
    // dirige, sinon son rattachement principal, sinon l'entité reliée à son
    // service (même ordre que la colonne « Département » de Utilisateurs &
    // Accès, voir UserList::departementLabelDe()). Mémoïsé : lu à chaque page.
    public function entitePrincipale(): ?OrganizationUnit
    {
        return once(fn () => OrganizationUnit::where('responsible_user_id', $this->id)->first()
            ?? $this->organizationUnits()->orderByDesc('organization_unit_user.is_primary')->first()
            ?? ($this->service_id ? OrganizationUnit::where('service_id', $this->service_id)->first() : null));
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    // Page "Utilisateurs & Accès" (2026-09-21) — photo de profil optionnelle,
    // stockée sur S3 (Règle n°4, jamais le disque local) via AvatarService.
    // `initials()` ci-dessus reste le repli d'affichage tant qu'aucune photo
    // n'a été téléversée. 2026-10-26 — servie par l'application
    // (UtilisateurPhotoController), jamais par un lien direct vers le bucket
    // privé ; `v` change avec chaque nouvelle photo (cache navigateur).
    public function photoUrl(): ?string
    {
        return $this->photo_path
            ? route('utilisateurs.photo', ['user' => $this->id, 'v' => substr(md5($this->photo_path), 0, 8)])
            : null;
    }

    // Garde-fou anti-verrouillage (même principe que
    // PrivilegePolicy::gerer(), voir DECISIONS.md "Système de privilèges")
    // — un Administrateur reçoit déjà TOUS les privilèges automatiquement
    // (PrivilegeSeeder, "admin should have all the privileges") : sans ce
    // repli, il resterait bloqué par la confidentialité (niveau par défaut
    // 1) sur ses propres courriers "confidentiel"/"très confidentiel" tant
    // que personne ne lui assigne explicitement un niveau plus élevé — une
    // régression réelle constatée en testant (voir le journal de développement).
    //
    // 2026-09-21 — la DGA N'EST PAS incluse ici, sur correction explicite de
    // l'utilisateur : "the dga profile will have the permissions or niveau
    // elevated" — un compte DGA a un niveau de confidentialité ÉLEVÉ parce
    // que l'administrateur le configure ainsi (donnée réelle, colonne
    // `niveau_confidentialite`), pas parce que le code fait une exception
    // cachée basée sur le nom du profil. Contrairement à Administrateur
    // (garde-fou anti-verrouillage volontaire, précédent déjà établi par
    // PrivilegePolicy::gerer()), rien n'empêche techniquement une DGA
    // d'avoir un niveau insuffisant si elle n'a jamais été configurée —
    // c'est une responsabilité d'administration, pas une garantie du code.
    // Plafond partagé par le garde-fou Administrateur ci-dessous et par la
    // validation du niveau (voir UserList::ajouter()/enregistrerEdition()),
    // un seul endroit à changer si l'échelle évolue encore. Configurable
    // depuis l'UI ("Group A", 2026-09-24, voir DECISIONS.md "Paramètres
    // système configurables — Groupe A/B") — ex-`const NIVEAU_CONFIDENTIALITE_MAX`,
    // remplacée par ce lookup dynamique sur Parametre.
    public static function niveauConfidentialiteMax(): int
    {
        return Parametre::actuel()->niveau_confidentialite_max;
    }

    public function niveauConfidentialiteEffectif(): int
    {
        return $this->profil?->nom === 'Administrateur' ? self::niveauConfidentialiteMax() : $this->niveau_confidentialite;
    }
}
