{{-- 2026-10-14 — page de connexion (demande explicite : "design the login
     page", puis "redesing it"). Seconde version : la première (dégradé navy
     uni + icône scaffold Laravel) ignorait les VRAIS assets de marque déjà
     présents dans public/images (logo NSIA, immeuble NSIA, filets dorés,
     courbes — les mêmes que x-sidebar-artwork). Mêmes techniques de fusion
     que la sidebar, pour la même raison (JPEG = aucun canal alpha, fonds
     NOIR PUR) :
     - courbes / filets dorés : mix-blend-screen (le noir pur s'annule) ;
     - immeuble : le VRAI bâtiment NSIA (photo fournie par l'utilisateur le
       2026-10-14, "nsia2.jpeg is the real building" — déplacée de la racine
       du repo vers public/images/nsia/building-real.jpg ; building.jpeg,
       façade vitrée bleue générée, n'est plus utilisé nulle part — la
       sidebar est passée au vrai bâtiment le même jour).
       Fond NOIR PUR mesuré (canal max = 2 dans le ciel) -> mix-blend-lighten,
       PAS screen (screen délave une photo) : lighten garde, pixel par pixel,
       le plus clair des deux — le noir laisse passer le dégradé navy, la
       façade crème éclairée reste intacte. Portrait (853×1280) : ancré en
       bas à droite, hauteur fixée, largeur auto (ratio natif, jamais
       étiré), bord gauche fondu par mask-image ; la colonne de texte est
       volontairement étroite (max-w-108) pour ne jamais passer
       par-dessus la façade claire (texte blanc illisible sur crème).
       Hauteurs calées sur captures : 45 % sous xl (paragraphe masqué),
       64 % en xl (1280–1535 px, sinon la tour gauche passe sous le
       paragraphe), 72 % à partir de 2xl. Absent du bandeau mobile.
     Tous les calques fusionnés vivent dans des conteneurs AU FOND OPAQUE
     (le dégradé est posé sur le conteneur lui-même) : un mix-blend-mode
     contre un fond transparent assombrit au lieu de s'annuler.
     Courbes/filets : mask-image obligatoire — leur brume grise touche les
     bords du JPEG, d'où des arêtes rectangulaires nettes sans fondu
     (constaté sur capture headless Chrome).
     Logo NSIA (public/images/apercu/nsia-logo.png, 120×46) : biseau clair
     intégré au PNG (1 px haut/gauche, 2 px droite, 5 px bas — mesuré au
     pixel), retiré par clip-path en pourcentages. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-dvh bg-white antialiased dark:bg-zinc-900">
        <div class="flex min-h-dvh">
            {{-- Panneau de marque (desktop) — couleurs écrites en dur : la
                 règle globale [data-flux-heading] force le noir (app.css),
                 d'où des balises HTML simples ici plutôt que flux:heading. --}}
            <aside class="relative isolate hidden w-[56%] shrink-0 overflow-hidden lg:sticky lg:top-0 lg:flex lg:h-dvh xl:w-[58%]">
                <div aria-hidden="true" class="pointer-events-none absolute inset-0 -z-10 bg-linear-to-br from-brand-sidebar-bg-from via-brand-navy-dark to-brand-sidebar-bg-to">
                    <div class="absolute inset-0 bg-cover bg-center opacity-70" style="background-image: url('{{ asset('images/nsia/sidebar-background.jpeg') }}')"></div>
                    <div class="absolute -top-16 -right-24 h-[70%] w-[55%] bg-cover bg-top-left opacity-30 mix-blend-screen" style="background-image: url('{{ asset('images/nsia/abstract-curves.jpeg') }}'); mask-image: radial-gradient(ellipse 95% 95% at 100% 0%, #000 30%, transparent 75%); -webkit-mask-image: radial-gradient(ellipse 95% 95% at 100% 0%, #000 30%, transparent 75%)"></div>
                    <div class="absolute inset-x-0 top-[38%] h-[45%] bg-cover bg-center opacity-35 mix-blend-screen" style="background-image: url('{{ asset('images/nsia/gold-light-trails.jpeg') }}'); mask-image: linear-gradient(to bottom, transparent, #000 30%, #000 70%, transparent); -webkit-mask-image: linear-gradient(to bottom, transparent, #000 30%, #000 70%, transparent)"></div>
                </div>

                {{-- Avant le contenu dans le DOM et SANS z-index : le contenu
                     (relative, z-auto) se peint donc par-dessus, tandis que
                     ce calque reste dans la pile du <aside> isolé — un
                     z-index ici ou sur le contenu créerait un groupe isolé
                     et le fond noir du JPEG ne s'annulerait plus. --}}
                <img src="{{ asset('images/nsia/building-real.jpg') }}" alt="" class="pointer-events-none absolute right-[3%] bottom-0 h-[45%] w-auto max-w-none mix-blend-lighten xl:h-[64%] 2xl:h-[72%]" style="mask-image: linear-gradient(to right, transparent, #000 30%); -webkit-mask-image: linear-gradient(to right, transparent, #000 30%)">

                <div class="relative flex h-full w-full flex-col px-10 pt-10 pb-8 xl:px-14">
                    <div class="flex items-center gap-4">
                        <img src="{{ asset('images/apercu/nsia-logo.png') }}" alt="NSIA Assurances" class="h-10 w-auto" style="clip-path: inset(2.5% 2% 12% 1.5% round 0.375rem)">
                        <span class="h-8 w-px bg-white/20"></span>
                        <div class="leading-tight">
                            <p class="text-sm font-semibold tracking-[0.2em] text-white">{{ config('app.name', 'GEC') }}</p>
                            <p class="text-xs text-white/60">{{ __('Gestion Électronique du Courrier') }}</p>
                        </div>
                    </div>

                    <div class="mt-14 max-w-108 xl:mt-16">
                        <p class="flex items-center gap-3 text-xs font-semibold tracking-[0.25em] text-brand-blue uppercase">
                            <span class="h-px w-8 bg-brand-blue"></span>
                            {{ __('Espace collaborateurs') }}
                        </p>
                        <p class="mt-5 text-4xl leading-tight font-semibold tracking-tight text-white">
                            {{ __('Chaque courrier suivi,') }}<br>
                            <span class="text-brand-blue">{{ __('du premier au dernier jour.') }}</span>
                        </p>
                        <p class="mt-5 hidden leading-relaxed text-white/70 xl:block">
                            {{ __('Enregistrement, acheminement, traitement et archivage : un circuit unique, traçable et sécurisé pour tout le courrier de NSIA Assurances.') }}
                        </p>
                        <ul class="mt-8 flex flex-wrap gap-2.5">
                            <li class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/[0.07] px-3.5 py-1.5 text-sm text-white/90 backdrop-blur-sm">
                                <flux:icon.document-check class="size-4 text-brand-blue" />
                                {{ __('Traçabilité complète') }}
                            </li>
                            <li class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/[0.07] px-3.5 py-1.5 text-sm text-white/90 backdrop-blur-sm">
                                <flux:icon.clock class="size-4 text-brand-blue" />
                                {{ __('Délais maîtrisés') }}
                            </li>
                            <li class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/[0.07] px-3.5 py-1.5 text-sm text-white/90 backdrop-blur-sm">
                                <flux:icon.shield-check class="size-4 text-brand-blue" />
                                {{ __('Accès sécurisé') }}
                            </li>
                        </ul>
                    </div>

                    <div class="flex-1"></div>

                    <div class="mt-4">
                        <p class="text-sm font-light text-brand-blue italic">{{ __('Protéger aujourd\'hui, bâtir demain.') }}</p>
                        <div class="mt-2 h-px w-10 bg-brand-blue/60"></div>
                    </div>
                </div>
            </aside>

            <div class="flex min-h-dvh min-w-0 flex-1 flex-col">
                {{-- Bandeau de marque (mobile/tablette) — remplace le panneau
                     ci-dessus, masqué sous lg. --}}
                {{-- Sans l'immeuble : sur 390 px, aucune place où il ne
                     passerait pas sous le titre (texte blanc sur façade
                     crème illisible, constaté sur capture). --}}
                <header class="relative isolate overflow-hidden px-6 pt-8 pb-16 sm:px-10 lg:hidden">
                    <div aria-hidden="true" class="pointer-events-none absolute inset-0 -z-10 bg-linear-to-br from-brand-sidebar-bg-from to-brand-sidebar-bg-to">
                        <div class="absolute -top-10 -right-16 h-72 w-56 bg-cover bg-top-left opacity-35 mix-blend-screen" style="background-image: url('{{ asset('images/nsia/abstract-curves.jpeg') }}'); mask-image: radial-gradient(ellipse 95% 95% at 100% 0%, #000 30%, transparent 75%); -webkit-mask-image: radial-gradient(ellipse 95% 95% at 100% 0%, #000 30%, transparent 75%)"></div>
                        <div class="absolute inset-x-0 bottom-0 h-28 bg-cover bg-center opacity-40 mix-blend-screen" style="background-image: url('{{ asset('images/nsia/gold-light-trails.jpeg') }}'); mask-image: linear-gradient(to bottom, transparent, #000 55%); -webkit-mask-image: linear-gradient(to bottom, transparent, #000 55%)"></div>
                    </div>
                    <img src="{{ asset('images/apercu/nsia-logo.png') }}" alt="NSIA Assurances" class="h-9 w-auto" style="clip-path: inset(2.5% 2% 12% 1.5% round 0.375rem)">
                    <p class="mt-6 text-2xl leading-tight font-semibold text-white">
                        {{ __('Chaque courrier suivi,') }}<br>
                        <span class="text-brand-blue">{{ __('du premier au dernier jour.') }}</span>
                    </p>
                </header>

                {{-- Feuille blanche qui recouvre le bas du bandeau sur mobile
                     (-mt-8 + coins arrondis), simple colonne blanche sur desktop. --}}
                <main class="relative -mt-8 flex flex-1 flex-col rounded-t-4xl bg-white lg:mt-0 lg:rounded-none dark:bg-zinc-900">
                    <div class="flex flex-1 items-start justify-center px-6 pt-10 pb-8 sm:px-10 lg:items-center lg:px-10 lg:py-8 xl:px-16">
                        <div class="w-full max-w-md">
                            {{ $slot }}
                        </div>
                    </div>

                    <footer class="flex flex-wrap items-center justify-center gap-x-6 gap-y-1 px-6 pb-6 text-xs text-zinc-500 sm:px-10 lg:px-10 xl:justify-between xl:px-16 dark:text-zinc-400">
                        <span class="whitespace-nowrap">&copy; {{ now()->year }} NSIA Assurances</span>
                        <span class="inline-flex items-center gap-1.5 whitespace-nowrap">
                            <flux:icon.lock-closed class="size-3.5" />
                            {{ __('Accès réservé au personnel autorisé') }}
                        </span>
                    </footer>
                </main>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
