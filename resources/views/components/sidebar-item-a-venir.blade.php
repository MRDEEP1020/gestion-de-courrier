{{-- Nouvelle maquette sidebar fournie par l'utilisateur (2026-09-18) : elle
     liste plusieurs modules qui n'existent pas encore dans l'application
     (Dossiers & Archives, Organisation, Automatisation, Workflows, SLA &
     Alertes, Sécurité & Audit, Statistiques & Rapports en page dédiée,
     Aide) — demande explicite de l'utilisateur (clarifié via
     AskUserQuestion) : les inclure quand même dans la sidebar, mais
     visiblement désactivés, plutôt que de les omettre ou de les faire
     pointer vers une page qui n'existe pas. Badge texte "Bientôt" retiré
     (2026-09-18, demande explicite de l'utilisateur) — contribuait aussi à
     la barre de défilement horizontale parasite de la sidebar (voir
     le journal de développement) en ajoutant de la largeur non tronquée sur chaque
     ligne. Le style grisé/non cliquable ci-dessous suffit à signaler que
     ces liens ne sont pas encore actifs, sans texte supplémentaire.
     `pointer-events-none` + `opacity-60` : aucun `href`/`wire:click`, le
     composant flux:sidebar.item rendrait sinon un simple <button> cliquable
     qui ne ferait rien (pire qu'un lien mort visible). --}}
@props(['icon'])

<flux:sidebar.item :icon="$icon" class="pointer-events-none opacity-60">
    {{ $slot }}
</flux:sidebar.item>
