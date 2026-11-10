<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">


@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
{{--
    Thème clair par défaut (2026-09-16, demande explicite de l'utilisateur
    après la maquette GPT du tableau de bord — voir DECISIONS.md "Thème
    clair par défaut") : sans préférence explicite déjà enregistrée, le
    script Flux ci-dessous retombe sur 'system', qui affichait l'app en
    sombre dès que l'OS/le navigateur préfère le sombre — jamais le rendu
    clair et coloré de la maquette. Pré-remplit 'light' AVANT que ce script
    ne lise flux.appearance, uniquement si rien n'a encore été choisi — un
    choix explicite fait depuis Réglages > Apparence (light/dark/system)
    écrase cette valeur et reste ensuite prioritaire. Bug corrigé
    (2026-09-16) : écrire le nom de la directive Blade juste en dessous en
    toutes lettres dans un commentaire JS cassait la page — Blade ne
    reconnaît pas les commentaires JS, il remplace la directive PARTOUT où
    le texte apparaît dans le fichier, y compris ici.

    2026-10-27 (liste « Phase 7 » : le thème sombre ne doit s'appliquer qu'au
    compte qui l'a choisi) — la préférence vient désormais du COMPTE
    (users.apparence), plus du navigateur : elle est réécrite dans
    flux.appearance à chaque page, juste avant le script d'apparence de Flux.
    Visiteur non connecté (connexion, mot de passe oublié…) : toujours clair,
    donc le choix d'un utilisateur ne passe jamais au suivant sur le même
    poste. « system » = clé retirée (Flux suit alors le réglage de l'OS) ;
    auparavant, ce choix retombait sur « clair » au rechargement.
--}}
@php
    $apparenceCompte = in_array(auth()->user()?->apparence, ['light', 'dark', 'system'], true)
        ? auth()->user()->apparence
        : 'light';
@endphp
<script>
    (() => {
        const apparence = @js($apparenceCompte);

        try {
            if (apparence === 'system') {
                window.localStorage.removeItem('flux.appearance');
            } else {
                window.localStorage.setItem('flux.appearance', apparence);
            }
        } catch (e) {}
    })();
</script>
@fluxAppearance
