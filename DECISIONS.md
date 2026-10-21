# DECISIONS.md — Journal des décisions techniques

Chaque décision technique significative est ajoutée ici, datée, avec la justification.
But : empêcher qu'une décision déjà prise soit re-questionnée ou changée silencieusement
lors d'une évolution future.

Format :
```
## [AAAA-MM-JJ] Titre court de la décision
Contexte : pourquoi ce choix était nécessaire
Décision : ce qui a été choisi
Alternatives envisagées : ce qui a été écarté et pourquoi
```

---

## [2026-09-03] Choix de la base de données : MySQL
Contexte : à trancher avant le mois 1
Décision : MySQL. Choix par défaut de Laravel, support le plus large chez les
hébergeurs/IT dans la zone, écosystème d'outils d'administration le plus
répandu. Déjà en place : `.env` configuré, migrations appliquées sur la base
`gec` réelle — ne pas rouvrir cette question sans base concrète pour migrer.
Alternatives envisagées : PostgreSQL (meilleur full-text natif, mais moins
courant sur l'infra/IT locale envisagée) — écarté.

## [2026-09-03] Hébergement : cloud ou serveur local Nsia Assurances
Contexte : conditionne le choix S3 vs MinIO, et la latence réseau (contraintes
Cameroun). Pas encore tranché — à confirmer avec l'IT Nsia.
Décision : reporté. Pour ne pas bloquer le développement, le code utilise
systématiquement `Storage::disk('s3')` avec une config S3-compatible générique
(driver `s3` de Laravel), pointée vers MinIO en dev/local. Le jour où le choix
hébergement est confirmé, seule la config `.env`/`filesystems.php` change (endpoint,
credentials) — aucun code applicatif à réécrire, que ce soit MinIO on-premise ou
S3 réel en cloud.
Alternatives envisagées : trancher immédiatement sans validation IT — écarté,
risque de devoir tout réécrire si le choix retenu diffère.
Complément [2026-09-03] : MinIO local mis en place pour du développement/test
réel (upload de pièces jointes fonctionnel en local, pas seulement simulé par
les tests). Binaire autonome Windows (`minio.exe`/`mc.exe`, pas de Docker
installé sur cette machine) — voir "MinIO local (dev)" plus bas pour les
identifiants et la commande de démarrage. Un vrai fournisseur S3 (AWS ou
S3-compatible) remplacera cette configuration une fois l'hébergement confirmé
avec l'IT Nsia ; il faudra alors aussi installer `league/flysystem-aws-s3-v3`
(dépendance du driver `s3` de Laravel, absente par défaut, désormais requise
dans composer.json).

## [À compléter] Driver de queue : Redis confirmé
Contexte : nécessaire pour Horizon et la Règle n°1 (voir CONVENTIONS.md)
Décision : Redis
Alternatives envisagées : database driver (écarté — pas de monitoring Horizon, moins performant)

## Terminologie : "Profil" au lieu de "Rôle"
Contexte : le vocabulaire métier de Nsia Assurances utilise "profil" (voir l'email RH
d'origine : "identifier un profil à retenir"), pas "rôle"
Décision : utiliser `Profil` / table `profils` dans tout le code (modèles, migrations,
policies), jamais `Role`/`roles`
4 profils phase 1 : Agent, Collaborateur, Responsable de service, Administrateur
Alternatives envisagées : garder "Role" (convention Laravel par défaut) — écarté pour
rester cohérent avec le langage métier du client

## Structure de dossiers : à plat (MVC classique), pas de sous-dossiers par module
Contexte : préférence pour une structure simple à naviguer en solo (pas une question
de performance — le nombre de dossiers n'affecte pas la vitesse d'exécution)
Décision : app/Models, app/Livewire, app/Jobs, app/Policies, app/Notifications — tous
à plat, sans sous-dossiers Courriers/, Workflow/, Dashboard/. La logique de classement
(Module 3) et de calcul SLA (Module 5) reste directement dans les Jobs concernés,
pas de couche Services/ séparée.
Alternatives envisagées : sous-dossiers par module (écarté — plus de navigation
mentale pour un seul développeur, sans bénéfice de performance)
Note d'application [2026-09-03] : appliqué au code déjà écrit (RegistrationForm,
EditForm, ShowCourrier, CourrierList, ValidationCircuit, DashboardHome déplacés
à plat sous app/Livewire/). `Livewire/Forms/` et `Services/` restent des
sous-dossiers *par type* (convention Livewire / séparation logique), pas par
module — cohérents avec cette décision, pas une exception à celle-ci.
Révisé [2026-09-03] par la décision "Séparation backend/frontend" ci-dessous :
les composants ont ensuite été déplacés sous `app/Livewire/Backend/` (la
structure "à plat par module" reste vraie *à l'intérieur* de Backend/).

## [2026-09-03] Séparation backend/frontend pour les composants Livewire GEC
Contexte : demande explicite de séparer la logique (backend) de l'affichage
(frontend), à l'intérieur des conventions Laravel existantes (pas de
restructuration racine — voir l'option écartée ci-dessous).
Décision : `app/Livewire/Backend/` pour les classes des composants GEC
(RegistrationForm, EditForm, ShowCourrier, CourrierList, ValidationCircuit,
DashboardHome, Forms/CourrierForm), namespace `App\Livewire\Backend` — reste
sous la convention PSR-4 standard `App\ => app/`, aucun mapping composer.json
personnalisé requis. `resources/views/livewire/frontend/` pour leurs vues
Blade, référencées via un namespace de vue Laravel dédié `frontend::`. Les
deux emplacements ne sont pas connus de Livewire par défaut (qui ne scanne que
`App\Livewire` et `resources/views/livewire`) : `App\Livewire\Backend` est
déclaré via `Livewire::addLocation(classNamespace: ...)` et `frontend::` via
`View::addNamespace(...)`, tous deux dans `AppServiceProvider::boot()`.
Point d'attention retenu : la casse du dossier physique doit correspondre
exactement à la casse du namespace PHP (`Backend`, pas `backend`) — sur
Windows (insensible à la casse) une différence passe inaperçue en local mais
casse le chargement des classes sur un serveur de production Linux
(sensible à la casse).
Alternatives envisagées : vrais dossiers `backend/`/`frontend/` à la racine du
projet (regroupant aussi public/, bootstrap/, vendor/, routes/, database/,
config/) — écarté, casserait l'autoload Composer et la découverte du site par
Herd (le serveur local), qui attendent `public/` etc. à la racine.

## [2026-09-03] Format et génération du numéro de référence (Module 1)
Contexte : specifications-modules-GEC.md donne l'exemple `GEC-2026-DIR-000123`
(année + code service + séquence), mais le modèle de données initial ne
prévoyait pas de code court par service, et une génération naïve
("MAX(numero) + 1") est sujette aux doublons sous requêtes concurrentes malgré
la contrainte unique (Règle n°3 CONVENTIONS.md).
Décision : format `GEC-{année}-{code service}-{séquence sur 6 chiffres}`. Ajout
d'une colonne `services.code` (courte, unique). Séquence tenue dans une table
dédiée `numero_sequences` (année, service_id, dernier_numero), incrémentée sous
verrou de ligne (`lockForUpdate`) dans `NumeroReferenceGenerator`, avec un
retry sur violation de contrainte unique pour le cas limite de la toute
première ligne d'une (année, service). La contrainte unique sur
`courriers.numero_reference` reste le filet de sécurité final.
Alternatives envisagées : `MAX(numero_reference) + 1` sur `courriers` — écarté,
race condition classique sous charge concurrente ; UUID sans séquence lisible —
écarté, ne respecte pas le format attendu par les utilisateurs métier.

## [2026-09-03] Nommage des fichiers Blade et Livewire : anglais
Contexte : demande explicite (ex. `RegistrationForm.php` / `registrationForm.blade.php`),
au lieu du français/kebab-case par défaut de Laravel.
Décision : les classes Livewire et fichiers `.blade.php` associés sous
`resources/views/livewire/` sont nommés en anglais, en camelCase pour les vues
(ex. `registrationForm.blade.php`). Le chemin de vue est toujours explicite
dans la méthode `render()` du composant. Les modèles Eloquent, colonnes DB,
routes/URLs et vocabulaire métier restent en français (voir décision
"Terminologie" ci-dessus) — seule la couche fichiers/classes Livewire+Blade
change de langue.
Alternatives envisagées : tout traduire (modèles, DB, routes) — écarté, annulerait
la décision terminologie déjà prise ; kebab-case français par défaut — écarté
sur demande explicite.

## [2026-09-03] Génération PDF : barryvdh/laravel-dompdf
Contexte : Module 1 exige un bordereau d'enregistrement / accusé de réception
imprimable (PDF).
Décision : `barryvdh/laravel-dompdf` (v3). Pur PHP, aucun binaire ni Node.js
requis sur le serveur — important tant que le choix d'hébergement (serveur
local Nsia vs cloud) reste ouvert. Rendu HTML/CSS simple (voir
`resources/views/pdf/bordereau.blade.php`, sans Tailwind/Flux — dompdf a un
support CSS limité, mise en page en tableaux classiques).
Alternatives envisagées : `spatie/browsershot` (rendu Chrome/Puppeteer, plus
fidèle visuellement mais ajoute une dépendance Node + Chromium au déploiement)
— écarté pour un document aussi simple.

## [2026-09-03] Pièces jointes : table dédiée `pieces_jointes` (un-à-plusieurs)
Contexte : la première implémentation du Module 1 réutilisait `courriers.fichier_path`
(colonne unique) pour la pièce jointe optionnelle à l'enregistrement — ne
permettait qu'un seul fichier par courrier et entrait en conflit avec le rôle
de `fichier_path` (document principal scanné, Module 2).
Décision : table `pieces_jointes` (courrier_id FK, fichier_path, nom_original,
type_mime, taille), relation un-à-plusieurs avec `courriers`, écriture unique
par ligne (pas d'updated_at). `courriers.fichier_path` reste réservé au document
principal du Module 2. Nommage de stockage :
`courriers/{annee}/{code service}/{numero_reference}/{uuid}.{ext}` (le uuid
différencie plusieurs pièces jointes d'un même courrier). Modèle `PieceJointe`
avec `$table = 'pieces_jointes'` explicite (Eloquent déduirait `piece_jointes`
du nom de classe sinon).
Alternatives envisagées : garder `fichier_path` unique et refuser les pièces
jointes multiples — écarté, ne correspond pas à l'usage réel (plusieurs
documents peuvent accompagner un même courrier, ex. constat + photos).

## [2026-09-03] Moteur OCR (Module 2) : Tesseract local, français + anglais
Contexte : Module 2 exige l'extraction du texte des courriers scannés, en
français et en anglais (annotation de l'utilisateur dans
specifications-modules-GEC.md). L'hébergement (cloud vs serveur local Nsia,
réseau Cameroun) n'est pas tranché.
Décision : Tesseract OCR (binaire open source, hors ligne, gratuit) via le
wrapper PHP `thiagoalessio/tesseract_ocr`, langues `fra+eng`. Chemin du
binaire et dossier `tessdata` configurables par `.env` (`TESSERACT_PATH`,
`TESSERACT_TESSDATA_PATH`, voir `config/services.php`) pour ne dépendre ni du
PATH système ni de l'emplacement Windows/Linux. Traitement exclusivement en
Job (`ProcessDocumentOcr`, queue `ocr`, Règle n°1), statut de traitement
séparé (`courriers.ocr_statut`) du statut de circuit (`statut`, Module 4).
Contrôle qualité phase 1 : seuil minimal de texte extrait (20 caractères) et
confiance moyenne par mot ≥ 55 % (sortie TSV de Tesseract, une seule passe
pour le texte et les scores) — en dessous, `ocr_statut = echec_qualite` et un
re-scan est proposé ; le document scanné ne devient immuable (Règle n°4)
qu'une fois l'OCR réussi. Conséquence pratique : un dossier tessdata
personnalisé doit contenir `configs/` (fichier `tsv`) en plus des langues,
sinon Tesseract retombe en texte brut et le job signale une configuration
incomplète (vécu le 2026-09-03).
**[2026-09-04, résolu] OCR des PDF via Ghostscript.** Tesseract lit les
images nativement mais pas les PDF directement — le message d'erreur ("Pdf
reading is not supported") laisse penser que Ghostscript suffirait à le
débloquer nativement ; **faux** sur ce build Windows (UB-Mannheim) : même
Ghostscript installé et sur le PATH, Tesseract ne l'invoque pas lui-même
pour un PDF (vérifié en reproduisant l'échec après installation). La
vraie solution : convertir le PDF en image **avant** d'appeler Tesseract.
`ProcessDocumentOcr::ocrFichier()` (nouveau point d'entrée OCR commun, utilisé
par `ProcessDocumentOcr` et `ProcessBrouillonOcr`) détecte un PDF et appelle
`convertirPdfEnImages()` : Ghostscript (`GHOSTSCRIPT_PATH`, `services.ghostscript.binary`)
rasterise chaque page en PNG 300 dpi (`Illuminate\Support\Facades\Process`),
chaque page est OCRisée séparément, le texte est concaténé et la confiance
moyennée sur l'ensemble. Sans `GHOSTSCRIPT_PATH` configuré (vide par défaut),
comportement historique inchangé : un PDF est tenté tel quel et échoue
proprement (`ocr_statut = echec`, historique + log). Installation sur ce
poste Windows : l'installeur initial (`gs-installer.exe`, 6,1 Mo) était
corrompu (échec d'intégrité NSIS) — retéléchargé (Ghostscript 10.07.1 réel,
62 Mo) directement via `Invoke-WebRequest` depuis les releases GitHub
d'ArtifexSoftware/ghostpdl-downloads (pas de droits admin nécessaires pour le
téléchargement ; l'installation elle-même si). Vérifié en conditions réelles :
un vrai PDF 2 pages (le document ITSC Sarl/NSIA du 2026-09-04) → `ocr_statut
reussi`, confiance 89 %, texte cohérent avec le document. Sur serveur Linux,
`apt install ghostscript` suffit (le binaire `gs` est alors trouvé par
défaut).
**Toujours pas résolu** (problème séparé) : Tesseract lisant maintenant les
PDF, le tampon d'entrée coloré (encre rose pâle) sur ce même document reste
illisible — `numero_tampon_detecte` toujours vide sur ce test malgré l'OCR
globalement réussi. Voir l'entrée "Numéro de tampon (OCR)" : problème de
contraste/couleur sur une photo de téléphone, pas résolu par Ghostscript.
Normalisation PDF/A ("recommandé" par le spec) : toujours reportée, non
bloquante pour le MVP.
**[2026-09-04] Abandon de la piste "lire le tampon" pour pré-remplir le
service/type — remplacée par la classification du texte OCR.** Un essai de
prétraitement d'image ciblé (recadrage de la zone du tampon depuis un rendu
Ghostscript 600 dpi, agrandissement ×8, renforcement du contraste) a été
tenté sur le document réel ci-dessus. Le tampon est *visible* à l'œil une
fois recadré et agrandi ("NSIA ASSURANCES", "31 JUL '26 10:26:48-1789553",
les sigles de service avec le cercle manuscrit) mais reste petit et à faible
contraste — pas assez fiable pour être une base de production sans plus
d'expérimentation, et surtout : l'utilisateur a explicitement demandé
d'abandonner cette piste ("on ne va plus utiliser le tampon") au profit
d'une approche différente, détaillée dans l'entrée "Proposition automatique
du service/type à la pré-visualisation du brouillon" ci-dessous. Le numéro
de tampon détecté (`numero_tampon_detecte`) reste affiché quand il est
disponible (aucune régression), mais n'est plus la voie principale envisagée
pour le pré-remplissage.

## [2026-09-04] Proposition automatique du service/type à la pré-visualisation du brouillon (Module 1/3)
Contexte : demande explicite de l'utilisateur — "le système doit détecter
les informations et les mettre dans un service correspondant, et attendre
la validation d'un humain pour qu'il puisse vérifier si c'est correct ou non
et modifier". Remplace la tentative de lecture du tampon (abandonnée, voir
entrée précédente) comme mécanisme de pré-remplissage automatique du flux
scan-first.
Décision : `RegistrationForm::proposerServiceEtType()` réutilise
**exactement le même moteur** que le classement post-enregistrement
(`ClassificationService::classer()`, Module 3 — mêmes règles configurées par
un administrateur, voir "Classement automatique (Module 3)") plutôt que
d'en construire un second. Appliqué au moment où le formulaire se pré-remplit
depuis un brouillon (`RegistrationForm::mount()`, seulement si
`ocr_statut === 'reussi'`) : un `Courrier` non persisté, avec uniquement
`texte_ocr`/`ocr_statut` renseignés (objet/expéditeur pas encore saisis à ce
stade), est passé à `classer()`. Si une règle correspond, `form.service_id`
et `form.type_document` sont pré-remplis — jamais imposés : les deux champs
restent des `<flux:select>`/`<flux:input>` normaux, entièrement modifiables,
avec juste un indicateur visuel (`serviceEtTypeProposes`, texte ambre
"proposé automatiquement — à vérifier") pour que l'agent sache qu'il doit
relire avant de confirmer — même principe que le panneau Classement de
`ShowCourrier` pour les courriers déjà enregistrés. Si l'OCR a échoué, si
aucun texte n'est disponible, ou si aucune règle ne correspond, les champs
restent simplement vides (comportement inchangé, aucune fausse proposition).
Alternatives envisagées : un second moteur de proposition dédié au
scan-first (écarté — dupliquerait `ClassificationService`, deux logiques à
maintenir en synchronisation) ; imposer directement le service sans
validation (écarté — contraire au principe "jamais imposé sans validation"
appliqué partout ailleurs, et à la demande explicite de l'utilisateur).
Limite connue (constatée en test réel le 2026-09-03) : Tesseract ne lit que le
texte **imprimé**. Une page manuscrite (stylo, ratures, photo) donne "aucun
mot reconnu" → `ocr_statut = echec_qualite`, le document reste consultable en
image et le courrier reste retrouvable par ses métadonnées (objet, expéditeur,
référence — Module 8). Question produit ouverte, à trancher avec Nsia : les
courriers manuscrits (ex. réclamation client écrite à la main) justifient-ils
une API de reconnaissance d'écriture (Google Vision / Azure Read — internet +
coût par page, contraire au choix hors-ligne ci-dessus), ou l'indexation par
métadonnées suffit-elle pour cette minorité de courriers ?
Alternatives envisagées : API cloud (Google Vision, AWS Textract, Azure) —
meilleure précision mais connexion internet obligatoire en production, clé
API et coût par page — écarté tant que l'hébergement n'est pas fixé.

## [2026-09-03] Classement automatique (Module 3) : règles "mots-clés → proposition", validées par l'agent
Contexte : PRD phase 1 = "classement/indexation par règles simples, PAS de
ML" ; spec Module 3 = classement proposé à l'enregistrement, validé ou corrigé
par l'agent, règles paramétrables par un administrateur sans développeur, tags
secondaires multiples, extensible pour les sinistres en phase 2.
Décision :
- Une règle = liste de mots-clés déclencheurs (comparaison insensible à la
  casse et aux accents, "contient") sur des champs surveillés (objet,
  expéditeur, texte OCR ; tous par défaut) → propose un type de document, un
  service, des tags. Priorité numérique : la première règle qui propose une
  valeur l'emporte, les tags de toutes les règles correspondantes se cumulent.
- La proposition est stockée **à part** des valeurs saisies
  (`type_document_propose`, `service_propose_id`, `classement_statut`) : rien
  n'est écrasé sans action de l'agent (Valider applique, Ignorer conserve ;
  corriger vers une autre valeur = formulaire Modifier). Une décision prise
  n'est remise en question que si la proposition change (ex. texte OCR arrivé
  après coup) ; une proposition encore en attente qui ne tient plus (objet
  modifié) est retirée avec trace. Le job horodate chaque analyse
  (`classement_analyse_le`) pour que la fiche distingue « job en file
  d'attente » de « aucune règle ne correspond » — confusion constatée au
  premier test utilisateur.
- Exécution en Job (`IndexCourrierJob`, queue `indexation`, Règle n°1) à
  l'enregistrement, après un OCR réussi, et après modification de l'objet ou
  de l'expéditeur ; notification temps réel sur la fiche.
- Mots-clés secondaires : table `mots_cles` + pivot avec `source`
  (regle | ocr | manuel) ; les tags OCR sont les 10 mots significatifs les plus
  fréquents (hors mots vides FR/EN, hors nombres) ; un tag manuel n'est jamais
  écrasé par l'automatique.
- Une règle créée ou modifiée ne s'applique pas rétroactivement toute seule :
  l'administrateur relance explicitement l'analyse (bouton « Réanalyser » de
  la page des règles, ou `php artisan courriers:reanalyser [--tous]`), qui
  passe par `ReanalyserCourriersJob` → un `IndexCourrierJob` par courrier.
  Automatique à chaque sauvegarde de règle = une vague de jobs sur toute la
  table à chaque retouche — écarté ; par défaut seuls les courriers sans
  décision de l'agent sont concernés.
- Point d'extension phase 2 : le job et l'UI ne connaissent que
  `ClassificationService::classer()` / `extraireMotsCles()` — remplaçables par
  un modèle ML sans toucher au reste.
Complément [2026-09-04] — règle "historique d'expéditeur" (confirmée par le
client, prioritaire sur les mots-clés en cas de conflit) : si l'expéditeur
(nom OU organisation, comparaison normalisée) d'un courrier correspond à celui
d'un courrier passé dont le classement a été **validé** par un agent (signal
fiable — une simple proposition jamais confirmée ne compte pas), le service de
ce courrier passé est proposé, en remplacement du service que la règle
mots-clés aurait proposé (le type de document et les tags des mots-clés
restent, eux, inchangés). Recherche bornée aux 200 courriers validés les plus
récents (`ClassificationService::FENETRE_HISTORIQUE_EXPEDITEUR`) comparés en
PHP (accents/casse) plutôt qu'un index SQL sur du texte normalisé — cohérent
avec l'échelle phase 1 (~120 courriers/jour) ; à revoir si le volume grossit
significativement (Module 8).
Alternatives envisagées : expressions régulières dans les règles (plus
puissant, mais inaccessible à un administrateur non technique — écarté pour la
phase 1) ; écraser directement type/service (contraire au "valider ou
corriger" du spec — écarté) ; TF-IDF pour les mots-clés OCR (mieux qu'une
fréquence brute, mais demande un corpus — à reconsidérer avec le Module 8).

## [2026-09-03] Temps réel : Laravel Reverb (Echo), au prix d'un Guzzle 7
Contexte : la Règle n°1 exige d'informer l'utilisateur "quand le job est
terminé" (OCR, et plus tard alertes du Module 7) ; la Règle n°2 interdit
`wire:poll` et nomme "Echo + Reverb/Pusher".
Décision : Laravel Reverb (serveur WebSocket auto-hébergé, premier choix
cohérent avec le hors-ligne/on-premise) + Laravel Echo côté navigateur.
Reverb 1.x exige `guzzlehttp/psr7 ^2`, alors que le projet avait Guzzle 8 /
psr7 3 (versions les plus récentes tirées à l'installation, sans usage direct
dans le code). Installation avec `-W` : Guzzle 8.1 → 7.15, psr7 3.1 → 2.13,
promises 3 → 2. Laravel 13 et le SDK AWS acceptent les deux lignes ; aucun
code applicatif n'appelle Guzzle directement. À réévaluer (remonter Guzzle 8)
quand Reverb supportera psr7 3.
Les événements de fin de traitement sont diffusés en `ShouldBroadcastNow`
depuis le job, dans un try/catch : un Reverb arrêté ne fait jamais échouer
un traitement métier. Canaux privés par courrier (`courrier.{id}`), accès
via `CourrierPolicy::view` (routes/channels.php).
Alternatives envisagées : Pusher (cloud, coût, internet obligatoire) — écarté ;
Soketi (serveur Node séparé) — écarté, dépendance de plus à exploiter ;
Server-Sent Events maison — écarté, une connexion PHP maintenue par fiche
ouverte ne passe pas à l'échelle de toute l'entreprise.

## [2026-09-04] Flux scan-first (Module 1/2) : table `courrier_brouillons` séparée, finalisation synchrone résiliente
Contexte : specifications-modules-GEC.md décrit le processus réel confirmé —
l'agent scanne d'abord (parfois "des dizaines de courriers d'affilée"), le
système pré-remplit automatiquement le formulaire, l'agent valide. Le code
jusqu'ici faisait l'inverse (RegistrationForm d'abord, ScanForm ensuite sur un
Courrier déjà créé). Avant de coder, une proposition détaillée a été soumise à
une critique à 4 angles indépendants (intégrité des données, conformité
CONVENTIONS.md, impact sur les 133 tests existants, pertinence du périmètre) —
plusieurs problèmes concrets et convergents ont été relevés ; cette entrée
documente le design final, révisé en conséquence.
Décision :
- **Table séparée `courrier_brouillons`**, aucune FK vers `courriers` ni vers
  les tables des Modules 3/4/8/9/10 : `courriers` a `numero_reference`/
  `service_id`/`objet`/`type_document`/`date_mouvement` NOT NULL — un brouillon
  scan-first n'a aucune de ces valeurs. Réutiliser `courriers` avec un flag
  "brouillon" aurait exigé de rendre ces colonnes nullable et de filtrer le
  brouillon dans toutes les requêtes existantes (policies, jobs, dashboard) —
  net surcroît de risque de régression pour zéro bénéfice. Colonnes : id,
  fichier_path, nom_original, type_mime, ocr_statut, texte_ocr, ocr_confiance,
  numero_tampon_detecte (même vocabulaire qu'un Courrier), cree_par_id (FK
  users), **finalise_le** (nullable — voir plus bas), timestamps.
- **`numero_reference` toujours généré à la confirmation**, jamais avant,
  inchangé (`NumeroReferenceGenerator`, séquence verrouillée, Règle n°3) :
  aucun champ n'expose `numero_reference` en écriture, donc la table brouillon
  ne peut pas corrompre cette garantie.
- **OCR du brouillon dans un Job dédié `ProcessBrouillonOcr`** (nouveau),
  PAS une généralisation de `ProcessDocumentOcr` : ce dernier est typé sur
  `Courrier`, crée des `CourrierHistorique` (FK NOT NULL — un brouillon n'en a
  pas encore) et diffuse sur `courrier.{id}`. Modifier sa signature aurait
  cassé `ProcessDocumentOcrTest` et `ScanFormTest` (133 tests verts au moment
  de la décision). `ProcessBrouillonOcr` réutilise telles quelles les méthodes
  statiques déjà indépendantes de `Courrier` (`extraireTexteEtConfiance`,
  `corrigerOrientation`, `estUneAbsenceDeTexte`, `motifQualite`,
  `messageLisible`, `extraireNumeroTampon`) — zéro duplication de la logique
  Tesseract/EXIF/contrôle qualité. Nouveau canal privé `brouillon.{id}`
  (`routes/channels.php`, autorisation par `cree_par_id`) et événement
  `BrouillonOcrTermine`, même pattern que `OcrTermine`.
- **Finalisation (au clic "Enregistrer" avec un brouillon)** : le Courrier est
  créé exactement comme aujourd'hui (numero_reference, historique,
  `IndexCourrierJob` inconditionnel sur objet/expéditeur — pas de régression
  sur le déclenchement existant). Le brouillon n'est consommé qu'**après**,
  hors de cette transaction :
  1. Verrou anti-double-soumission : `courrier_brouillons` verrouillé
     (`lockForUpdate`) dans une courte transaction dédiée qui pose
     `finalise_le = now()` — si déjà posé (double clic, deux onglets), la
     deuxième requête s'arrête là sans retoucher au fichier.
  2. Si `ocr_statut === 'en_cours'` sur le brouillon (l'agent a confirmé plus
     vite que l'OCR n'a fini — cas réel identifié en critique), le formulaire
     refuse la soumission avec un message clair ("numérisation encore en
     cours") plutôt que de perdre la cible du job OCR en supprimant le
     brouillon sous ses pieds ; le canal temps réel rafraîchit la fiche dès
     que l'OCR se termine, l'agent retente.
  3. Copie du fichier vers le chemin final (`courriers/{année}/{code}/{numero_reference}.{ext}`),
     champs OCR copiés sur le Courrier, `ReplicateFichierJob` redispatché sur
     ce nouveau chemin (la copie de secours de l'ancien chemin brouillon
     devient inutile, jamais répliquée elle-même), `IndexCourrierJob`
     redispatché si le texte OCR copié est exploitable (même double
     déclenchement qu'un scan classique), brouillon supprimé seulement après
     confirmation que le fichier existe au chemin final.
  4. **Synchrone, pas un Job**, mais résilient (try/catch, `report($e)`,
     toast d'avertissement) : même pattern déjà établi pour la pièce jointe
     dans `RegistrationForm::enregistrer()`. Une copie S3→S3 d'un seul fichier
     déjà traité, déclenchée par une action utilisateur unique, n'est pas
     assimilée à "OCR/emails/stats/indexation" (la liste explicite de la
     Règle n°1) — c'est la même catégorie que l'upload de pièce jointe,
     déjà synchrone. En cas d'échec : le Courrier reste valide (numéro
     généré, consultable), le brouillon n'est pas supprimé (récupérable), et
     `ScanForm` (inchangé) reste le filet de secours pour re-numériser.
- **Accès au brouillon** : `CourrierBrouillonPolicy` minimale (`utiliser` :
  `cree_par_id === $user->id`), vérifiée à l'ouverture du formulaire pré-rempli
  ET à nouveau à la finalisation (Règle n°6) — pas l'exception "sans Policy"
  envisagée initialement.
- **Liste "brouillons en attente"** : un simple bloc sur `RegistrationForm`
  (pas une page dédiée) listant les scans non encore enregistrés de l'agent
  connecté, avec lien direct. Nécessaire dès ce MVP (pas différé) : le PRD
  pose "courriers perdus ou oubliés" comme le problème n°1 à résoudre, et un
  agent qui scanne plusieurs documents avant d'avoir fini le premier
  formulaire perdrait sinon toute trace du reste.
Explicitement HORS de ce MVP, différé et documenté (pas oublié) :
- Nettoyage automatique des brouillons abandonnés (fichier orphelin si un
  agent scanne puis ne finalise jamais) — commande `courriers:nettoyer-brouillons`
  ajoutée mais **pas** branchée sur le Scheduler par défaut ; un brouillon
  orphelin reste visible dans la liste "en attente" de son auteur, donc
  traitable manuellement (pas un cas de perte silencieuse).
- Détection du service par cercle manuscrit (vision) — toujours différée,
  voir l'entrée "Numéro de tampon (OCR) et détection du cercle manuscrit".
Alternatives envisagées : déplacement/finalisation en Job dédié (écarté après
réflexion — ajoute un état intermédiaire "Courrier créé mais fichier pas
encore là" à gérer partout, pour une opération qui est en pratique rapide et
déjà couverte par le même filet de secours try/catch que la pièce jointe) ;
pointeur `courrier_brouillons.courrier_id` pour laisser un OCR encore en vol
retrouver sa cible après finalisation (écarté — plus simple de simplement
refuser la finalisation tant que l'OCR n'est pas fini, cas rare en pratique
vu la rapidité du traitement observée en test réel).

## [2026-09-04] Circuit de validation (Module 4) : machine à états unique, pas de circuits par type
Contexte : PRD.md §3/§4 tranche explicitement pour la phase 1 — "workflow
générique unique, pas de circuits multiples par type" IN SCOPE, "règles de
workflow avancées différenciées par type de courrier" HORS SCOPE. La colonne
`courriers.statut` (enregistre, affecte, en_traitement, en_validation, traite,
archive, rejete, en_attente_information) existait déjà, scaffoldée dès le
Module 1.
Décision :
- Une seule machine à états, codée en dur dans `WorkflowService::TRANSITIONS`
  (pas de configuration admin, pas de circuit par type) : chaque transition
  vérifiée server-side indépendamment des boutons affichés (Règle n°6),
  chacune trace une entrée `courrier_historiques` immuable (Règle n°5).
  `traite → archive` n'est pas une transition manuelle : le spec Module 9 dit
  "automatiquement transféré vers l'archivage" — ce sera un Job du Module 9,
  pas construit ici.
- Acteurs (`CourrierPolicy::affecter/traiter/valider`) : `affecter` = le
  responsable du service concerné (`services.responsable_id`) ou un
  administrateur ; `traiter` (démarrer/soumettre) = le collaborateur
  actuellement affecté (`Courrier::affectationCourante`, relation `hasOne`
  `latestOfMany()` plutôt qu'un `->first()` sur la collection chargée en
  mémoire — utilisable dans des `whereHas()` sans piège) ; `valider`
  (valider/renvoyer/rejeter) = le responsable. "Mettre en attente"/"Reprendre"
  sont ouverts aux deux (le spec ne réserve pas cette étape à la seule
  hiérarchie).
- Nouvelle colonne `users.service_id` (nullable) : un Collaborateur (et en
  pratique un Responsable) appartient à un service — nécessaire pour
  restreindre la liste des collaborateurs affectables et la file d'attente.
  Distincte de `services.responsable_id` (qui identifie le responsable d'un
  service) — les deux ne sont jamais interchangées dans le code (bug constaté
  et corrigé pendant l'écriture des tests : `WorkflowQueue` filtrait d'abord
  par erreur sur `user->service_id` pour un responsable).
- Module 6 (affectation) : "le système affecte automatiquement au
  collaborateur le moins chargé" implémenté comme une **présélection**
  (charge affichée par collaborateur, triée croissant) plutôt qu'une
  affectation silencieuse sans confirmation — cohérent avec le principe déjà
  appliqué partout ailleurs (proposition, jamais imposée) et avec la phrase
  suivante du même spec : "un responsable peut toujours affecter
  manuellement en priorité sur la règle automatique". Réaffectation : motif
  obligatoire (validation), nouvelle ligne `affectations` (append-only,
  jamais de update), le statut du circuit ne change pas.
- Page dédiée `courriers/a-traiter` (Module 4 : "le courrier enregistré
  arrive dans la file d'attente du responsable du service concerné") :
  Responsable de service → courriers actifs de son service ; Collaborateur →
  courriers qui lui sont actuellement affectés ; Administrateur → tous les
  courriers actifs ; Agent → 403 (`CourrierPolicy::voirFileAttente`, un Agent
  enregistre des courriers mais ne participe pas au circuit).
Alternatives envisagées : circuit configurable par type de courrier dès la
phase 1 (écarté — explicitement hors scope PRD.md) ; affectation 100 %
automatique sans confirmation du responsable (écarté — contraire au principe
"jamais imposé sans validation" appliqué au Module 3) ; dérogation "sauter une
étape" pour un administrateur (non implémentée — le spec la mentionne
("sauf dérogation explicite tracée") mais elle ajoute une complexité
(qui peut forcer, comment la tracer distinctement) qui n'était pas nécessaire
pour un premier circuit fonctionnel ; à ajouter si un besoin réel se présente
en pilote).

## [2026-09-04] `specifications-modules-GEC.md` ajouté ; éléments confirmés par le client à trancher
Contexte : CONVENTIONS.md et PRD.md renvoient tous deux vers
`specifications-modules-GEC.md` pour le détail des 10 modules, mais ce fichier
n'existait pas dans le dépôt. L'utilisateur en a apporté le contenu (compte
rendu de réunion client), créé tel quel à la racine. Deux faits factuels du
document, sans ambiguïté, sont actés ici ; le reste (liste ci-dessous)
implique soit du code déjà écrit (Module 1-3), soit un module pas encore
commencé (4-10) — **pas appliqué sans confirmation**, conformément à PRD.md
§5 ("toute décision d'architecture reste validée manuellement, pas déléguée à
l'agent").
Décision (faits actés) :
- Volumétrie — **estimation à valider, pas confirmée**, et la piste d'un
  compteur simplement croissant est encore plus affaiblie qu'initialement
  noté. Un premier calcul à partir de deux numéros de tampon donnait
  1789067 le 27/07 → 1789553 le 31/07 (~486 courriers en 4 jours, ~120/jour).
  **[2026-09-04, correction]** En examinant directement une photo du tampon
  portant le numéro 1789553, sa date réelle est **le 21/07**, pas le 31/07
  (confirmée à la fois par le tampon et par la date du courrier lui-même,
  "Mardi le 21 Juillet 2026") — la donnée précédente semble avoir été mal
  transcrite. Avec cette correction, la séquence connue est : 21/07 →
  1789553, 27/07 → 1789067, 14/08 → 1780959 — le numéro **descend** entre le
  21 et le 27 juillet, avant de redescendre encore plus au 14 août. Ce n'est
  donc pas seulement "pas strictement croissant sur la durée", c'est
  décroissant sur la période la mieux documentée. Conclusion inchangée mais
  renforcée : ne présenter aucun chiffre de volumétrie basé sur ce compteur
  en réunion ; question réelle à poser au client : le compteur est-il unique
  pour toute l'entreprise ou propre à chaque machine de scan, et se
  réinitialise-t-il (mensuellement, par service) ? Tant que ce n'est pas
  clarifié, dimensionner le test de charge (Module 10) avec une marge plutôt
  que sur ce chiffre précis.
- Liste réelle des services (tampon papier) : SDG, DAF, DT, DSIN, ACG, DI, DC,
  SANTE, SJ, DCOM, RH, RAG (à confirmer), TRANS, + Secrétariat Général — 14
  services semés le 2026-09-04 via `ServiceSeeder` (`firstOrCreate` par code,
  idempotent : n'écrase jamais un service déjà renommé/configuré en pilote).
  `responsable_id` volontairement laissé vide — à assigner une fois le
  service pilote choisi et un vrai responsable identifié. Intitulés complets
  RAG toujours à confirmer avec le client (laissé tel quel plutôt qu'inventé).
Éléments confirmés par le document — décision prise avec l'utilisateur le
2026-09-04 (voir entrées dédiées ci-dessous pour le détail de chacun) :
1. Module 1 — numéro de tampon : **fait**, à titre indicatif (voir "Numéro de
   tampon (OCR) et détection du cercle manuscrit").
2. Module 2 — détection du cercle manuscrit par vision : **différée**,
   raisons détaillées dans la même entrée.
3. Module 3 — règle de classement par historique d'expéditeur : **fait**
   (voir "Classement automatique (Module 3)").
4. Module 3 — sous-type "Sinistre" (matériel / corporel) dès la phase 1 :
   **fait**, colonne `courriers.sous_type_sinistre`.
5. Module 9 — sous-fonction "décharge" : toujours sans objet, Module 9 pas commencé.
6. Modules 4 à 10 : feuille de route inchangée, rien de nouveau tranché ici.
## [2026-09-04] Stockage hybride : local en priorité + réplication asynchrone vers un secours cloud
Contexte : confirmé explicitement par l'utilisateur — le stockage "local en
priorité + réplication automatique vers un stockage cloud de secours" décrit
dans `specifications-modules-GEC.md` remplace la décision "Hébergement" du
2026-09-03 (qui restait un choix binaire différé, un seul disque `s3`).
Décision : deux disques Laravel, tous deux via le driver `s3` (compatible
S3, jamais `local` — Règle n°4 inchangée sur ce point) :
- `s3` (déjà en place, MinIO en dev) reste le disque **primaire**, celui que
  toute l'application lit (`Storage::disk('s3')->get/download/exists`) — la
  rapidité et l'indépendance réseau du client restent servies exactement
  comme avant.
- `s3_backup` (nouveau, `config/filesystems.php`) reçoit une copie
  asynchrone après chaque écriture primaire, via `ReplicateFichierJob`
  (queue `replication`, Règle n°1, `tries=3`) dispatché depuis
  `PieceJointeService::attacher()` et `ScanForm::numeriser()`. Jamais lu par
  l'application — écriture seule, pure sauvegarde de secours.
- Le job copie en flux (`readStream`/`writeStream`, pas de chargement complet
  en mémoire) et s'auto-désactive silencieusement si `AWS_BACKUP_BUCKET` est
  vide (`.env` en dev sans identifiants cloud réels ; `phpunit.xml` le force
  à vide par défaut pour que les tests ne dépendent jamais d'un vrai
  MinIO/S3 — voir `ReplicateFichierJobTest`) : jamais bloquant, jamais un
  échec d'enregistrement (Règle n°1).
- En dev, `AWS_BACKUP_*` pointe vers un second bucket (`gec-backup`) sur le
  même MinIO local, faute d'un vrai compte cloud à ce stade — un vrai
  fournisseur (AWS S3, ou repli OneDrive/Google Drive mentionné par le
  client) prendra sa place avant le pilote, par simple changement `.env`
  (même principe que la décision "Hébergement" du 2026-09-03).
Alternatives envisagées : répliquer de façon synchrone à l'écriture (écarté —
violerait la Règle n°1, et ferait dépendre l'enregistrement d'un courrier de
la disponibilité du cloud, contraire à "indépendance vis-à-vis d'internet") ;
un seul disque avec versioning côté fournisseur cloud (écarté — ne couvre pas
le cas "panne du serveur local", qui est le risque que le client a nommé).

## [2026-09-04] Numéro de tampon (OCR) et détection du cercle manuscrit (vision) : l'un fait, l'autre différé
Contexte : `specifications-modules-GEC.md` (Module 1/2) demande deux
extractions automatiques supplémentaires depuis le tampon d'entrée existant
chez le client — un numéro par OCR/regex, et un sigle de service entouré à la
main par détection de contours/ellipse (vision par ordinateur).
Décision :
- **Numéro de tampon** : implémenté en best-effort. `ProcessDocumentOcr::extraireNumeroTampon()`
  cherche un motif dans le texte OCR et stocke le résultat brut dans
  `courriers.numero_tampon_detecte` — **à titre indicatif uniquement**,
  affiché sur la fiche à côté du numéro officiel s'ils diffèrent, et utilisé
  pour pré-remplir `date_mouvement` à l'ouverture du formulaire (flux
  scan-first). N'alimente jamais `numero_reference`, qui reste exclusivement
  généré par `NumeroReferenceGenerator` (séquence verrouillée, Règle n°3) :
  aucun champ du formulaire n'expose `numero_reference` en écriture, donc
  rien à corrompre même si l'extraction se trompe.
  **[2026-09-04, mise à jour]** Format confirmé sur deux vrais tampons
  photographiés par l'utilisateur : `NSIA ASSURANCES {jour} {mois abrégé fr}
  '{année sur 2 chiffres} {heure}:{minute}:{seconde}-{numéro}`, ex.
  `NSIA ASSURANCES 21 JUIL '26 10:26:48-1789553`. Le mois est en toutes
  lettres (abrégé, 3 à 5 caractères — "JUIL" et "JUL" observés selon les
  documents), pas numérique comme initialement deviné ; l'heure porte les
  secondes. Regex et `RegistrationForm::dateDepuisTampon()` corrigés en
  conséquence (table de correspondance des mois abrégés — "JUIN"/"JUIL" ne
  peuvent pas être distingués sur leurs 3 premières lettres seules, gardées
  en entier).
  **Problème séparé, non résolu par cette correction** : sur le document
  fourni en test (photo de téléphone, encre du tampon rose pâle), Tesseract
  n'a lu **aucun caractère du tampon lui-même** (aucune trace de la date, de
  l'heure, du numéro ni des sigles de service dans le texte OCR — seule la
  mention "NSIA ASSURANCE" de l'adresse du courrier, en encre noire normale,
  a été reconnue). Le motif corrigé ne sert donc à rien tant que l'OCR ne
  transcrit pas le tampon — même limite déjà documentée pour la détection du
  cercle manuscrit ("encre rouge pâle, qualité photo téléphone"). Piste non
  engagée : prétraitement d'image ciblé (isolation du canal de couleur,
  contraste) avant l'OCR — expérimental, à cadrer séparément si jugé utile ;
  le test le plus décisif reste un vrai scan au scanner à plat plutôt qu'une
  photo.
- **Détection du cercle manuscrit (vision)** : **différée**, pas construite.
  Trois obstacles concrets, chacun rédhibitoire seul :
  1. Aucune image réelle de tampon disponible dans cette session pour
     développer/valider un détecteur — la décision "Détection du service"
     déjà présente dans ce journal (test sur un document client) le
     signalait déjà comme "à revalider avec un vrai scan avant engagement
     ferme", jamais fait depuis.
  2. Pas de bibliothèque de vision par ordinateur dans la stack actuelle :
     PHP (GD/Imagick) ne fait pas de détection de contours/ellipse robuste ;
     OpenCV n'a pas de binding PHP mûr — nécessiterait soit un microservice
     Python séparé (nouveau composant à déployer/exploiter), soit une API
     cloud (contraire au choix "hors-ligne" déjà tranché pour l'OCR).
  3. C'est un second traitement de vision indépendant de l'OCR (spec Module 2,
     point 2) : un vrai développement, pas un enrichissement d'une fonction
     existante — mérite son propre cadrage avant d'être commencé.
  Le repli déjà en place (classement automatique du texte, Module 3) reste le
  seul mécanisme de pré-proposition du service tant que ce point n'est pas
  repris.
Alternatives envisagées pour le numéro de tampon : ne rien extraire tant
qu'un exemple réel n'est pas fourni (écarté — la valeur indicative, non
bloquante et non autoritaire, ne présente aucun risque à livrer maintenant) ;
faire du numéro détecté la référence officielle du courrier (écarté — viole
la garantie d'unicité en base de la Règle n°3, un OCR peut se tromper).

## [2026-09-04] Destinataire proposé automatiquement (Module 1)
Contexte : la précédente décision documentée dans `RegistrationForm::preremplirDepuisBrouillon()`
écartait volontairement toute tentative d'extraction de l'expéditeur, faute de
convention fixe repérable de façon fiable dans un courrier quelconque — le
même raisonnement s'appliquait a priori au destinataire. L'utilisateur a
explicitement demandé, le 2026-09-04, d'étendre le pré-remplissage automatique
au champ destinataire malgré ce risque, en connaissance de cause (choix
confirmé face à la reformulation du risque de faux positifs).
Décision : `ProcessDocumentOcr::extraireDestinataire()` tente deux motifs, par
ordre de fiabilité décroissante : (1) marqueur explicite "à l'attention de…"
(accent optionnel, souvent perdu par l'OCR) ; (2) à défaut, une ligne de
civilité suivie d'un titre ("Monsieur/Madame Le/La/Les …", convention observée
sur les vrais courriers adressés à Nsia, ex. "Monsieur Le Directeur Général de
NSIA Assurances"). Le "Le/La/Les" obligatoire après la civilité évite de
confondre avec la simple formule de politesse ("Monsieur," seule, sans titre
après). Comme pour l'objet/service/type, jamais imposé : purement une
proposition, avec le même bandeau "à vérifier" (`champsProposesAutomatiquement`)
dans la vue. Best-effort, jamais de valeur inventée — motif 2 non testé sur un
échantillon large de courriers réels, risque de faux positif accepté par
l'utilisateur plutôt qu'écarté par principe.
Alternatives envisagées : ne rien tenter, comme pour l'expéditeur (écarté —
demande explicite de l'utilisateur, qui a évalué le risque et l'a accepté) ;
un seul motif (civilité + titre) sans le marqueur explicite "à l'attention de"
(écarté — ce marqueur est strictement plus fiable quand il est présent, aucune
raison de ne pas le tenter en premier).

## [2026-09-04] Expéditeur (organisation) proposé automatiquement + date de repli sans tampon (Module 1)
Contexte : suite à la décision précédente ("Destinataire proposé automatiquement"),
l'utilisateur a demandé explicitement d'aller plus loin — construire les
fonctions d'extraction manquantes pour les champs encore saisis à la main
(mode_reception, expéditeur), et a signalé que le tampon d'entrée n'est pas
détecté sur son document de test (ce qui bloque le pré-remplissage de la
date sur ce document précis).
Décision (expéditeur — organisation uniquement) : `ProcessDocumentOcr::
extraireExpediteurOrganisation()` tente, par ordre de fiabilité décroissante,
(1) un marqueur explicite "Expéditeur :" ; (2) une forme juridique en
suffixe courante en Afrique francophone (Sarl, SARL, SUARL, SA, S.A., GIE —
le nom propre précède, ex. "ITSC Sarl") ; (3) une forme juridique en
préfixe (Ets/Établissements, Cabinet — le nom propre suit, ex. "Cabinet
Ndiaye"). Volontairement limité à l'organisation, PAS au nom d'une personne
physique (`expediteur_nom` reste toujours à saisir à la main) : contrairement
à une forme juridique, il n'existe aucun marqueur orthographique fiable pour
reconnaître le nom d'une personne dans un texte quelconque — même
raisonnement qui avait initialement exclu tout l'expéditeur. Risque assumé et
documenté dans le code : un tiers cité dans le corps d'un courrier (ex. "notre
assureur XYZ SA") peut être confondu avec l'expéditeur réel — la majorité des
courriers réels attendus (réclamations de particuliers) n'ont de toute façon
aucune forme juridique à détecter, donc cette fonction n'aide principalement
que sur le courrier entreprise-à-entreprise, jamais sur le courrier d'un
assuré personne physique. Toujours une proposition, jamais imposée (bandeau
"à vérifier").
Décision (date — repli sans tampon) : `RegistrationForm::
dateDepuisTexteCourrier()` reparse la date écrite par l'expéditeur en tête du
courrier ("Mardi le 21 Juillet 2026", "Yaoundé, le 07 Janvier 2026"), tentée
uniquement si `dateDepuisTampon()` n'a rien donné (le tampon reste prioritaire
— plus fiable, c'est la date réelle de dépôt/réception chez Nsia, alors que la
date du courrier est celle de sa rédaction/envoi, potentiellement différente
de quelques jours). Contrairement à la date du tampon (posée sans
avertissement, jugée assez fiable), cette date de repli déclenche un
indicateur dédié (`dateProposeeAutomatiquement`, séparé de
`champsProposesAutomatiquement`) et un bandeau "à vérifier" explicite. Ce
n'est PAS une simple réutilisation de l'indicateur partagé existant :
partager l'indicateur global aurait reproduit exactement la classe de bug
déjà corrigée le 2026-09-04 pour service/type (un indicateur affiché à tort
sous un champ qui n'a pourtant reçu aucune proposition, simplement parce
qu'un AUTRE champ, lui, en a reçu une) — ici, la Date pourrait provenir du
tampon (fiable) pendant qu'objet/destinataire/organisation sont proposés
séparément (indicateur global activé) : sans indicateur dédié, "à vérifier"
s'afficherait à tort sous une date pourtant fiable. Couverture ajoutée à la
table des mois (`MOIS_FRANCAIS`, ex-`MOIS_ABREGES`, renommée car elle contient
désormais aussi les noms de mois en toutes lettres, pas seulement les
abréviations du tampon) plutôt qu'une table séparée — même besoin
(normalisation accents/casse d'un nom de mois français), pas de raison de
dupliquer.
Décision (mode de réception) : **non implémenté**, volontairement — aucun
marqueur textuel ne permet de déduire de façon fiable comment un courrier est
arrivé (dépôt physique, poste, fax, email) à partir de son seul contenu
scanné ; construire une fonction qui devine quand même violerait le principe
"jamais une valeur inventée" appliqué à toutes les autres extractions de ce
fichier. Champ laissé à la valeur par défaut du formulaire (`depot_physique`,
CourrierForm), toujours à confirmer par l'agent.
Alternatives envisagées pour l'expéditeur : tenter aussi le nom d'une personne
physique via une civilité en fin de lettre (signature) — écarté, bien plus
risqué que la civilité du destinataire (qui bénéficie d'un motif de repli
"à l'attention de" quasi jamais ambigu ; une signature manuscrite/dactylographiée
n'a pas d'équivalent fiable). Pour la date : dériver `depot_physique` par
défaut du champ mode_reception quand un tampon est détecté (le tampon
suggère un dépôt physique) — écarté pour l'instant, n'aurait changé aucun
comportement observable (c'est déjà la valeur par défaut du formulaire) tout
en ajoutant de la complexité pour un signal qui ne distingue de toute façon
pas `depot_physique` de `poste`.

## [2026-09-04] Revue adversariale des extractions expéditeur/destinataire — correctifs et limites acceptées
Contexte : les deux décisions ci-dessus ("Destinataire proposé automatiquement",
"Expéditeur (organisation) proposé automatiquement…") ont été soumises à une
revue adversariale à 3 angles (risque de faux positifs sur des courriers
réels plausibles construits et exécutés contre le vrai code — pas de simple
relecture ; absence de régression sur l'indicateur "à vérifier" ; cohérence
avec CONVENTIONS.md/DECISIONS.md). En parallèle, l'utilisateur a lui-même repéré un
premier bug réel sur son document de test (voir entrée "Correctif : fragment
OCR isolé recollé au nom de l'organisation à tort").
Décision — bugs corrigés (comportement contredisant ce que la documentation
précédente prétendait déjà couvrir, pas de nouvelle tolérance au risque) :
1. **`S.A.` (avec points) ne matchait jamais en pratique** : la frontière de
   fin `\b` échoue juste après un point (caractère non-mot), donc le suffixe
   listé comme supporté était du code mort dans un texte réel. Corrigé par un
   lookahead "pas suivi d'une lettre" à la place de `\b`.
2. **Auto-référence à Nsia** : "NSIA Assurances SA" apparaît dans la quasi-
   totalité des réclamations clients ("bien assuré auprès de NSIA
   Assurances SA depuis…") — sans filtre, c'était le cas le PLUS fréquent en
   pratique, pas un tiers occasionnel comme documenté à l'origine. Nsia ne
   s'envoie jamais de courrier à elle-même : toute correspondance dont le
   nom contient "NSIA" (normalisé) est désormais écartée, en cherchant la
   première correspondance suivante qui n'est pas Nsia plutôt qu'en
   abandonnant purement (`preg_match_all` + filtre, pas juste `preg_match`).
3. **Connecteurs minuscules ("de", "du", "des", "d'", "l'") non tolérés** à
   l'intérieur d'un nom d'organisation : un nom comme "Cabinet d'Expertise
   Immobilière du Centre Sarl" n'était capturé qu'à partir de "Centre Sarl".
   Motif suffixe/préfixe étendu pour accepter ces connecteurs entre mots
   capitalisés.
4. **"Cabinet" en préfixe défait par l'élision** : "Cabinet d'Avocats…",
   "Cabinet d'Expertise…" (constructions très courantes pour un cabinet
   professionnel, plus courantes qu'un nom propre nu après "Cabinet") ne
   matchaient jamais, faute d'un mot capitalisé immédiatement après le mot
   "Cabinet". Corrigé par le même assouplissement que le point 3.
5. **"à l'attention de" en milieu de phrase sur-capturait** toute la fin de
   la ligne (ex. "…à l'attention de Madame la Chargée du dossier, comme
   convenu lors de notre entretien…" capturait toute la proposition
   subordonnée). Corrigé en arrêtant la capture à la première virgule ou au
   premier point plutôt qu'à la fin de ligne.
6. **Indicateurs "à vérifier" jamais réinitialisés après un enregistrement
   réussi** (`champsProposesAutomatiquement`/`dateProposeeAutomatiquement`) :
   le composant reste monté après la sauvegarde (l'agent peut enchaîner un
   autre courrier via `brouillonsEnAttente`, flux batch confirmé par le
   client) — sans reset, les bandeaux "à vérifier" d'un brouillon précédent
   restaient affichés sous des valeurs saisies entièrement à la main pour le
   courrier suivant. Ajoutés à `$this->reset(...)` dans `enregistrer()`.
Décision — limites identifiées mais **volontairement non corrigées**, à
trancher plus tard si jugé nécessaire (pas des bugs contredisant une
promesse déjà faite, mais une extension de périmètre) :
- **Formes juridiques anglophones (PLC, Ltd)**, courantes dans les régions
  anglophones du Cameroun (Nord-Ouest/Sud-Ouest) — la décision d'origine
  cadrait explicitement "Afrique francophone" ; les ajouter est une décision
  de périmètre, pas un correctif.
- **Courrier d'une administration/régulateur** (ministère, ARSA/CIMA) sans
  forme juridique classique — comportement sûr (aucune proposition), juste
  une catégorie de courrier pour laquelle la fonction n'aide jamais.
- **Civilité + titre visant un tiers cité dans le récit** (ex. "Monsieur Le
  Chef d'agence de la compagnie adverse" — un rival croisé sur les lieux
  d'un accident, pas le destinataire réel) : instance concrète du risque déjà
  documenté et accepté pour `extraireDestinataire()`, pas une catégorie
  nouvelle — non traité pour éviter d'ajouter des motifs d'exclusion au cas
  par cas (jeu du chat et de la souris avec un nombre non borné de formulations).
- **Un champ édité par l'agent après une proposition automatique garde son
  bandeau "à vérifier"** (le garde-fou est "indicateur + champ non vide", pas
  "champ encore égal à la valeur proposée") : comportement déjà présent
  depuis la toute première version de cet indicateur (pas introduit par les
  correctifs ci-dessus), accepté tel quel — le stocker/comparer par champ
  ajouterait une complexité disproportionnée pour un faux "à vérifier"
  résiduel, jamais une fausse absence d'avertissement.
Vérifié : chaque scénario de faux positif/négatif relevé par la revue a été
rejoué contre le vrai code (pas seulement tracé mentalement) avant d'écrire
les tests de non-régression correspondants ; pint propre, 176/176 tests.

## [2026-09-04] Formes juridiques anglophones + motif de repli "bloc de coordonnées/Direction Générale"
Contexte : suite logique de l'entrée ci-dessus — l'utilisateur a demandé
explicitement de traiter deux des limites listées comme "volontairement non
corrigées" (formes juridiques anglophones, administration/organisation sans
forme juridique), en signalant que le nom d'une organisation ET ses
coordonnées sont très souvent mentionnés ensemble en en-tête ou en pied de
page, ou près d'une mention "La Direction"/"Direction Générale" en signature
— convention déjà visible sur le vrai document ITSC Sarl/NSIA ("Contacts :
... BP 2138 Yaoundé Cameroun", juste après les identifiants légaux).
Décision (formes anglophones) : `PLC`, `Ltd`, `Co. Ltd`, `Limited` ajoutés à
la liste des formes juridiques en suffixe, au même titre que Sarl/SA/GIE —
aucun changement de mécanisme, juste une extension de la liste de mots-clés.
Décision (motif de repli "bloc de coordonnées") : nouveau quatrième motif,
le moins fiable des quatre (tenté en dernier), dans
`organisationPresDunBlocCoordonnees()` : repère une ligne contenant un
identifiant légal/de contact (N° RC, RCCM, Contribuable, NIU, boîte postale,
téléphone, "Contacts :") ou une mention "La Direction (Générale)", puis
regarde cette ligne elle-même, la précédente, puis la suivante, pour une
candidate qui **ressemble à un nom d'organisation** : restreint aux lignes
ENTIÈREMENT EN MAJUSCULES ou contenant déjà une forme juridique connue —
jamais une ligne en casse normale. Ce garde-fou est déterminant : un assuré
qui donne son propre numéro de téléphone signe en casse normale ("Jean
Dupont, Tél : …", "Monsieur EYENGA Paul, Tél : …"), jamais tout en
majuscules — sans lui, ce motif aurait proposé le nom du client lui-même
comme "organisation expéditrice" à chaque réclamation mentionnant un numéro
de contact personnel. Filtre auto-référence à Nsia (voir entrée précédente)
réappliqué ici aussi.
Décision (administration/régulateur — limite maintenue) : toujours **non**
détectée si le texte ne contient ni forme juridique ni bloc de coordonnées
identifiable (aucun test disponible sur un vrai courrier administratif reçu
par Nsia) — deviner à partir de mots-clés institutionnels non vérifiés
("RÉPUBLIQUE DU", "MINISTÈRE"…) aurait été une supposition non fondée sur un
document réel, contraire au principe "jamais une valeur inventée" ; si un tel
courrier comporte malgré tout un bloc de coordonnées (probable pour une
administration), le nouveau motif de repli le détectera déjà.
Alternatives envisagées : détecter un bloc d'en-tête institutionnel
(mots-clés "RÉPUBLIQUE DU", "MINISTÈRE", "AUTORITÉ DE") indépendamment de
tout bloc de coordonnées — écarté, surajustement à un exemple construit par
l'agent de revue plutôt qu'un vrai document, aucune confirmation que les
courriers administratifs réellement reçus par Nsia suivent cette convention
précise.
Vérifié : chaque scénario (PLC/Ltd/Co. Ltd, nom sans forme juridique près
d'un bloc BP/Tél, nom près de "La Direction Générale", assuré signant avec
son numéro personnel — non confondu, administration sans bloc de
coordonnées — toujours non détectée) rejoué contre le vrai code, extraction
"ITSC Sarl" reconfirmée inchangée sur le vrai document ; pint propre,
181/181 tests.

## [2026-09-04] Destinataire "A"/"À" isolé, insensibilité à la casse, coordonnées de l'expéditeur, aperçu du document sans téléchargement
Contexte : l'utilisateur a fourni un deuxième vrai document (PDF scanné,
"FORMAVISION.COM" — publicité déjà croisée en photo le 2026-09-04, voir
entrée "Format réel du tampon corrigé…", cette fois en PDF net) et signalé
trois choses concrètes : (1) sur son dernier essai, "ITSC Sarl" s'est bien
proposé en Organisation, mais Nom et Coordonnées restent toujours vides ;
(2) ce nouveau document n'a pas de "Objet :" et le destinataire est introduit
par un simple "A" isolé en tête de bloc adresse, pas par "à l'attention de" ;
(3) certains documents écrivent "À Monsieur…" directement, sans "l'attention
de". Il a aussi demandé une prévisualisation du document dans le site, sans
téléchargement.
Décision (bloc "A"/"À" isolé) : nouveau motif dans `extraireDestinataire()`,
`destinataireApresMarqueurA()` — une ligne qui est EXACTEMENT "A" ou "À"
déclenche la capture des lignes suivantes (jusqu'à 4, jusqu'à la première
ligne vide), à condition que la toute première ligne suivante commence par
une civilité (Madame/Monsieur) — sans ce garde-fou, un "A" isolé serait trop
souvent un artefact OCR plutôt qu'un vrai marqueur d'adresse. Couvre aussi
"A"/"À" suivi directement de la civilité sur la même ligne ("À Monsieur le
Directeur…"). Motif tenté après "à l'attention de" (le plus fiable) mais
avant la civilité seule sur une ligne (le moins fiable).
Décision (casse) : le motif de repli "civilité + titre" (`Madame|Monsieur
Le|La|Les …`) accepte désormais aussi la casse minuscule ("Monsieur le
Directeur Général") — le vrai document confirme que c'est au moins aussi
fréquent que la majuscule ; rien ne justifiait de l'exiger.
Décision (coordonnées expéditeur) : nouvelle fonction
`extraireExpediteurCoordonnees()`, même famille que les autres marqueurs
explicites ("Contacts :", "Tél :") — capture le reste de la ligne après le
marqueur. Toujours pas de tentative sur `expediteur_nom` (la personne) : ni
ce document ni aucun des précédents ne comporte de nom de signataire
individuel repérable par une convention fixe — le raisonnement d'exclusion
déjà documenté pour ce champ reste inchangé, pas juste non traité par oubli.
Décision (aperçu du document) : nouveau contrôleur
`CourrierDocumentApercuController` (route `courriers.document.apercu`),
sert le fichier avec `Storage::disk('s3')->response()` (Content-Disposition
`inline`) au lieu de `->download()` (`attachment`) — même droits que la
consultation du courrier (Règle n°6). Affiché dans un `<flux:modal>` avec un
`<iframe>` pointant vers cette route plutôt qu'un nouvel onglet : reste
"sur le site" comme demandé, sans ajouter de propriété Livewire (le modal
Flux se pilote entièrement côté Alpine via `flux:modal.trigger`/`name`,
conforme à la Règle n°2 — pas de nouvel état serveur nécessaire). Le lien
"Télécharger" existant est conservé tel quel à côté du nouveau "Voir le
document".
Alternatives envisagées : ouvrir l'aperçu dans un nouvel onglet plutôt qu'un
modal — écarté, la demande explicite était de rester "sur le site" ; générer
une prévisualisation image côté serveur (miniature) — écarté, complexité
disproportionnée (dépendance de conversion PDF→image supplémentaire) pour un
gain marginal vu que le navigateur affiche déjà nativement PDF/JPG/PNG.
Vérifié : "Monsieur le Directeur Général\nNSIA ASSURANCE\nYaoundé" extrait
correctement du bloc "A" du document FORMAVISION ; "À Monsieur…"/"A
Monsieur…" sur la même ligne également ; absence d'"Objet :" sur ce document
confirmée comme n'inventant aucune proposition (comportement attendu, pas un
bug) ; garde-fou "A isolé sans civilité après" vérifié ; pint propre,
193/193 tests. Aperçu testé via requête HTTP directe (`assertOk()` +
en-tête `Content-Disposition: inline`) et droits refusés à un agent tiers ;
non testé visuellement dans un navigateur réel (aucun outil de test
navigateur disponible dans cette session).

## [2026-09-04] Organisation expéditrice : fenêtre de recherche élargie, marqueurs RC/NIU réparés, limite structurelle assumée
Contexte : sur le même document FORMAVISION.COM, l'utilisateur a signalé que
le champ Organisation restait vide malgré une organisation bien présente
dans le texte, et a fait une observation juste : le nom d'une organisation
n'a pas toujours la même taille, couleur ou style d'un document à l'autre.
Décision (fenêtre élargie) : `organisationPresDunBlocCoordonnees()` cherchait
seulement la ligne immédiatement avant/après un marqueur (±1) ; sur ce
document, le nom ("FORMAVISION.COM") est séparé du bloc téléphone/RC par un
slogan et une ligne d'adresse — élargi à 3 lignes dans chaque direction,
sans jamais franchir une ligne vide (un bloc d'en-tête/pied de page reste
compact). Vérifié utile sur un cas sans ambiguïté (nom à 3 lignes du
marqueur, lignes intermédiaires en casse normale — aucun faux candidat sur
le chemin).
Décision (marqueurs RC/NIU réparés) : deux bugs trouvés en écrivant les
tests de cette même entrée — "N°?" ne rendait optionnel QUE le symbole
degré, pas tout le préfixe "N°" (donc "RC/YAO/…" sans "N°" devant, très
courant, ne déclenchait jamais ce motif — y compris sur le document ITSC
Sarl déjà traité par le motif suffixe, donc jamais remarqué) ; corrigé en
rendant tout le groupe "N°\s*" optionnel. "N.I.U." écrit avec des points
entre chaque lettre (vu sur FORMAVISION.COM) ne matchait pas "NIU" collé ;
motif assoupli pour tolérer les points.
Constat honnête (limite structurelle, non résolue) : sur ce document précis,
même avec les deux correctifs ci-dessus, le résultat le plus probable reste
soit rien, soit une proposition FAUSSE ("RAPIDITE EFFICACITE PRODUCTIVITE",
un slogan publicitaire en majuscules) plutôt que "FORMAVISION.COM" —
vérifié en reproduisant le texte du document sans ligne vide entre les
blocs de l'en-tête (le slogan/les badges se trouvent alors plus près du
numéro de téléphone que le nom lui-même). Cause : l'OCR ne conserve ni la
taille, ni la couleur, ni la graisse du texte d'origine — un humain repère
"FORMAVISION.COM" instantanément parce qu'il est écrit en gros en haut,
mais le texte brut qui arrive au code place "RAPIDITE EFFICACITE
PRODUCTIVITE" tout aussi près, tout aussi capitalisé, sans aucun signal
distinctif restant pour les départager. Non traité par des mots-clés
anti-slogan ("RAPIDITE", "EFFICACITE"…) : même piège de surajustement déjà
écarté pour la détection d'administrations — un slogan publicitaire peut
dire absolument n'importe quoi. Accepté comme limite fondamentale de
l'approche texte seul, pas comme un bug à corriger : la proposition reste
toujours "à vérifier", jamais imposée, exactement pour ce genre de cas —
même raisonnement déjà appliqué au nom d'une personne physique
(`expediteur_nom`), étendu ici à un sous-cas de l'organisation elle-même.
Alternatives envisagées : liste de mots-clés à exclure (slogans publicitaires
courants) — écarté, surajustement sans fin face à des formulations non
bornées ; privilégier systématiquement la toute première ligne du document
plutôt que la plus proche du marqueur — écarté, réintroduirait le risque
déjà rencontré et corrigé le 2026-09-04 (bruit OCR en tout début de document,
"URITE").
Vérifié : nouveaux cas (nom à 3 lignes d'un marqueur sans ligne parasite,
"RC" sans "N°", "N.I.U." avec points) rejoués contre le vrai code, toutes
les non-régressions précédentes (ITSC Sarl, TRANSCAM VOYAGES, garde-fous
contre les signatures personnelles, administration sans bloc de
coordonnées) toujours correctes ; pint propre, 196/196 tests. Le texte du
document FORMAVISION.COM utilisé pour ce constat est une reconstitution
manuelle (lecture visuelle du PDF, pas un vrai passage par Tesseract) — la
répartition réelle des sauts de ligne OCR n'est pas garantie identique ; à
confirmer en scannant réellement ce document dans l'outil.

## [2026-09-04] Objet proposé sans mention "Objet :" (repli sur le paragraphe avant la formule d'appel) + aperçu du document agrandi
Contexte : l'utilisateur a réellement scanné le document FORMAVISION.COM
dans l'outil (brouillon id 7, capture d'écran fournie) — confirmant en
conditions réelles que Date, Type de document, Coordonnées et Destinataire
se proposent tous correctement, mais Objet reste vide, puisque ce document
n'a pas le mot "Objet" (il a "Solutions innovantes : ..." au même endroit
structurel). Il a aussi signalé que le modal d'aperçu du document (ajouté
plus tôt le 2026-09-04) est trop petit pour lire confortablement une page.
Décision (objet) : nouveau repli `objetPresDeLaFormuleDappel()`, tenté
uniquement si "Objet :" est absent — cherche un "Libellé : texte" dans le
SEUL paragraphe qui précède immédiatement la formule d'appel ("Monsieur,"
/ "Madame le Directeur Général,"…, détectée par une ligne qui commence par
la civilité et se termine par une virgule). Restreint à ce paragraphe
précis plutôt qu'à tout le document : les nombreux "Libellé :" d'un
en-tête/pied de page (Tél :, Contacts :, N° RC :…) ne s'y trouvent
structurellement jamais, donc pas besoin d'une liste d'exclusion — piège
déjà écarté pour l'organisation expéditrice, même raisonnement ici. Bug réel
trouvé en écrivant les tests de cette même entrée : une première version
remontait jusqu'à 6 lignes en arrière (en sautant les lignes vides sans
limite de paragraphes traversés), et confondait à tort un "Contacts :"
d'en-tête, deux paragraphes plus haut, avec l'objet — corrigé en bornant
strictement au paragraphe immédiatement précédent (jusqu'à la ligne vide
la plus proche dans chaque sens, jamais au-delà).
Décision (aperçu agrandi) : le modal (`resources/views/livewire/frontend/
showCourrier.blade.php`) passe de `max-w-4xl` à `w-[95vw]! max-w-[95vw]!`
(quasi pleine largeur d'écran) et l'iframe de `h-[75vh]` à `h-[90vh]`, pour
se rapprocher d'une page de document lisible plutôt qu'une vignette.
Vérifié : "Optimisez votre bureau avec nos fournitures connectées et
systèmes de" extrait correctement depuis "Solutions innovantes : ..." sur
le texte du document FORMAVISION.COM ; "Objet :" reste prioritaire quand
présent (non-régression) ; le cas de confusion avec un "Contacts :" éloigné
explicitement rejoué et confirmé corrigé ; pint propre, 198/198 tests.
Aperçu agrandi non vérifié visuellement dans un navigateur réel (toujours
aucun outil de test navigateur disponible dans cette session) — à confirmer
par l'utilisateur.

## [2026-09-04] Piège opérationnel : un changement de classes Tailwind dans une vue ne s'applique pas sans reconstruire les assets
Contexte : l'utilisateur a signalé, capture d'écran à l'appui, que le modal
d'aperçu restait minuscule malgré l'agrandissement (`w-[95vw]! max-w-[95vw]!`,
`h-[90vh]`) déjà appliqué dans le Blade quelques échanges plus tôt.
Diagnostic : `public/build/` (assets compilés servis par l'application,
`@vite` en mode manifeste plutôt que serveur de développement) datait du
2026-09-03 17:40 — antérieur à TOUS les changements Blade de cette session,
y compris le premier agrandissement du modal. Tailwind (mode JIT) ne génère
que les classes utilitaires réellement scannées dans les fichiers au moment
de la compilation ; une classe ajoutée dans un `.blade.php` après coup
n'existe tout simplement pas dans le CSS déjà construit tant que
`npm run build` (ou `npm run dev` en watch) n'a pas tourné — aucune erreur
visible, juste aucun effet, ce qui rend le symptôme trompeur (on croit le
correctif inefficace alors qu'il n'a jamais été livré au navigateur).
Décision : `npm run build` exécuté après ce changement de classes (et
désormais après tout changement Blade touchant des classes Tailwind non
déjà utilisées ailleurs dans le projet, tant qu'aucun `npm run dev` n'est
confirmé actif en arrière-plan) ; vérifié en cherchant les nouvelles classes
directement dans le CSS compilé (`Select-String` sur `public/build/assets/
app-*.css`) avant de considérer le changement livré, pas seulement en
relisant le Blade.
Alternatives envisagées : demander à l'utilisateur de lancer `npm run dev`
en permanence pendant les sessions de travail — plus pratique à terme
(rebuild automatique à chaque sauvegarde) mais nécessite un processus
persistant en arrière-plan que cette session ne peut pas garantir démarré ;
non tranché, à sa discrétion.
Vérifié : classes `98vw`/`95vh` (agrandissement suivant, même échange)
confirmées présentes dans le CSS compilé après `npm run build` ; suite de
tests ShowCourrier toujours au vert (7/7). Rendu visuel réel non confirmé
dans un navigateur — aucun outil de test navigateur disponible dans cette
session, à valider par l'utilisateur après rechargement de la page.

## [2026-09-04] Nom de l'expéditeur ("Je soussigné(e)"/"Signé :") et mode de réception (email/fax) proposés automatiquement
Contexte : après le point sur les modules 1/2/3 restants, l'utilisateur a
choisi de traiter les points 1 ("Superviseur" — laissé ouvert, pas
prioritaire), 2 (numéro de référence extrait du tampon — refusé, la règle
actuelle est gardée) et 4 (Nom de l'expéditeur + Mode de réception, jamais
proposés jusqu'ici) du Module 1, plus le point ouvert du Module 3 (voir
entrée suivante pour ce dernier).
Décision (Nom) : `ProcessDocumentOcr::extraireExpediteurNom()`, sur le même
principe que les autres champs — deux marqueurs explicites, jamais une
position/civilité seule. "Je soussigné(e) [Nom]," est une convention
administrative française bien établie, utilisée précisément par un
déclarant individuel (ex. un assuré rédigeant une réclamation) pour
s'identifier sans ambiguïté — contrairement au reste du texte d'un courrier
quelconque, où aucune convention fixe ne repère un nom de personne (raison
initiale de l'exclusion totale de ce champ). Complété par "Signé :"/
"Signature :" en fin de courrier. Une simple civilité en signature (ex.
"Monsieur EYENGA Paul" seule) reste volontairement hors périmètre — bien
plus ambiguë, aucun marqueur qui la distingue d'une civilité citée ailleurs
dans le texte.
Décision (Mode de réception) : `ProcessDocumentOcr::extraireModeReception()`
— contrairement à "dépôt physique"/"poste" (aucune trace distinctive
possible, limite déjà documentée et assumée), un email imprimé/transféré ou
un fax transmis laissent souvent une trace textuelle propre à leur canal :
"De :"/"From :" DIRECTEMENT suivi d'une adresse mail → 'email' (une simple
adresse mail citée en signature ne suffit pas, trop faible) ; un bandeau de
télécopie (mot "FAX"/"TÉLÉCOPIE"/"TX"/"RX" ET un compte de page "NNN/NNN"
ensemble, pas l'un sans l'autre) → 'fax' (un simple "Tél/Fax :" de contact
dans un en-tête ne suffit pas seul — juste un moyen de contact parmi
d'autres, pas une preuve du canal réel de CE courrier). Sans signal reconnu,
le champ garde sa valeur par défaut du formulaire (`depot_physique`),
comme n'importe quel autre champ non proposé.
Décision (indicateur dédié) : nouvelle propriété
`$modeReceptionProposeAutomatiquement`, sur le même principe que
`$dateProposeeAutomatiquement` — `mode_reception` a toujours une valeur par
défaut dans `CourrierForm` (jamais vide), donc le garde-fou "indicateur
partagé + champ non vide" utilisé par les autres champs ne peut jamais
distinguer "non proposé" de "proposé" pour ce champ précis ; sans
indicateur dédié, "à vérifier" se serait affiché en permanence sous Mode de
réception dès qu'un AUTRE champ est proposé — même classe de bug déjà
corrigée deux fois ce jour pour service/type puis pour la Date. Ajoutée au
reset de `enregistrer()` au même titre que les deux indicateurs existants.
`expediteur_nom`, lui, démarre à `null` dans `CourrierForm` — le garde-fou
standard "indicateur partagé + champ non vide" suffit, pas besoin
d'indicateur dédié.
Vérifié : chaque scénario (soussigné, signé/signature, civilité seule
exclue, email via "De :", fax via bandeau+pages, simples mentions de
contact insuffisantes seules) rejoué contre le vrai code ; test dédié
prouvant que "à vérifier" n'apparaît qu'une seule fois quand seul l'objet
est proposé (non-régression explicite sur le garde-fou de Mode de
réception) ; pint propre, 208/208 tests.

## [2026-09-04] Recherche multi-critères (comble le point ouvert du Module 3, amorce du Module 8)
Contexte : suite du point de situation sur les modules 1/2/3 — le Module 3
n'était pas totalement fermé, sa dernière exigence ("classement... retrouvable
via plusieurs critères (métadonnées)") dépendant du Module 8 (recherche),
jamais commencé : `CourrierList.php` n'était qu'un squelette vide, sans
requête, sans filtre, sans route.
Décision : nouveau composant `CourrierList` (route `courriers/rechercher`,
lien de menu "Rechercher un courrier"), filtres sur numéro de référence,
objet, expéditeur (nom OU organisation), plage de dates (date_mouvement),
service, statut, sens — exactement les critères listés par Module 8 point 1
("recherche simple par numéro, objet, expéditeur ou date") + les métadonnées
citées par Module 3, PAS la recherche plein-texte dans le contenu scanné
(Module 8 point 3, volontairement laissée de côté — un chantier à part,
distinct de fermer le point du Module 3). Résultats systématiquement
filtrés selon le périmètre de l'utilisateur, même logique que
`CourrierPolicy::view()` (Administrateur : tout ; Responsable de service :
son service ; Agent : ce qu'il a lui-même enregistré, via l'historique
Règle n°5 — pas de colonne dupliquée ; Collaborateur : ce qui lui est
affecté) — appliquée au niveau de la requête (`whereHas`), pas filtrée après
coup en mémoire (Règle n°6). Nouvelle policy `rechercher()`, plus permissive
que `voirFileAttente()` : l'Agent y a accès (il doit pouvoir retrouver ce
qu'il a enregistré), pas seulement les acteurs du circuit de validation.
Pagination systématique (20/page) + eager loading de `service` (Règle n°3).
Index ajouté sur `date_mouvement` (migration séparée) : seule colonne
filtrable qui n'était pas encore indexée (Règle n°3) — `numero_reference`
(contrainte unique), `statut`, `service_id` (clé étrangère) et
`expediteur_nom` l'étaient déjà depuis la création de la table.
Alternatives envisagées : construire la recherche plein-texte OCR dans la
même entrée pour livrer tout le Module 8 d'un coup — écarté, hors du
périmètre demandé (fermer le point ouvert du Module 3, pas construire tout
le Module 8) et un chantier nettement plus lourd (indexation, moteur de
recherche texte) mérite son propre cadrage plutôt que d'être ajouté en
prime.
Vérifié : pint propre, 221/221 tests (13 nouveaux — scoping par profil × 4,
un test par filtre × 7, réinitialisation, profil inconnu refusé) ; migration
appliquée sur la base dev ; assets reconstruits (nouvelle vue).

## [2026-09-07] Correctif : la présélection du collaborateur le moins chargé n'était jamais réellement appliquée
Contexte : l'utilisateur, testant l'affectation avec les comptes
Collaborateur créés la veille (rattachés au service DI), a signalé un refus
lors d'une tentative d'affectation. Aucune trace utile dans les journaux
(seules des erreurs d'anciens passages de tests y figuraient) — diagnostic
fait en relisant le code du panneau Circuit (`ShowCourrier`).
Diagnostic : le commentaire de `collaborateursDuService()` affirme "le
premier de la liste est présélectionné dans la vue", mais rien — ni le
composant, ni la vue Blade (`<flux:select>` avec un simple placeholder,
aucune valeur par défaut) — ne le faisait réellement. `collaborateurSelectionne`
restait `null` tant que l'agent ne cliquait pas lui-même sur une option du
menu. Cliquer "Affecter" sans y toucher d'abord déclenchait systématiquement
l'erreur de validation "Choisissez un collaborateur du service." — lue par
l'utilisateur comme un refus. Les tests existants du fichier
`CircuitCourrierTest.php` ne l'avaient jamais détecté : chacun fixe
`collaborateurSelectionne` à la main avant d'appeler `affecter()`/`reaffecter()`,
contournant sans le vouloir exactement le scénario cassé (cliquer sans rien
choisir).
Décision : `ShowCourrier::mount()` présélectionne désormais réellement le
premier élément de `collaborateursDuService()` (déjà trié par charge
croissante) dans `$collaborateurSelectionne`, quand le courrier est encore
au statut `enregistre`. Limité à la première affectation (pas à la
réaffectation, dont le menu déroulant reste vide par défaut) : c'est le
scénario explicitement nommé par l'utilisateur, et la réaffectation a une
sémantique différente (choisir consciemment un AUTRE collaborateur, pas
nécessairement le moins chargé) — même lacune potentielle côté
réaffectation, non traitée ici faute de signalement, à reprendre si
constatée en pratique.
Alternatives envisagées : présélectionner aussi dans `reinitialiserCirculation()`
(après une action réussie qui laisse le panneau de réaffectation visible) —
écarté pour l'instant, hors du scénario rapporté, à traiter séparément si
besoin.
Vérifié : nouveau test reproduisant exactement le scénario cassé (deux
collaborateurs à charge inégale, aucun `set('collaborateurSelectionne', …)`
avant `call('affecter')`) — échouait avant le correctif, passe après ; pint
propre, 222/222 tests.

## [2026-09-07] Organisation expéditrice extraite d'une formule d'auto-présentation ("La société X…")
Contexte : demande de clarification sur "le nom et l'organisation ne se
montrent pas toujours", après le formulaire d'enregistrement — diagnostic
fait en rejouant le vrai texte OCR du brouillon 8 (document "FORMAVISION.COM",
finalisé en courrier GEC-2026-DI-000002). Confirmé : ni Nom ni Organisation
ne s'étaient proposés automatiquement sur ce document — comportement
attendu vu l'état du texte (en-tête OCR trop dégradé, "RMA N.COM" au lieu
de "FORMAVISION.COM", aucune forme juridique lisible). La valeur
"FORMAVISION.COM" actuellement enregistrée en base pour ce courrier a donc
été tapée à la main par l'utilisateur, pas proposée par le système.
Décision : nouveau motif dans `extraireExpediteurOrganisation()`, entre le
motif préfixe (Ets/Cabinet) et le repli "bloc de coordonnées" — une formule
d'auto-présentation ("La société X…", "L'entreprise X…", "Le groupe X…",
"La compagnie X…"), très courante dans le corps d'un courrier commercial
pour que l'expéditeur se nomme lui-même. Repéré précisément parce que le
corps du texte du document FORMAVISION.COM contient "La société FORMAVISION
Cameroun créée en 2007 est une filiale du groupe FORMAVISION International."
— une phrase parfaitement lisible malgré un en-tête totalement illisible.
Même garde-fou NSIA que les autres motifs ; même risque déjà accepté qu'un
tiers cité dans un récit puisse être confondu (ex. "la société Garage
Excellence, qui a établi un devis").
Bug trouvé et corrigé en écrivant les tests de cette même entrée : le
premier essai utilisait le drapeau `/i` (insensible à la casse) pour
reconnaître "société"/"entreprise" sous toute casse — mais en PCRE, `/i`
rend AUSSI les classes de caractères `[A-Z...]` insensibles à la casse,
défaisant sans le vouloir la contrainte "mot capitalisé" du motif `$mot`
partagé avec les autres branches de la fonction (le motif capturait alors
des mots minuscules entiers de la phrase suivante, ex. "FORMAVISION
Cameroun créée en" au lieu de "FORMAVISION Cameroun"). Corrigé en
énumérant explicitement les variantes de casse du marqueur ("La
[Ss]ociété", "[Ll]'entreprise"…) plutôt que d'utiliser `/i` sur tout le
motif.
Vérifié : "FORMAVISION Cameroun" extrait correctement à la fois d'un
extrait du vrai texte OCR dégradé et de quatre phrases construites (société/
entreprise/groupe/compagnie) ; garde-fou NSIA et risque tiers déjà acceptés
revérifiés ; toutes les non-régressions précédentes (ITSC Sarl, Cabinet
d'Avocats…) confirmées ; pint propre, 225/225 tests.

## [2026-09-07] RC et NIU de l'expéditeur — nouveaux champs, proposés automatiquement
Contexte : demande explicite de l'utilisateur — pouvoir aussi collecter le
RC (Registre du Commerce) et le NIU (Numéro d'Identifiant Unique fiscal)
des courriers, deux identifiants légaux quasi systématiques en en-tête/pied
de page d'un courrier commercial camerounais (déjà vus sur les deux vrais
documents fournis : "N° RC/YAO/2019/B/433 - Contribuable :
M051912784615T - NIU : M051912784615T" pour ITSC Sarl, "RC/YAO/2007/B/4014"
+ "N.I.U." pour FORMAVISION.COM).
Décision (nouveaux champs) : deux colonnes dédiées `expediteur_rc` et
`expediteur_niu` sur `courriers` (migration séparée), plutôt que de les
mélanger dans `expediteur_coordonnees` (champ texte libre de contact,
sémantiquement différent — un identifiant légal n'est pas un moyen de
contact) — ajoutées à `CourrierForm`, `RegistrationForm`, `EditForm`,
affichées sur `ShowCourrier`, sur le même modèle que les champs expéditeur
existants (nullable, jamais requis).
Décision (extraction) : `extraireExpediteurRc()` capture le motif complet
"RC/{ville}/{année}/{type}/{numéro}" tel qu'écrit (avec ou sans "N°"
devant). `extraireExpediteurNiu()` cherche "N.I.U."/"N.U.I." (les deux
ordres de lettres constatés sur les vrais documents) avec ":" ou non, puis
à défaut "Contribuable :" (même valeur que NIU sur le document ITSC Sarl —
ancien intitulé pour le même identifiant). "I" et "L" tolérés l'un pour
l'autre dans le sigle NIU spécifiquement : le vrai texte OCR du document
FORMAVISION.COM confond systématiquement les deux à cet endroit précis, à
DEUX occurrences différentes du même document ("NLU." et "N.U.L" pour
"N.I.U."/"N.U.I.") — tolérance étroite et justifiée par des cas réels
observés deux fois, pas une correction OCR générale (qui resterait hors de
portée d'une simple regex).
Décision (affichage, effet de bord corrigé) : en ajoutant RC/NIU au même
bloc "Expéditeur" de `ShowCourrier`, remarqué que `expediteur_coordonnees`
— capturé depuis le 2026-09-04 — n'y avait pourtant jamais été affiché
(seuls Nom et Organisation l'étaient). Corrigé dans la même entrée plutôt
que laissé à part : les trois champs (Coordonnées, RC, NIU) partagent
maintenant le même bloc conditionnel, chacun affiché seulement s'il est
renseigné.
Alternatives envisagées : une correction OCR générale I/L pour tout le
fichier — écartée, bien trop large (casserait potentiellement d'autres
motifs qui dépendent d'un "I" ou un "L" précis) ; un seul champ combiné
"Identifiants légaux" au lieu de deux colonnes séparées — écarté, RC et NIU
sont deux identifiants distincts avec des formats différents, plus utiles
séparément pour une recherche/vérification future.
Vérifié : RC et NIU extraits correctement des deux vrais documents (dont le
NIU dégradé par l'OCR sur FORMAVISION.COM, dans ses deux graphies) ;
affichage sur la fiche détail vérifié, y compris la correction du gap
Coordonnées ; migration appliquée sur la base dev ; pint propre, 232/232
tests ; assets reconstruits (aucune nouvelle classe Tailwind introduite,
empreinte CSS identique).

## [2026-09-07] Correctif : l'objet de repli était tronqué au premier saut de ligne
Contexte : l'utilisateur a signalé, capture d'écran de la liste des
courriers à l'appui, que l'objet du courrier GEC-2026-DI-000003 (document
FORMAVISION.COM) restait incomplet ("...et systèmes de", sans "surveillance
avancés."). Vérifié en base : la valeur stockée était bien tronquée, pas
un simple effet d'affichage.
Diagnostic : `objetPresDeLaFormuleDappel()` (voir entrée "Objet proposé
sans mention 'Objet :'…", 2026-09-04) capture le texte de la ligne "Libellé :
texte" trouvée dans le paragraphe précédent, mais ignorait les lignes
SUIVANTES de ce même paragraphe — alors qu'un retour à la ligne dans un
document scanné est presque toujours un simple effet de largeur de page
("Solutions innovantes : Optimisez votre bureau avec nos fournitures
connectées et systèmes de\nsurveillance avancés." = UNE seule phrase sur
deux lignes), pas une nouvelle phrase. Déjà pressenti comme limite mineure
au moment d'écrire la fonction (voir DECISIONS.md, 2026-09-04) mais jamais
corrigé avant d'être réellement rencontré.
Décision : une fois la ligne "Libellé : texte" trouvée, les lignes
restantes du même paragraphe (déjà collectées dans `$paragraphePrecedent`)
sont recollées à la suite, séparées par un espace, plutôt que de s'arrêter
à la première ligne.
Vérifié : "Optimisez votre bureau avec nos fournitures connectées et
systèmes de surveillance avancés." (phrase complète) extrait désormais
correctement ; le courrier GEC-2026-DI-000003 déjà enregistré recalculé et
corrigé en base à partir de son texte OCR déjà stocké (pas de nouveau scan
nécessaire) ; pint propre, 232/232 tests.

## [2026-09-07] Courrier confidentiel jamais ouvert : objet générique imposé ; "Affaire X c/ Y" noté, non traité
Contexte : l'utilisateur a discuté avec le personnel de réception qui traite
réellement le courrier entrant et rapporté deux points opérationnels
jusqu'ici inconnus du projet : (1) un courrier marqué confidentiel n'est
JAMAIS ouvert par l'agent qui l'enregistre — seul le nom lisible sur
l'enveloppe est noté, et le courrier est orienté directement vers RH ou la
DGA sans consultation du contenu ; (2) certains dossiers sinistre suivent
une convention "Affaire [Partie A] c/ [Partie B]" (une partie contre une
autre), non capturée par la structure actuelle.
Décision (objet confidentiel) : Objet reste obligatoire (règle métier
Module 1, "un enregistrement incomplet ne peut pas être validé" —
specifications-modules-GEC.md), mais reçoit un texte générique imposé,
"Correspondance confidentielle (non ouverte)", dès que l'agent passe
Confidentialité à confidentiel/très confidentiel — uniquement si Objet est
encore vide, jamais en écrasant une saisie déjà faite. Choix confirmé par
l'utilisateur entre trois options (Objet facultatif si confidentiel / texte
générique imposé / ne rien changer) : le texte générique imposé, pas le
champ rendu facultatif — cohérent avec la règle métier existante plutôt que
d'y déroger. `wire:model` sur Confidentialité passé en `wire:model.live`
pour déclencher le hook Livewire `updatedFormConfidentialite()` dès le
changement, sans attendre un autre aller-retour serveur.
Décision (nom sur l'enveloppe) : confirmé par l'utilisateur — ce nom
correspond au champ `expediteur_nom` déjà existant, aucun nouveau champ
nécessaire. Purement une clarification d'usage, aucun changement de code.
Décision ("Affaire X c/ Y") : explicitement noté mais **non traité** —
l'utilisateur l'a marqué "pas prioritaire pour l'instant" plutôt que de
choisir entre les deux options proposées (texte libre dans Objet, ou deux
nouveaux champs "Partie 1"/"Partie 2"). Point ouvert à reprendre : si un
jour traité, trancher entre convention de saisie dans Objet (aucun
changement de schéma) et champs dédiés (changement de schéma, comme
`sous_type_sinistre`).
Vérifié : nouveau test confirmant le texte générique imposé au changement
de Confidentialité, et un second confirmant qu'un objet déjà saisi n'est
jamais écrasé ; pint propre, 234/234 tests.

## [2026-09-07] Interface bilingue français/anglais
Contexte : demande explicite de l'utilisateur ("eng et fr de l'appli").
Constat en investiguant : `APP_LOCALE`/`APP_FALLBACK_LOCALE` valaient déjà
"en" (config par défaut du starter kit, jamais ajustée), mais aucun fichier
`lang/` n'existait — tout le texte de l'interface est écrit directement en
français comme argument de `__()`, donc utilisé tel quel comme clé de
traduction (comportement de repli de Laravel quand aucune traduction ne
correspond). L'application affichait donc du français uniquement par
coïncidence, pas par configuration explicite.
Décision (périmètre) : traduction complète des écrans propres au GEC —
composants `App\Livewire\Backend\*` et leurs vues (`resources/views/
livewire/frontend/*.blade.php`), navigation (`sidebar.blade.php`). Le
bordereau PDF (`resources/views/pdf/bordereau.blade.php`) n'utilise aucun
`__()` (texte français codé en dur) — non traité ici, chantier séparé s'il
est demandé. Les pages d'authentification/réglages du starter kit
(login, inscription, paramètres du compte…) sont déjà en anglais par
défaut et n'ont pas été touchées.
Décision (mécanisme) : traductions anglaises dans `lang/en.json` (clé =
texte français exact utilisé dans le code, comme Laravel l'exige pour ce
mode de traduction) plutôt que des fichiers PHP par namespace — cohérent
avec la convention déjà en place (texte français directement en argument de
`__()`, pas de clés abstraites du style `messages.objet`). `APP_LOCALE`/
`APP_FALLBACK_LOCALE` corrigés à "fr" (`.env`, `.env.example`,
`config/app.php`) pour que le français reste la langue par défaut réelle —
sans ce changement, ajouter `lang/en.json` aurait fait basculer
silencieusement toute l'application en anglais (le comportement actuel
"tout s'affiche en français" ne tenait qu'à l'absence de fichier de
traduction, pas à une locale française explicite).
Décision (bascule) : choix de langue gardé en session
(`App\Http\Middleware\SetLocale`, ajouté au groupe `web`), pas une colonne
sur `users` — un même poste de réception est probablement partagé par
plusieurs agents au pilote (voir PRD.md, déploiement progressif par
service), la session suffit et évite une migration pour un besoin
non confirmé comme durable par profil. Route `GET /langue/{locale}`
(`LocaleController`), hors du groupe `auth`/`verified` : un visiteur non
connecté doit pouvoir changer de langue aussi. Une langue non reconnue est
ignorée silencieusement (retour à la page précédente), jamais une 404 — un
lien mal formé ne doit jamais bloquer l'agent. Sélecteur FR/EN ajouté dans
le bas de la barre latérale.
Vérifié : script dédié comparant CHAQUE appel `__()` du code (352 au total,
extraits par une regex qui gère les apostrophes échappées) à `lang/en.json`
— aucune traduction manquante après deux allers-retours (RC/NIU/Search
oubliés au premier passage, ajoutés). Traduction testée fonctionnellement
(`__('Objet')` → "Subject" une fois la locale basculée, y compris avec
placeholder `:nom`). `APP_LOCALE=fr` confirmé ne casser aucun des 234 tests
existants (qui vérifient tous du texte français) — la locale par défaut
reste français comme avant ce changement. Nouveau test dédié à la bascule
elle-même (défaut français, persistance en session, langue invalide
ignorée, traduction appliquée après changement) ; pint propre, 238/238
tests ; assets reconstruits (nouvelles classes du sélecteur confirmées dans
le CSS compilé, empreinte différente de la précédente).

---

## [2026-09-07] Extraction OCR affinée sur 5 nouveaux vrais documents
Contexte : l'utilisateur a fourni 5 nouveaux courriers réels (TBG, Univsoft
SARL, TBS SARL, Ste SAPDIST SARL, ENGITAS) pour vérifier le format couvert
par le Module 1/2. Plutôt que de deviner depuis une lecture visuelle des
PDF, chacun a été passé dans le VRAI pipeline OCR (Tesseract via
`ProcessDocumentOcr::ocrFichier()`, même binaire/tessdata que la prod) puis
dans les fonctions d'extraction actuelles, pour ne réagir qu'à des écarts
réellement observés (l'OCR dégrade/recolle le texte de façon souvent
imprévisible — plusieurs "gaps" supposés à la lecture visuelle du PDF ne se
sont finalement PAS produits sur le vrai texte OCR, ex. "Attn :"/"ATTN :"
déjà couvert par le repli civilité existant).
Décision : quatre changements dans `ProcessDocumentOcr` :
1. "Raison sociale :" ajouté comme marqueur d'organisation explicite (même
   fiabilité qu'"Expéditeur :") — intitulé légal exact vu en pied de page du
   document TBG.
2. "Concerne :" ajouté comme synonyme d'"Objet :" — convention administrative
   francophone courante, vue sur le document TBG (l'OCR de CE document
   précis l'a dégradé en "Conceme :", donc ce cas précis reste couvert par
   le repli existant plutôt que ce nouveau motif — mais un futur document où
   l'OCR lit correctement "Concerne :" en bénéficiera).
3. "RC N° :" (N° APRÈS "RC", pas seulement avant) ajouté à
   `extraireExpediteurRc()` — vu sur le document Ste SAPDIST SARL
   ("RC N° : CM-DLA-02-2025-B-00827"), forme non couverte par le motif
   existant qui exigeait "RC" immédiatement suivi de "/" ou ":".
4. Bug réel corrigé dans `organisationPresDunBlocCoordonnees()` (dernier
   repli, sans forme juridique reconnaissable ailleurs dans le document) :
   son second cas acceptait toute ligne en casse normale du seul fait
   qu'elle contient le mot "Sarl"/"SA"/etc., et renvoyait la ligne ENTIÈRE.
   Sur le document Univsoft SARL, l'OCR avait recollé sur une seule ligne
   physique le bloc expéditeur ET le bloc destinataire (mise en page à deux
   colonnes) — "oft SARL, À l'attention de Monsieur le Directeur Général"
   (le début du nom, "Univs", illisible) était donc proposé tel quel comme
   organisation. Ce cas est supprimé : les motifs principaux (suffixe/
   préfixe juridique) scannent déjà TOUT le document, pas seulement une
   ligne — un nom valide (mot capitalisé immédiatement avant la forme
   juridique) y aurait déjà été trouvé avant d'arriver à ce repli. Le repli
   ne renvoie donc plus qu'une ligne ENTIÈREMENT EN MAJUSCULES (seul cas
   qu'aucun motif principal ne peut couvrir, car sans aucune forme
   juridique). `$mot` (motif d'un mot du nom) extrait en méthode partagée
   `motDuNom()` pour éviter la duplication entre les deux endroits qui
   l'utilisaient déjà.
Alternatives envisagées : tolérer l'OCR "Conceme" (typo Tesseract observée
sur ce document précis) comme variante de "Concerne" — écarté, une seule
occurrence réelle ne justifie pas une tolérance OCR spécifique (contrairement
au "I"/"L" du NIU, justifié par deux documents distincts) ; le repli
existant produit déjà une valeur exploitable pour ce cas. Borner la capture
d'`extraireExpediteurCoordonnees()` (sur ENGITAS, un pied de page à 3
colonnes recollé par l'OCR a fait déborder "Tél :" jusque dans un bloc
bancaire sans rapport) — écarté : aucune limite fiable trouvée qui ne
casserait pas le cas légitime existant (adresse complète après le
téléphone) ; limitation OCR de mise en page multi-colonnes documentée
ci-dessous plutôt que corrigée par un correctif fragile.
Vérifié : les 5 documents repassés dans le vrai pipeline OCR avant ET après
le changement (script `test_nouveaux_courriers.php` dans le scratchpad) —
confirme la disparition du faux positif Univsoft et l'apparition du RC sur
SAPDIST, sans régression sur les 3 autres documents. 4 tests ajoutés/repris
dans `ProcessDocumentOcrTest` (marqueur "Concerne", marqueur "Raison
sociale", RC "N° après", non-régression du repli organisation) ; 242/242
tests, pint propre.

## [2026-09-07] Limites OCR connues (mise en page multi-colonnes/photo)
Contexte : sur les 5 nouveaux documents (voir décision ci-dessus), plusieurs
écarts constatés ne sont PAS des bugs de regex mais des limites de l'OCR
lui-même — à garder en tête avant de proposer un futur correctif regex pour
l'un de ces symptômes précis.
Décision (documenter, ne pas "corriger") :
- Mise en page à 2-3 colonnes (bloc expéditeur/destinataire/bancaire côte à
  côte) : Tesseract (psm 1) les lit parfois ligne par ligne en les
  entrelaçant plutôt que colonne par colonne, recollant des blocs sans
  rapport sur une même ligne physique (vu sur Univsoft SARL et ENGITAS).
- Photo inclinée/mal cadrée (par opposition à un scan à plat) : dégrade
  fortement la qualité (document TBS SARL — mots coupés en plein milieu
  "Gé\nnéral", bloc RC/NIU du pied de page pas du tout reconnu) ou coupe le
  bas de page (document Ste SAPDIST SARL — RC tronqué à "CM-DL",
  NIU absent).
- Bandeaux/pastilles colorées (boutons de services en bas de page, cachets) :
  parfois totalement ignorés par l'OCR (pied de page RC/NIU du document TBG,
  jamais présent dans le texte reconnu malgré Ghostscript+Tesseract
  fonctionnels).
Alternatives envisagées : aucune — ce sont des limites de qualité
d'acquisition (photo vs scan à plat) ou de segmentation automatique de
Tesseract, hors du périmètre d'un correctif d'expression régulière.
Vérifié : observé directement dans le texte OCR réel produit par le
pipeline (pas une supposition).

---

## [2026-09-07] Aperçu PDF via PDF.js vendu (zoom/recherche/rotation)
Contexte : l'utilisateur a montré en référence l'aperçu PDF natif d'Outlook
(barre d'outils claire, zoom, recherche, rotation, texte net et lisible dès
l'ouverture) et demandé que l'aperçu "Voir le document" (Module 2) y
ressemble. L'aperçu existant n'était qu'un `<iframe>` pointant sur le PDF
brut (`Content-Disposition: inline`) : le rendu dépendait entièrement du
lecteur PDF natif de CHAQUE navigateur, sans contrôle sur l'apparence ni
garantie de cohérence (barre d'outils absente/différente selon Chrome,
Edge, Firefox, ou un iframe sandboxé).
Décision : la distribution officielle prête à l'emploi de PDF.js (le moteur
qui alimente Chrome/Firefox eux-mêmes) est vendue statiquement dans
`public/vendor/pdfjs/` (dossiers `web/` et `build/`, ~7,8 Mo après
suppression des source maps de debug, sans impact fonctionnel) plutôt
qu'une visionneuse maison. L'aperçu d'un PDF pointe désormais vers
`vendor/pdfjs/web/viewer.html?file={URL encodée de la route d'aperçu}` —
même origine que l'appli, donc pas de souci CORS/cookies de session. Une
image scannée (JPG/PNG — l'extension d'origine n'est jamais convertie en
PDF, voir `Courrier::estUnDocumentPdf()`) continue d'être affichée
nativement : PDF.js ne sait pas l'ouvrir.
Alternatives envisagées : (1) construire une visionneuse maison avec les
classes réutilisables `pdfjs-dist` (npm, `PDFViewer`/`PDFFindController`)
et une barre d'outils Flux UI — écarté : sans navigateur disponible dans
cette session pour tester visuellement le rendu (positionnement du canvas,
redimensionnement dans la modale Livewire, ouverture du worker), le risque
de livrer quelque chose de cassé sans pouvoir le vérifier était trop élevé
face à la distribution officielle, déjà testée par des millions
d'utilisateurs. (2) Garder l'iframe natif et espérer un rendu cohérent
entre navigateurs — écarté, c'est justement ce que l'utilisateur a signalé
comme insatisfaisant. Le paquet npm `pdfjs-dist` (installé puis
DÉSINSTALLÉ) ne fournit PAS `viewer.html`/sa barre d'outils via npm depuis
les versions récentes — seul le zip de release GitHub l'inclut, d'où le
choix du vendoring statique plutôt qu'un import npm.
Vérifié : suite de tests complète (244/244) et pint propres. Chaque
fichier statique vendu (viewer.html, viewer.mjs, viewer.css, pdf.mjs,
pdf.worker.mjs) confirmé servi par Herd avec le bon Content-Type (essentiel
pour les scripts `type="module"`). **Non vérifié** : le rendu visuel réel
dans un navigateur (zoom par défaut, barre d'outils, recherche) — aucun
outil de navigateur disponible dans cette session ; à confirmer par
l'utilisateur en conditions réelles.

## [2026-09-07] Barre d'outils PDF.js restylée façon pastille Outlook
Contexte : après le choix ci-dessus (visionneuse PDF.js vendée), l'utilisateur
a précisé que la barre d'outils STOCK de PDF.js (barre du haut façon
application de bureau, barre latérale miniatures/plan, outils
d'annotation…) n'est PAS ce qu'il demandait — il veut la pastille flottante
minimale exacte de l'aperçu Outlook envoyé en référence (zoom -, zoom +,
recherche, rotation, "..." — 5 icônes, en bas, discrète).
Décision : plutôt que reconstruire une visionneuse maison (canvas + zoom/
rotation/recherche codés à la main — trop risqué sans navigateur pour
tester), les boutons D'ORIGINE de PDF.js (mêmes id, mêmes écouteurs
d'évènement déjà testés par Mozilla) sont simplement DÉPLACÉS dans le DOM,
au chargement, vers une nouvelle pastille flottante :
`public/vendor/pdfjs/web/apercu-outlook.js` (script classique, déplace
`zoomOutButton`, `zoomInButton`, le conteneur de `viewFindButton` (bouton +
son popup `#findbar`), `pageRotateCw`, et le conteneur de
`secondaryToolbarToggle` (bouton "..." + son popup `#secondaryToolbar`,
vidé de tout ce qui fait doublon — imprimer/télécharger/ouvrir/propriétés :
voir `apercu-outlook.css`) — et `public/vendor/pdfjs/web/apercu-outlook.css`
(cache la barre/barre latérale d'origine, style la pastille, et corrige
deux effets de bord du déplacement : l'icône de `pageRotateCw`, stylée par
une règle scopée à son ancien parent `#secondaryToolbar`, est restaurée
sans cette exigence ; les popups (recherche, "...") qui s'ouvraient
normalement vers le bas depuis une barre en haut d'écran sont inversés pour
s'ouvrir vers le HAUT depuis une pastille en bas d'écran). Aucune ligne de
`viewer.mjs` (la logique PDF.js elle-même) n'est modifiée — zéro risque sur
le zoom/la recherche/la rotation en tant que tels, seul l'habillage visuel
change.
Alternatives envisagées : dupliquer les boutons (nouveaux éléments avec
nouveaux id, ré-implémentant les actions à la main) — écarté, ça revenait à
re-coder et re-tester une logique déjà fournie et déjà fiable par Mozilla ;
masquer la barre d'origine en CSS pur sans rien déplacer, et déclencher les
actions via des clics programmés sur les boutons cachés — écarté, un popup
(recherche, menu "...") ouvert à l'intérieur d'un ancêtre `display:none`
resterait invisible même une fois "ouvert", donc inutilisable pour la
recherche notamment.
Vérifié : les 3 fichiers (viewer.html modifié, apercu-outlook.css/.js
nouveaux) confirmés servis par Herd avec le bon contenu et le bon
Content-Type. **Non vérifié** : le rendu visuel réel (position exacte de la
pastille, alignement des popups inversés, apparence de l'icône de rotation
restaurée) — toujours aucun navigateur disponible dans cette session ; il
s'agit ici d'une personnalisation plus profonde que la décision précédente,
donc un risque résiduel plus élevé à vérifier en conditions réelles.

---

## [2026-09-08] Barre PDF.js : référence Outlook remplacée (aperçu .docx, pas PDF)
Contexte : après la pastille flottante ci-dessus (calquée sur l'aperçu PDF
d'Outlook), l'utilisateur a fourni une AUTRE capture d'écran de référence —
l'aperçu d'un fichier **.docx** dans Outlook : barre CLAIRE en HAUT avec des
boutons libellés icône+texte ("Mode d'accessibilité", "Imprimer", "Lecteur
immersif", "Traduire", "..."), et un indicateur "Page 1 sur 5" séparé en bas
à gauche — layout différent de l'aperçu PDF (pastille sombre flottante en
bas, icônes seules) utilisé pour la version précédente.
Décision : cette référence REMPLACE la précédente. Plusieurs boutons de la
référence n'ont pas de sens pour un scan (pas de "Traduire"/"Lecteur
immersif"/"Ouvrir dans Word" pertinents pour un courrier scanné) — le STYLE
est repris (barre claire en haut, boutons icône+texte, indicateur de page
séparé en bas à gauche), appliqué aux actions déjà pertinentes de
PDF.js (zoom, recherche, rotation, "..."), plutôt qu'une copie littérale des
boutons Word. Mécanisme inchangé (toujours les éléments PDF.js d'origine
déplacés, pas recréés) : `--toolbar-height` (variable déjà utilisée par
`#viewerContainer` pour réserver sa marge du haut) redéfinie à 48px plutôt
que recalculée à la main ; recherche et rotation affichent maintenant leur
libellé texte (masqué par défaut dans la barre d'origine, réservé aux
lecteurs d'écran) ; zoom et "..." restent icône seule (la référence ne
montre pas de zoom du tout, et son "..." est bien sans texte) ; les popups
s'ouvrent de nouveau normalement vers le bas (plus besoin de les inverser,
la barre est en haut comme dans l'original) ; l'indicateur de page réutilise
le champ `#pageNumber` (toujours éditable, saut de page fonctionnel) et
`#numPages` d'origine, restylés en texte simple. `<html lang="fr">` ajouté
(cosmétique — PDF.js lit en réalité `navigator.language`, pas cet
attribut : sans effet garanti, mais la langue du navigateur de l'utilisateur
est très probablement déjà le français d'après tous ses environnements
observés cette session).
Alternatives envisagées : garder la pastille basse et ignorer la nouvelle
référence — écarté, l'utilisateur a explicitement dit que ce n'est "pas ce
style" et fourni une référence précise à suivre "exactement". Copier
littéralement les boutons Word (Traduire, Lecteur immersif…) — écarté, ces
fonctions n'existent pas pour un PDF et n'auraient rien fait.
Vérifié : les 3 fichiers confirmés servis par Herd avec le contenu à jour ;
`node --check` sur apercu-outlook.js (syntaxe valide). **Non vérifié** :
rendu visuel réel — toujours aucun navigateur dans cette session ; risque
résiduel comparable à la révision précédente, à confirmer par l'utilisateur.

---

## [2026-09-08] Recherche plein-texte dans le contenu OCR (Module 8)
Contexte : demande explicite de l'utilisateur — retrouver un courrier par un
fragment de texte vu sur le document scanné (montant, capital social d'une
organisation cité, etc.) même en ayant oublié l'expéditeur/objet exact.
Point resté ouvert depuis la clôture provisoire du Module 3 (voir notes internes et le commentaire laissé dans `CourrierList.php` :
"recherche plein-texte... volontairement HORS périmètre ici") — le choix de
moteur ("Scout + Meilisearch vs MySQL FULLTEXT", noté sans être tranché)
est maintenant nécessaire.
Décision : **MySQL FULLTEXT natif**, pas Meilisearch/Scout. Nouveau champ
"Contenu du document" dans `CourrierList` (distinct des filtres Objet/
Expéditeur existants), interrogeant `texte_ocr` via `whereFullText(...,
['mode' => 'boolean'])` — mode BOOLÉEN et non langage naturel : ce dernier
exclut silencieusement tout terme présent dans plus de 50 % des lignes de la
table, un seuil bien trop vite atteint avec le peu de courriers du pilote
(vérifié : "Capital" apparaît dans une majorité des documents commerciaux
réels de cette session — en langage naturel, une recherche sur ce terme
n'aurait renvoyé AUCUN résultat malgré des correspondances réelles). Un
extrait de ~120 caractères autour du terme trouvé est affiché dans les
résultats (`CourrierList::extraitTexteOcr()`) pour que l'utilisateur
comprenne pourquoi tel courrier remonte — sans lui, un résultat basé
uniquement sur `texte_ocr` (jamais affiché en liste par ailleurs) serait
incompréhensible. Nouvel index FULLTEXT sur `courriers.texte_ocr` (Règle
n°3 CONVENTIONS.md — un `LIKE '%terme%'` à joker en tête ne peut utiliser aucun
index B-tree classique, seul un FULLTEXT le permet).
Alternatives envisagées : Meilisearch + Laravel Scout — écarté, ajoute un
service séparé à déployer/maintenir (contraire à la contrainte "1
développeur", "déploiement progressif par service" du PRD) et
au champ d'application MVP/pilote (PRD : "plein-texte OCR si le temps le
permet", explicitement optionnel, pas un pilier du MVP) — MySQL FULLTEXT ne
demande aucune infrastructure nouvelle et suffit largement à ce volume.
Mode langage naturel (par défaut de `whereFullText()`) — écarté pour la
raison ci-dessus (seuil de 50 % trop facilement atteint en pratique, testé
et confirmé). Fusionner ce filtre avec le champ Objet existant plutôt qu'un
champ séparé — écarté, le PRD distingue explicitement "objet" et "contenu
du texte scanné" comme deux critères de recherche différents (Module 8).
Vérifié : suite complète 245/245, pint propre. **Comportement MySQL réel
confirmé par un script à part** (pas seulement via la suite de tests, qui
tourne sur SQLite et n'exerce que le repli LIKE — voir migration) : recherche
sur "SAPDIST", "990", "ENGITAS", un NIU complet ("M042517751797H") toutes
correctement trouvées ; "Capital" (terme très fréquent dans le jeu de
données réel) correctement PAS exclu en mode booléen (l'aurait été en
langage naturel) ; terme absent correctement sans résultat. **Piège
découvert en vérifiant** : un index FULLTEXT InnoDB ne rend les nouvelles
lignes cherchables qu'APRÈS COMMIT de la transaction qui les insère
(contrairement à un index B-tree classique, immédiatement à jour) — un
premier essai de vérification enveloppé dans une transaction annulée
(`DB::rollBack()`) ne trouvait donc JAMAIS les lignes de test tout en
trouvant les vrais courriers déjà en base, ce qui aurait pu faire croire à
tort à une recherche cassée ; sans impact en usage normal (l'enregistrement
d'un courrier n'est jamais enveloppé dans une transaction non validée), mais
à garder en tête pour tout futur test/débogage manuel de cette
fonctionnalité contre une vraie base MySQL.

---

## [2026-09-08] Filtres "Type de document" et "Confidentialité" dans la recherche
Contexte : demande explicite de l'utilisateur — "type document (catégorie
de document (confidentiel etc))" : deux critères de recherche
supplémentaires pour `CourrierList` (Module 8).
Décision : deux filtres distincts, chacun cohérent avec la nature réelle du
champ en base (voir `CourrierForm::rules()`) : `type_document` est un TEXTE
LIBRE saisi à l'enregistrement (ex. "Lettre", "Facture" — aucune liste
fermée) → filtre `LIKE '%...%'`, comme Objet/Expéditeur ; `confidentialite`
est une LISTE FERMÉE (normale/confidentiel/très confidentiel) → filtre à
choix (`<flux:select>`), comme Statut/Sens. Un résultat confidentiel/très
confidentiel est aussi signalé par un badge ambre à côté de l'Objet dans le
tableau de résultats — cohérent avec la manière dont RegistrationForm
signale déjà la confidentialité ailleurs dans l'appli, et utile même sans
filtrer explicitement dessus (on voit tout de suite qu'un résultat trouvé
par un autre critère est sensible). Nouvel index B-tree classique sur
`confidentialite` (Règle n°3) — une égalité simple, pas un `LIKE` à joker,
contrairement à `texte_ocr` qui a nécessité du FULLTEXT ; `type_document`
était déjà indexé depuis la création de la table (`2026_09_03_100003_...`),
aucune migration nécessaire pour lui.
Alternatives envisagées : n'ajouter qu'un champ unique fusionnant les deux
(« catégorie ») — écarté, ce sont deux champs de nature différente en base
(texte libre vs liste fermée), les fusionner aurait empêché un filtre à
choix propre sur la confidentialité. Restreindre l'accès au filtre
confidentialité par profil — non nécessaire : `CourrierPolicy` ne
conditionne déjà l'accès à un courrier confidentiel par AUCUNE règle liée
au champ `confidentialite` lui-même (uniquement service/créateur/affectation,
comme les autres courriers) — ce champ est un marqueur de PROCESSUS
(traitement du courrier, voir DECISIONS.md "courrier confidentiel"), pas un
mécanisme de contrôle d'accès ; filtrer dessus n'expose donc rien de plus
que ce que l'utilisateur pouvait déjà voir dans la liste non filtrée.
Vérifié : 247/247, pint propre, migration appliquée à la vraie base de
développement, 0 écart de traduction (356 appels __() audités).

---

## [2026-09-08] "Type de document" en liste déroulante (RegistrationForm/EditForm)
Contexte : le filtre de recherche ci-dessus ("Type de document"/
"Confidentialité") n'était pas ce que l'utilisateur demandait à l'origine —
clarifié via question : il voulait le champ "Type de document" DU
FORMULAIRE D'ENREGISTREMENT (et de modification) transformé en liste
déroulante de catégories (comme "Confidentialité" l'est déjà), pas
seulement un nouveau filtre de recherche sur le champ texte libre existant.
Décision : nouvelle constante `CourrierForm::TYPES_DOCUMENT` (Lettre,
Sinistre, Réclamation, Facture, Demande, Proposition commerciale,
Convocation, Relevé ou bordereau, Contrat ou avenant) — valeurs en texte
brut affiché tel quel (comme objet/expediteur_nom), PAS des clés
d'énumération traduites, pour rester cohérent avec tout le reste de
l'appli qui compare/affiche `type_document` en texte brut
(`ClassificationService::normaliser()`, `CourrierList`, règles de
classement configurées par un admin). "Sinistre" reste dans la liste :
`CourrierForm::estUnSinistre()` en dépend (teste si le texte contient
"sinistre"). Une option "Autre (préciser)" (valeur sentinelle `__autre__`,
jamais stockée) bascule vers un champ texte libre via
`$typeDocumentPersonnalise` (nouveau drapeau, RegistrationForm ET EditForm)
— sans ce garde-fou, une valeur DÉJÀ existante hors de cette liste
(courrier enregistré avant ce changement, ou classé automatiquement par une
règle du Module 3 dont le `type_document_propose` est un texte libre
configuré par un admin, totalement indépendant de cette liste) serait soit
invisible dans un select qui ne la contient pas, soit silencieusement
écrasée à l'enregistrement suivant — testé explicitement (EditForm avec une
valeur hors liste : `typeDocumentPersonnalise` devient vrai, la vraie
valeur reste affichée, jamais perdue). Aucune `Rule::in()` ajoutée à
`CourrierForm::rules()` : la liste ne restreint que le CHOIX à la saisie
via l'UI, jamais la DONNÉE elle-même — une proposition de classement ou une
valeur historique reste acceptée sans jamais bloquer un enregistrement/une
modification. Logique dupliquée (pas de trait/concern partagé) entre
RegistrationForm et EditForm plutôt qu'une abstraction — ARCHITECTURE-
ESSENTIALS.md est explicite sur une structure volontairement à plat, et
c'est une douzaine de lignes utilisées à exactement 2 endroits, pas un cas
d'usage spéculatif.
Alternatives envisagées : `Rule::in()` stricte — écarté, casserait toute
proposition de classement (Module 3) ou valeur historique hors liste dès la
prochaine modification du courrier, un vrai risque de perte de données/de
blocage. Fusionner avec le filtre de recherche ajouté juste avant (même
liste utilisée comme filtre ET comme choix de saisie) — pas nécessaire ici,
la recherche reste volontairement un LIKE texte libre (une catégorie mal
orthographiée dans une ancienne donnée doit rester trouvable).
Vérifié : 252/252, pint propre, 0 écart de traduction (364 appels __()
audités). Nouveaux tests couvrant explicitement : bascule vers "Autre",
enregistrement d'une valeur personnalisée (confirmant l'absence de
Rule::in()), retour à la liste, ET — le cas le plus important — un courrier
existant avec une valeur hors liste reste visible/modifiable en EditForm au
lieu d'être perdu.

---

## [2026-09-08] Circuit courrier entrant : validation DGA/ADJ du service
Contexte : l'utilisateur a décrit un circuit détaillé pour le courrier
ENTRANT (portée explicitement limitée à l'entrant pour l'instant, le sortant
garde son circuit actuel) : réceptionniste confirme/infirme si Module 3
propose "sinistre" ; sinistre confirmé → va directement au responsable de
DSIN ; sinon → un nouveau rôle "DGA/ADJ" confirme/change d'abord le service
avant que le circuit habituel (affectation, traitement, validation,
clôture) ne démarre. Deux questions posées explicitement avant
implémentation (pas supposées) : "Superviseur du service" dans son schéma =
`Responsable de service` existant (confirmé, aucun nouveau rôle pour ce
point — voir notes internes, maintenant résolue)
; DGA/ADJ = un vrai nouveau profil à créer (confirmé).
Décision : après revue complète du code existant, la quasi-totalité du
circuit décrit existait déjà sous d'autres noms — un seul ajout réel.
Correspondance établie et présentée à l'utilisateur avant codage (plan validé) :
`valide_sinistre`(Cas A)→`enregistre` (réutilisé tel quel, comportement
inchangé) ; `en_attente_validation_dga`(Cas B)→**nouveau statut** ;
`valide`(après DGA)→`enregistre` (même état convergent que Cas A) ;
`assigne`→`affecte` ; `en_attente_validation_superviseur`→`en_validation` ;
`cloture`→`traite` ; `en_cours`→`en_traitement` — décision explicite de NE
PAS renommer les valeurs existantes de l'ENUM (coût élevé sur tests/vues/
WorkflowService pour un gain cosmétique). La confirmation/infirmation du
sinistre par la réceptionniste réutilise le champ "Type de document" ajouté
la même session (liste déroulante, voir décision précédente) — pas de
nouveau bouton dédié : `CourrierForm::estUnSinistre()` (déjà existant, teste
si le texte contient "sinistre") sert directement de test au moment de
`RegistrationForm::enregistrer()`, appliqué UNIQUEMENT quand
`sens === 'entrant'`.
Composants ajoutés (schéma volontairement identique à l'existant, voir
`ShowCourrier::affecter()`/`peutAffecter` pour le modèle suivi) :
`WorkflowService::validerService(Courrier, int $serviceId, User $auteur)`
(transaction + historique `service_valide_dga`, même schéma qu'`affecter()`
plutôt que `transitionnerSimple()` car elle modifie aussi `service_id`, pas
seulement `statut`) ; `CourrierPolicy::validerService()` (Administrateur/DGA,
rôle global sans notion de service — contrairement à `Responsable de
service`, qui est scopé par `services.responsable_id`) ; panneau "Valider le
service" sur `ShowCourrier` (présélection RÉELLE du service proposé, même
soin que la présélection du collaborateur déjà corrigée le 2026-09-04) ;
`WorkflowQueue`/`CourrierList` scopés pour DGA par STATUT
(`en_attente_validation_dga`) plutôt que par service (seul profil sans
dimension service). `CourrierPolicy::view()` étendu pour DGA — sans ça, un
DGA n'aurait pas pu ouvrir la fiche depuis sa propre file d'attente.
Alternatives envisagées : renommer les statuts existants pour coller
exactement au vocabulaire de l'utilisateur — écarté (voir ci-dessus).
Router automatiquement vers DSIN en cas de sinistre confirmé (forcer
`service_id` dans le code) — écarté, déjà possible SANS code via une règle
de classement Module 3 existante ("Sinistre" → DSIN, donnée de
configuration, pas développement) ; le champ service reste par ailleurs
toujours modifiable par la réceptionniste comme n'importe quel autre
courrier. Notifications réelles ("notification collaborateur" dans le
schéma de l'utilisateur) et SLA réel ("SLA démarre") — explicitement HORS
PÉRIMÈTRE, les deux restent des stubs vides déjà notés ailleurs (Module 7 :
`SendMailAlertJob`/`CourrierEnRetardNotification`, jamais câblés ; Module 5 :
`SlaCalculatorService::calculerStatutDelai()` retourne toujours `'a_temps'`
sans condition, aucun scheduler enregistré) — sujets séparés déjà mis en
pause plus tôt cette session (design du lien magique, 2026-09-07, jamais
confirmé).
Vérifié : plan écrit et validé par l'utilisateur AVANT implémentation
(`ExitPlanMode`). Migration testée fonctionnelle sur MySQL réel ET SQLite
(Laravel 13 gère `enum(...)->change()` nativement des deux côtés — vérifié
par une insertion réelle post-migration sur chaque moteur, pas seulement
"la migration ne plante pas"). 261/261 tests (9 nouveaux : transition/trace
WorkflowService ×2, file DGA scopée, panneau ShowCourrier ×3 dont un refus
d'autorisation, RegistrationForm ×2 — sinistre reste `enregistre`, sortant
non-sinistre reste `enregistre`), pint propre, 0 écart de traduction (369
appels __() audités).

---

## [2026-09-08] Motif d'un renvoi pour correction affiché en tête de fiche
Contexte : demande explicite de l'utilisateur — un collaborateur dont le
courrier revient (`renvoyerPourCorrection`, Module 4) doit voir tout de
suite le motif indiqué par le responsable, pas seulement le retrouver en
cherchant dans l'historique en bas de page.
Décision : bandeau `<flux:callout variant="warning">` en haut de
`ShowCourrier`, juste sous l'en-tête, affiché quand la DERNIÈRE entrée de
`$courrier->historiques` (déjà triée `latest('created_at')`, voir
`Courrier::historiques()`) a `action === 'validation_refusee'`. Aucun état
supplémentaire à gérer : dès que le collaborateur resoumet
(`soumettrePourValidation` crée une entrée plus récente), le bandeau
disparaît de lui-même au prochain rendu — le même mécanisme qui affiche le
motif le fait aussi disparaître automatiquement.
Alternatives envisagées : un champ dédié sur `courriers` (ex.
`dernier_motif_renvoi`) mis à jour à chaque renvoi/resoumission — écarté,
duplique une information déjà présente et déjà correctement horodatée dans
l'historique (Règle n°5), sans bénéfice réel ; se contenter de rendre
l'historique plus visible (le remonter en haut de page) — écarté, mélange
tout l'historique (affectation, traitement, etc.) avec l'information
précise recherchée ici, moins direct qu'un bandeau dédié au cas actif.
Vérifié : 263/263, pint propre, 0 écart de traduction (371 appels __()
audités). Deux tests dédiés : le motif est bien visible avec le nom du
responsable qui l'a écrit, et disparaît correctement une fois le courrier
resoumis.

---

## [2026-09-09] Import automatique depuis un dossier surveillé (ScanPremier)
Contexte : fonctionnalité explicitement mise en pause le 2026-09-04 ("ne le
fair pas encore" — un premier refactor avait été commencé puis intégralement
annulé à ce moment-là) ; l'utilisateur a demandé de la construire
maintenant. Besoin : la réceptionniste scanne avec l'appli de son scanner
(qui enregistre un fichier dans un dossier local), puis devait jusqu'ici
uploader ce fichier manuellement à chaque fois — elle veut autoriser
l'accès à ce dossier UNE SEULE FOIS et que chaque nouveau fichier y soit
envoyé automatiquement, sans repasser par un sélecteur ni être redirigée à
chaque scan.
Décision technique clé — vérifiée dans le vrai code vendor avant toute
implémentation, pas supposée : `vendor/livewire/livewire/dist/livewire.esm.js`
expose `$wire.upload(nom, file, ...)`, une API JS publique qui accepte
N'IMPORTE QUEL objet `File`/`Blob`, pas seulement celui d'un vrai
`<input type="file">`. Un `File` obtenu via `FileSystemFileHandle.getFile()`
(API File System Access du navigateur, Chromium uniquement — Chrome/Edge,
non disponible sur Firefox/Safari/mobile, limite acceptée) peut donc lui
être passé directement, et `$wire.methodePublique()` appelle n'importe
quelle méthode Livewire publique comme une fonction async — AUCUNE nouvelle
route/contrôleur d'upload n'a été nécessaire, tout réutilise le mécanisme
d'upload temporaire déjà intégré à Livewire.
Architecture : `ScanPremier::numeriser()` scindé en logique partagée
(`creerBrouillonDepuisDocument()` — authorize/validate/stockage S3/jobs,
reproduisant proprement l'essai annulé du 2026-09-04) + deux points
d'entrée : `numeriser()` (inchangé, toast + redirection) et
`numeriserAutomatique()` (nouveau, `#[Renderless]`, ni toast ni redirection
— un scan en rafale ne doit ni spammer de toasts ni recharger la page à
chaque fichier, l'état visible est géré côté client par un journal
d'activité Alpine). `authorize()`/`validate()` tournent dans le helper
partagé, donc systématiquement revérifiés côté serveur quel que soit le
point d'entrée (Règle n°6). Nouveau fichier `resources/js/scan-watcher.js`
(entrée Vite, même découpage que le précédent `passkeys.js` : fonctions
utilitaires sur `window.ScanWatcher` + composant Alpine `surveillanceDossier`
enregistré via `alpine:init`) gère : détection de compatibilité, persistance
IndexedDB (`gec-scan-watcher`, stores `dossier` et `fichiers` avec purge à
90 jours), sondage du dossier toutes les 4s, vérification de stabilité
(taille identique sur 2 cycles avant upload, évite un fichier à moitié
écrit), traitement séquentiel (jamais concurrent — `document` est une seule
propriété, pas un tableau), et distinction 403 (droits perdus → pause
totale) / 422 (fichier invalide → marqué "traité" pour ne jamais le
retenter en boucle, motif affiché) / échec réseau opaque (jamais marqué
"traité", retenté au cycle suivant). Composant Alpine défini dans le
fichier JS plutôt qu'inline dans le Blade (`x-data="{ ... }"`) : les
messages traduits contiennent des apostrophes, et `@js()` les encode en
JSON avec des guillemets doubles — incompatible avec un attribut HTML
`x-data` lui-même délimité par des guillemets doubles ; les traductions
passent donc par des attributs `data-*` (échappement HTML normal, aucun
conflit), lus une fois dans `init()`. Reprise après rechargement de page :
JAMAIS de reprise automatique silencieuse même si `queryPermission()`
répond déjà `'granted'` — toujours un bouton explicite ("Reprendre la
surveillance") pour que `requestPermission()` s'exécute depuis un vrai
geste utilisateur (comportement navigateur plus fiable ainsi).
Corrigé au même moment (confirmé avec l'utilisateur, scope creep assumé) :
`config/livewire.php` publié pour relever la limite d'upload temporaire de
Livewire de 12 Mo (défaut vendor, jamais remarqué jusqu'ici) à 20 Mo, alignée
sur la règle déjà validée par `ScanPremier`. Sans ce correctif, un fichier
de 13-19 Mo échouait TOUJOURS avant même d'atteindre la règle du composant
— bug préexistant au scan manuel, mais qui serait devenu bien plus gênant
en boucle automatique (un fichier structurellement trop gros aurait bouclé
en échec indéfiniment).
`setInterval` de 4s côté client vs. l'interdiction du `wire:poll` (Règle
n°2) : jugé hors du périmètre visé par cette règle — le sondage ne fait
qu'un listage LOCAL du dossier (zéro réseau) à chaque tick ; le serveur
n'est sollicité que pour un fichier réellement nouveau et stable, quelques
dizaines par jour au maximum, jamais à intervalle fixe indépendamment de
tout changement réel (ce que la Règle n°2 vise à éviter, "à l'échelle").
Alternatives envisagées : une nouvelle route/contrôleur d'upload dédié —
écarté, `$wire.upload()` couvre exactement ce besoin sans rien construire
de nouveau. Reprise automatique silencieuse au rechargement — écarté,
`requestPermission()` est documenté comme plus fiable depuis un vrai geste
utilisateur. Ajout d'un outil de test navigateur (Dusk/Pest browser
testing) pour couvrir la couche JS — explicitement écarté de ce
changement, aucun outil de ce type n'existe dans le projet
(`package.json`/`composer.json` vérifiés) ; décision séparée si un besoin
réel se présente.
Vérifié : 267/267 (4 nouveaux tests sur `numeriserAutomatique()` — pas de
redirection contrairement à `numeriser()`, valeur de retour pour le JS,
autorisation refusée, validation refusée — les 3 tests existants sur
`numeriser()` inchangés), pint propre, 0 écart de traduction (390 appels
__() audités), `npm run build` confirmé (nouveau chunk `scan-watcher-*.js`
généré). **Non vérifié** : le comportement réel dans un navigateur
(`showDirectoryPicker()`, IndexedDB, la boucle de sondage elle-même) — API
navigateur, PHPUnit n'a aucun DOM ; à vérifier manuellement en conditions
réelles (Chrome/Edge) par l'utilisateur, même limite déjà rencontrée pour
la barre d'outils PDF.js du 2026-09-07/08.

**Mise à jour [2026-09-09]** — deux ajustements trouvés en testant en
conditions réelles avec l'utilisateur :
1. Le site n'était servi qu'en HTTP (`http://gecs.test`) — l'API File
   System Access exige un contexte sécurisé et refusait silencieusement de
   fonctionner. `herd secure gecs.test` a activé HTTPS ; `APP_URL` (resté
   sur l'ancien défaut `http://localhost:8000`, jamais aligné) corrigé au
   passage.
2. Retour direct de l'utilisateur : le journal ne montrait un fichier
   qu'une fois importé/rejeté — rien pendant qu'il était détecté/en attente
   de stabilité. `journal` restructuré en objet `{nom: {statut, raison}}`
   reflétant l'état COURANT de chaque fichier VU dans le dossier (affiché
   dès la détection, tout de suite, statut "en attente" par défaut) plutôt
   qu'un historique d'évènements passés déjà terminés — corrige un vrai
   décalage entre le comportement construit et l'attente de l'utilisateur
   ("shouldn't it automatically search for the document and display them"),
   pas juste un bug.

**Mise à jour [2026-09-09] — root cause trouvée pour `$wire` appelé depuis
la boucle de sondage.** Deux symptômes distincts observés en testant avec
l'utilisateur avaient la MÊME cause, identifiée en lisant directement
`vendor/livewire/livewire/dist/livewire.esm.js` (pas supposée) :

- `$wire.upload('document', file, ...)` appelé depuis la boucle Alpine ne
  produisait JAMAIS de requête réseau (confirmé onglet Réseau des
  DevTools), alors que le même mécanisme déclenché par un vrai clic
  utilisateur fonctionnait parfaitement.
- Une fois l'upload contourné (voir `televerser()` plus bas — input caché
  + `DataTransfer` + évènement `change` natif, le même chemin que le
  formulaire manuel), `$wire.numeriserAutomatique()` renvoyait `undefined`
  au lieu d'une Promise, systématiquement, à chaque cycle.

**Cause réelle** (ligne ~13592 du fichier vendor) : le magic Alpine
`$wire` résout le composant Livewire via `findComponentByEl(el)` à CHAQUE
accès à `this.$wire`, avec un `try/catch` qui avale silencieusement
l'exception et renvoie un no-op `() => {}` si `el` n'est plus rattaché à
un composant suivi par Livewire (typiquement après un morph qui remplace
le nœud DOM d'origine par un nouveau). Ce no-op passe le test
`typeof this.$wire.numeriserAutomatique === 'function'` (c'est bien une
fonction) mais renvoie `undefined` à l'exécution — exactement les deux
symptômes observés, jamais une vraie erreur JS explicite.

**Correctif** : `window.Livewire.find(id)` (exposé globalement,
`vendor/livewire/livewire/dist/livewire.esm.js:14238`) consulte un
registre global de composants par id STABLE, sans jamais dépendre d'un
élément DOM mis en cache. `this.$wire.$id` est capturé UNE SEULE FOIS
dans `init()` (moment où `$wire` est confirmé fiable) et stocké dans
`wireId` ; `creerBrouillon()` fait désormais
`window.Livewire.find(wireId).numeriserAutomatique()` au lieu de recevoir
et réutiliser l'objet `$wire` d'Alpine. **Leçon pour tout futur appel
`$wire.*` depuis un contexte JS longue durée (boucle `setInterval`,
callback asynchrone tardif) dans ce projet : ne jamais garder une
référence à `this.$wire` au-delà de l'appel immédiat qui l'a résolue —
capturer `$id` une fois et repasser par `window.Livewire.find(id)`.**
`televerser()` (upload via input caché + `DataTransfer`) reste inchangé —
il fonctionne déjà et ne dépend plus de `$wire` du tout.

**Confirmé en conditions réelles [2026-09-09]** : après ce correctif, les 6
fichiers de test déposés dans le dossier surveillé ont tous été importés
automatiquement de bout en bout (détection → upload → `CourrierBrouillon`
créé), vérifié directement en base (`courrier_brouillons` ids 19-24, un
toutes les ~6-7 secondes, cohérent avec un traitement séquentiel réel).
Fonctionnalité considérée terminée. Instrumentation `console.log`/
`console.error` de diagnostic retirée de `scan-watcher.js` une fois la
cause confirmée ; conservés uniquement les logs à valeur de diagnostic
permanent (erreur de cycle inattendue, composant Livewire introuvable,
échec réseau/opaque avant nouvel essai).

## [2026-09-09] Watcher automatique sur le formulaire d'enregistrement

**Contexte** : l'utilisateur prévoit de restreindre/retirer bientôt la page
"Numériser un nouveau courrier" (`ScanPremier`) pour les agents — lui-même,
en tant qu'admin, configurera le dossier surveillé une bonne fois pour
toutes. Le flux visé pour un agent devient : il scanne un document avec le
scanner physique (qui dépose le PDF dans le dossier surveillé), le document
est importé automatiquement en arrière-plan pendant qu'il est sur le
formulaire d'enregistrement (`RegistrationForm`), et il le choisit dans une
liste déroulante — le même pré-remplissage automatique qu'avant
(`?brouillonId=`, déjà en place), juste déclenché depuis un select plutôt
qu'un lien. Deux manques comblés par ce changement : (1) la surveillance ne
tournait que sur la page `ScanPremier`, que l'agent ne visitera plus ; (2)
elle exigeait un clic "Reprendre la surveillance" à chaque chargement de
page, alors que la demande explicite est "sans avoir à cliquer à nouveau".

**Décision** :
1. `ScanPremier::creerBrouillonDepuisDocument()` extraite vers
   `app/Services/BrouillonScanService.php` (nouveau, même pattern que
   `PieceJointeService`/`ClassificationService`/`NumeroReferenceGenerator`) :
   `reglesValidation(int)` (règles + closure de contrôle de résolution,
   partagées) et `creer(UploadedFile)` (stockage S3, `ReplicateFichierJob`,
   `CourrierBrouillon::create`, `ProcessBrouillonOcr`). `authorize()`/
   `validate()` restent dans CHAQUE composant Livewire appelant (Règle
   n°6 — le service n'est pas un point d'entrée qui contournerait ces
   contrôles), seule la logique de création/stockage est partagée.
   `ScanPremier` comportement inchangé (délègue au service), 7/7 tests
   toujours verts sans modification.
2. `RegistrationForm` gagne `public $document` + `numeriserAutomatique()`
   (même forme que `ScanPremier`), alimentée par le MÊME
   `resources/js/scan-watcher.js` (déjà générique — ne connaît que
   `wireId`/l'id de l'input caché) rendu sur `registrationForm.blade.php`.
   Contrairement à `ScanPremier`, **PAS** `#[Renderless]` : ici le nouveau
   brouillon doit apparaître dans la liste déroulante tout de suite (raison
   d'être de la fonctionnalité sur cette page) — un re-render Livewire
   classique ne perd aucune saisie en cours (les valeurs de `$form` déjà
   tapées voyagent avec chaque requête, renderless ou non, et sont
   réappliquées au rendu).
3. **Reprise automatique sans clic** (`scan-watcher.js::init()`) :
   `handle.queryPermission({mode:'read'})` ne nécessite PAS de geste
   utilisateur (contrairement à `requestPermission()`), donc appelable
   silencieusement à chaque chargement de page. Si déjà `'granted'` (cas
   normal — même profil/poste qu'une session précédente), `demarrer()`
   directement, sans bouton. Sinon, `'a_reprendre'` reste le repli (un clic
   redevient incontournable, `requestPermission()` l'exige). Remplace la
   décision du 2026-09-09 plus haut ("JAMAIS de reprise automatique
   silencieuse") — assouplie suite à une demande explicite de
   l'utilisateur ; bénéficie aussi à `ScanPremier` (moins de clics pour
   l'admin), pas seulement à `RegistrationForm`.
4. **UI volontairement minimale sur `RegistrationForm`** (confirmé via
   AskUserQuestion) : pas le panneau complet de `ScanPremier` (badge/
   compteurs/journal par fichier), juste une ligne discrète
   (`<flux:badge>` "Import automatique actif") pendant que ça tourne, et
   une courte mention neutre sans bouton si interrompu (droits révoqués en
   cours de route — l'agent ne peut de toute façon pas redonner la
   permission depuis cette page). **Si le dossier n'a jamais été autorisé
   sur ce poste/navigateur, rien n'est affiché à l'agent** (pas de bouton
   "Choisir un dossier" ici, confirmé via AskUserQuestion) — seul l'admin
   configure, via `ScanPremier` qui reste inchangé et accessible.
5. La section "Autres documents scannés en attente" (liste de
   `<flux:link>`) devient un `<flux:select>` — options = `$this->brouillonsEnAttente`
   (computed existant, inchangé : agent courant, borné à 20). Sélection →
   `x-on:change="Livewire.navigate($event.target.value)"`, chaque option
   `value` = `route('courriers.nouveau', ['brouillonId' => $id])` —
   réutilise EXACTEMENT le chemin de pré-remplissage existant
   (`RegistrationForm::mount()` → `preremplirDepuisBrouillon()`), aucun
   nouveau code de pré-remplissage, aucun risque de divergence entre "clic
   sur un lien" (avant) et "choix dans un select" (ce changement).

**Précision technique (persistance de la permission)** : l'autorisation du
dossier (`FileSystemDirectoryHandle` + permission navigateur) est stockée
dans IndexedDB, par ORIGINE ET par PROFIL NAVIGATEUR/POSTE, jamais
partagée entre postes. Le scanner physique étant forcément branché sur le
poste de la réceptionniste, c'est CE poste-là (pas un poste "admin"
séparé) qui doit recevoir le clic initial "Choisir un dossier" — via
`ScanPremier`, une fois, sur ce poste précis. Une fois fait, la reprise
devient automatique sur `ScanPremier` ET `RegistrationForm` dans ce même
navigateur, sans autre clic.

**`ScanPremier` : intouché sur le fond** — aucune restriction d'accès ni
suppression décidée ici ; l'utilisateur gère ça lui-même plus tard.

Vérifié : 270/270 tests (4 nouveaux sur `RegistrationForm::numeriserAutomatique()` —
nominal/valeur de retour/validation refusée ; pas de test dédié "non
autorisé" ici, déjà couvert par `mount()` qui authorize() avant même
d'atteindre la méthode, contrairement à `ScanPremier`), pint propre, 0
écart de traduction (395 appels `__()` audités), `npm run build` confirmé.
**Non vérifié en navigateur réel à l'écriture de cette entrée** : la
reprise silencieuse et le select — à confirmer par l'utilisateur en
conditions réelles, même limite déjà rencontrée pour chaque couche
JS/navigateur de ce projet (aucun outil de test browser installé).

**Mise à jour [2026-09-09] — "form is not pre remplis anymore" : ni un bug
de ce changement, ni du JS.** Diagnostic en conditions réelles avec
l'utilisateur : la navigation du select fonctionnait bien (`?brouillonId=`
apparaissait correctement dans l'URL, le bandeau "Document déjà scanné"
s'affichait), et le pré-remplissage serveur a été reconfirmé fonctionnel
via un test isolé (`Livewire::test(..., ['brouillonId' => ...])`) — donc
aucune régression de code. Cause réelle, en deux temps :
1. **Aucun worker de queue ne tournait** (`tasklist` : 0 processus
   `php.exe`) — les jobs `ProcessBrouillonOcr` dispatchés restaient
   indéfiniment `non_traite`, donc rien à pré-remplir.
2. **MinIO non plus** (`cURL error 7: connection refused` sur
   `127.0.0.1:9000`) — les 6 brouillons de test du 2026-09-09 (import
   automatique) et 3 autres plus anciens (16/17/18) n'avaient en réalité
   JAMAIS eu de fichier persisté dans le bucket `gec`
   (`Storage::disk('s3')->exists()` → `false` pour les 9), donc
   `ocr_statut = echec` permanent (3 tentatives épuisées) — aucun de ces 9
   brouillons ne pourra jamais se pré-remplir, peu importe le code.
   Supprimés après confirmation de l'utilisateur (AskUserQuestion) : ce
   sont des artefacts de test de cette session, pas des données réelles.

**Rappel d'environnement local (pas une décision de code, mais un piège
répété plusieurs fois cette session)** : sur ce poste, `php artisan
queue:work` ET MinIO (`C:\Users\NSIANV-EDS-ANK\minio\minio.exe`, voir
ARCHITECTURE.md) sont deux processus autonomes qui ne survivent PAS à un
redémarrage/veille — aucun des deux n'est un service Windows. Avant tout
diagnostic "l'OCR/le stockage ne marche pas" sur ce projet : vérifier
`tasklist /FI "IMAGENAME eq php.exe"` et `/FI "IMAGENAME eq minio.exe"`
AVANT de soupçonner le code, à égalité avec la vérification HTTPS déjà
documentée plus haut pour le dossier surveillé.

## [2026-09-09] Statut OCR de la liste déroulante mis à jour en direct (sans recharger)

**Contexte** : retour explicite de l'utilisateur — l'import d'un nouveau
document dans le dossier surveillé apparaît déjà en direct dans la liste
déroulante (grâce au re-render non-Renderless de `numeriserAutomatique()`,
voir entrée précédente), mais le STATUT OCR affiché à côté
(`en_attente`/`en_cours`/`reussi`/`echec`) restait figé sur sa valeur au
moment du chargement de page — "it is only when i refresh the page that
it changes status".

**Décision** : réutilisation de l'infrastructure Reverb/Echo déjà en place
(`BrouillonOcrTermine`, `ProcessBrouillonOcr::notifier()`, Règle n°1/n°2 —
jamais de `wire:poll`) plutôt qu'un nouveau mécanisme. Le canal de
diffusion change de PAR BROUILLON (`brouillon.{brouillonId}`, décision
initiale du flux scan-first) à PAR AGENT (`App.Models.User.{creeParId}`,
canal déjà défini/autorisé dans `routes/channels.php` pour les
notifications standard Laravel) : `RegistrationForm` doit réagir à la fin
d'OCR de N'IMPORTE LEQUEL des brouillons en attente de l'agent (toute la
liste déroulante), pas seulement celui actuellement sélectionné — un seul
canal/listener par agent couvre les deux cas (bandeau du brouillon
sélectionné ET liste) plutôt qu'un abonnement par brouillon qui ne
couvrirait que le premier cas.
- `BrouillonOcrTermine` : nouveau paramètre `creeParId`, `broadcastOn()`
  renvoie `App.Models.User.{creeParId}` au lieu de `brouillon.{brouillonId}`.
- `ProcessBrouillonOcr::notifier()` passe `$this->brouillon->cree_par_id`.
- `routes/channels.php` : canal `brouillon.{brouillonId}` retiré (plus
  aucun consommateur après ce changement) ; le canal `App.Models.User.{id}`
  existait déjà (convention Laravel par défaut), réutilisé tel quel.
- `RegistrationForm` : nouvelle propriété `agentId` (capturée dans
  `mount()` depuis `Auth::id()`, sert uniquement au placeholder
  `{agentId}` de l'attribut `#[On(...)]` — même pattern que `{brouillonId}`
  déjà utilisé). Le handler `brouillonOcrTermine()` invalide maintenant
  `brouillon` ET `brouillonsEnAttente` (avant : seulement `brouillon`).

Vérifié : 2 nouveaux tests (statut d'un AUTRE brouillon en attente qui se
met à jour dans la liste ; bandeau du brouillon sélectionné qui se met
aussi à jour), appelant directement `brouillonOcrTermine()` (l'infra
Echo/Reverb elle-même n'est pas testable en PHPUnit — même limite que le
reste du temps réel de ce projet). pint propre, 272/272 tests. Un premier
essai de test a révélé un faux positif instructif : un nom de fichier de
test "en-cours.pdf" collisionnait avec le texte statique "Envoi en cours…"
(indicateur `wire:loading` du champ pièce jointe, sans rapport) —
corrigé en cherchant `"(en cours)"` avec parenthèse (format exact rendu
par le select) plutôt que la sous-chaîne nue.
**Non vérifié en navigateur réel à l'écriture de cette entrée** — à
confirmer par l'utilisateur (MinIO + queue worker maintenant actifs).

## [2026-09-09] Liste des brouillons en attente : Administrateur voit tous les agents

**Contexte** : l'utilisateur a testé le nouveau select sous un compte
Administrateur — vide, alors qu'un brouillon existait bel et bien pour un
autre agent. Question posée directement : "wait shouldn't the
administrator see all". En vérifiant `CourrierBrouillonPolicy::utiliser()`
(Module 1/2, "panier personnel") : `$user->profil?->nom === 'Administrateur'
|| $user->id === $brouillon->cree_par_id` — un Administrateur pouvait déjà
UTILISER n'importe quel brouillon (ex. via `?brouillonId=` en direct), mais
`RegistrationForm::brouillonsEnAttente()` filtrait quand même
inconditionnellement sur `cree_par_id = Auth::id()`, pour tout le monde —
un admin ne pouvait donc jamais DÉCOUVRIR ces brouillons dans la liste
déroulante, seulement les utiliser s'il devinait/recevait l'ID. Incohérence
réelle entre la Policy et la requête de listing, pas un choix voulu.

**Décision** : `brouillonsEnAttente()` retire le filtre `cree_par_id` pour
un Administrateur (même condition que la Policy), et affiche le nom de
l'agent créateur à côté de chaque document (`creePar->name`) pour les
distinguer — sans ça, plusieurs agents ayant scanné un fichier au nom
similaire seraient indiscernables dans la liste d'un admin. La relation
`creePar` n'est chargée QUE dans le cas Administrateur (Règle n°3 — un
agent normal, qui ne voit que les siens, n'en a pas besoin, jamais de
requête N+1 pour lui).

**Alternatives envisagées** : limiter l'accès admin à un futur écran dédié
plutôt que ce select — écarté, la liste existe déjà précisément pour ce
flux (scan-first), dupliquer l'UI ailleurs aurait été plus de travail pour
un gain nul ; la Policy autorisant déjà l'usage, la cohérence minimale est
que la découverte suive la même règle.

Vérifié : nouveau test (`test_un_administrateur_voit_les_brouillons_de_tous_les_agents`),
pint propre, 274/274 tests.

## [2026-09-10] Trois bugs réels corrigés dans le flux scan-first (revue de code)

**Contexte** : l'utilisateur a demandé une revue de code générale
("passe en revue le code pour denicher the bug et auttre") sur les
fichiers du flux scan-first/dossier surveillé, via le skill `code-review`
(pas de dépôt git dans ce projet, revue ciblée sur les fichiers listés
plutôt qu'un diff). 8 constats remontés ; l'utilisateur a confirmé
corriger les 3 jugés les plus sûrs/confirmés (les 5 autres — deux
conditions de course JS, un fichier orphelin sur le disque de secours, le
plafond de 20 éléments désormais partagé entre tous les agents pour un
admin, un dépassement de date Carbon silencieux — restent ouverts,
documentés dans le journal de développement, pas dans ce fichier tant qu'ils ne
sont pas corrigés).

**Décision 1 — fuite d'objet confidentiel** : `preremplirDepuisBrouillon()`
pré-remplit l'objet depuis le texte OCR pendant `mount()`, AVANT que
l'agent ait choisi la Confidentialité. L'ancien garde-fou de
`updatedFormConfidentialite()` ("écraser seulement si vide", décision du
2026-09-07) ne s'appliquait donc jamais à un objet extrait par OCR — la
règle métier "l'objet réel du courrier n'est jamais connu à ce stade pour
un courrier confidentiel" était donc violée dans le cas le plus courant du
flux scan-first. Corrigé : nouvelle propriété `objetProposeParOcr`
(la valeur exacte proposée par l'OCR) ; l'objet est écrasé s'il est vide
OU s'il est ENCORE strictement égal à cette proposition — jamais si
l'agent l'a modifié depuis, préservant le comportement déjà voulu pour une
saisie manuelle.

**Décision 2 — historique manquant à la finalisation** : `finaliserBrouillon()`
déplaçait le fichier et copiait les champs OCR vers le `Courrier` sans
jamais créer d'entrée `courrier_historiques`, contrairement à
`ScanForm::numeriser()` (action `'numerisation'`) pour la même opération
via le flux classique. Violation directe de la Règle n°5 ("toute action
sur un courrier doit créer une entrée d'historique immuable") — chaque
courrier créé via scan-first depuis le 2026-09-04 (date d'introduction du
flux scan-first) n'a donc aucune trace de sa numérisation. Corrigé pour
les finalisations futures ; pas de rattrapage rétroactif des courriers
déjà finalisés (hors périmètre de cette correction, à traiter séparément
si jugé nécessaire).

**Décision 3 — garde-fou de finalisation incomplet** : `enregistrer()` ne
bloquait la finalisation que sur `ocr_statut === 'en_cours'`, pas sur
l'état initial `non_traite` (avant même que `ProcessBrouillonOcr` démarre
— ex. queue en retard/worker arrêté, incident réel déjà rencontré cette
session). Un brouillon pouvait donc être finalisé — sa ligne supprimée —
avant que le job OCR tourne ; celui-ci, une fois lancé, mettait alors à
jour une ligne inexistante (0 ligne affectée, aucune erreur visible),
perdant le texte OCR et le numéro de tampon pour toujours. Corrigé en
bloquant aussi `non_traite`, seul autre état "pas encore terminé" de
l'énum `ocr_statut` (les deux autres, `echec`/`echec_qualite`, restent
volontairement non bloquants — un OCR raté ne doit pas empêcher
d'enregistrer le courrier).

Vérifié : 5 nouveaux tests, pint propre, 277/277 tests, 0 écart de
traduction (395 `__()` audités).

## [2026-09-15] Système de privilèges

**Contexte** : demande explicite de l'utilisateur — pouvoir créer des
privilèges (sans écrire de code) et les assigner à un profil et/ou à des
utilisateurs précis. Avant cette décision, TOUT le contrôle d'accès était
du PHP codé en dur : 3 Policy (`CourrierPolicy`, `CourrierBrouillonPolicy`,
`RegleClassementPolicy`) comparaient `$user->profil?->nom` à une chaîne
fixe parmi les 5 profils. Confirmé avec l'utilisateur (AskUserQuestion) :
retrofit COMPLET des 3 Policy (pas une couche en plus), plus une page
d'administration (pas juste le modèle de données).

**Décision — modèle de données** : `Privilege` (`cle` unique — identifiant
stable utilisé par le code, `nom`, `description`) + deux pivots,
`privilege_profil` et `privilege_user`. `User::hasPrivilege(string $cle)`
= union des privilèges du profil ET de ceux assignés individuellement
(additif uniquement, pas de "retrait" par utilisateur dans cette version —
non demandé). Mémoïsé via `once()` pour la durée de vie de l'instance PHP
(Règle n°3 — plusieurs abilities souvent vérifiées par page).

**Décision — retrofit** : chaque branche `match()`/condition PAR PROFIL
dans les 3 Policy devient un privilège séparé et assignable
indépendamment (ex. `CourrierPolicy::view()` scindé en `courriers.voir_tout`/
`voir_service`/`voir_propre`/`voir_affecte`/`voir_dga`, chacun combiné à
la MÊME condition de périmètre qu'avant — seul le "qui a le droit" devient
dynamique, pas le périmètre lui-même). 21 privilèges au total. Catalogue
et assignations par défaut : `database/seeders/PrivilegeSeeder.php` —
reproduisaient EXACTEMENT le comportement d'avant au moment du retrofit
(vérifié : les ~280 tests existants passent sans AUCUNE modification après
le retrofit, preuve que le comportement observable n'a pas changé).
`Tests\TestCase` sème désormais `ProfilSeeder`+`PrivilegeSeeder`
automatiquement pour tout test `RefreshDatabase` — sans ça, chaque
`Profil::firstOrCreate(['nom' => 'Agent'])` existant dans les tests aurait
créé un profil SANS AUCUN privilège, faisant échouer tout Policy check.

**Garde-fou anti-verrouillage** : `PrivilegePolicy::gerer()` vérifie
`$user->profil?->nom === 'Administrateur' || $user->hasPrivilege('privileges.gerer')`
— seule exception au retrofit complet, pour qu'un admin qui se retire ce
privilège par erreur depuis l'UI ne perde pas tout moyen de se le
redonner.

**Décision — page d'administration** (`/admin/privileges`,
`PrivilegeList.php` + vue, même schéma que `RegleList.php`) : créer/
modifier/supprimer un privilège, assigner à des profils (cases à cocher)
et/ou des utilisateurs individuels, le tout `authorize()` à chaque action
(pas seulement `mount()` — Règle n°6).

**Mise à jour [2026-09-15] — 3 raffinements demandés dans la foulée** :
1. **Administrateur reçoit TOUS les privilèges automatiquement**
   ("admin should have all the privileges") — le seeder ajoute
   Administrateur à chaque entrée du catalogue, y compris
   `courriers.archiver` (vrai changement de comportement assumé : cette
   action n'existe de toute façon pas encore ailleurs dans le code, donc
   sans effet réel pour l'instant).
2. **Assignation en masse** ("select multiple / bulk privi at a time...
   send to a profile") : le tableau des privilèges gagne une case à cocher
   par ligne + une barre d'action (choisir un profil, "Assigner") pour
   envoyer plusieurs privilèges à un profil en une seule fois. Additif
   (`syncWithoutDetaching`, jamais `sync`) — n'enlève jamais un privilège
   que ce profil avait déjà mais qui n'était pas coché.
3. **Vue "qui a quoi"** (clarifié via AskUserQuestion après un message
   ambigu) : nouvelle section listant chaque utilisateur avec ses
   privilèges EFFECTIFS (profil ∪ individuels combinés, même règle que
   `hasPrivilege()`), pour vérifier d'un coup d'œil sans rouvrir chaque
   privilège un par un.

Vérifié : 293/293 tests (13 nouveaux dans `PrivilegeListTest.php`, dont la
preuve bout en bout qu'un utilisateur SANS le bon profil mais avec un
privilège individuel passe quand même un vrai Policy check), pint propre,
0 écart de traduction (435 `__()` audités). Migrations + seeder appliqués
sur la base dev, vérifié manuellement (`admin@gec.test` a bien
`courriers.voir_tout`).

**Mise à jour [2026-09-15] — redesign en matrice** : retour utilisateur
("arrange that design what thats it ugly check for design online and do
it") — la page d'origine (formulaire "ouvrir un privilège → cocher 5
cases profil → enregistrer") était effectivement le mauvais pattern pour
ce type d'écran. Recherche rapide (WebSearch/WebFetch) confirmant l'usage
courant pour les écrans de gestion de permissions : une MATRICE (rôles en
colonnes, permissions en lignes, case cliquable — bascule immédiate),
regroupée par catégorie pour rester scannable, avec l'assignation
individuelle par utilisateur gardée à part (une matrice à dizaines de
colonnes-utilisateurs ne serait plus lisible). `basculerProfilPrivilege()`
(nouveau) attache/détache une seule case au clic, sans repasser par le
formulaire "Assignations" — celui-ci ne gère plus que les utilisateurs
individuels. L'assignation en masse et la vue "qui a quoi" du raffinement
précédent sont conservées à l'identique, juste réintégrées dans le nouveau
tableau. Vérifié : 294/294 tests, pint propre, 0 écart de traduction (433
`__()` audités).

**Mise à jour [2026-09-15] — la matrice cède la place à deux boîtes** :
retour direct de l'utilisateur — "no change that i want a simple like two
boxes one for all permission and the other one for affecting to the
profile selected or created". La matrice (mise à jour précédente) est
abandonnée : remplacée par une rangée de profils cliquables + un petit
formulaire pour en CRÉER un nouveau directement ici (aucune page de
gestion des `Profil` n'existait avant), puis deux boîtes — "Tous les
privilèges" (pas encore sur le profil choisi) et "Privilèges de {profil}"
(déjà dessus) — un clic déplace instantanément d'une boîte à l'autre.
L'assignation en masse et la matrice elle-même sont retirées (la logique
"cliquer pour déplacer" les rend redondantes). La liste CRUD (modifier/
supprimer un privilège), disparue par erreur en retirant le tableau de la
matrice, est réintégrée en liste compacte sous le formulaire de création.
Vérifié : 295/295 tests, pint propre, 0 écart de traduction (433 `__()`
audités).

**Mise à jour [2026-09-15] — formulaires en modales, vue utilisateur
tronquée** : demande explicite, "reduce the work — do the nuveau
privilege as a button displaing a modal and the profile button too then
display th users with thier profile not all like 2 to 3 then either plus
or... showing there are many only and action button ... to assign, view,
delete, modify users". Le formulaire "Nouveau privilège"/"Modifier" et
celui de création de profil sont déplacés dans une `<flux:modal>`,
déclenchée par un bouton (`<flux:modal.trigger>`) — la page n'affiche
plus en permanence un formulaire vide, seulement la liste + un bouton
d'action. `enregistrer()`/`creerProfil()` ferment leur modale à la fin
(`Flux::modal('...')->close()`) pour revenir directement à la liste mise
à jour. Dans le tableau "Utilisateurs et leurs privilèges", chaque ligne
n'affiche plus que les 3 premiers privilèges + un bouton "+N" si plus —
un clic sur "+N" ou sur un nouveau bouton "Voir" par ligne ouvre une
modale avec la liste complète. Interprétation du "assign/view/delete/
modify" demandé : ces actions existent déjà ailleurs sur la page (CRUD du
privilège = modifier/supprimer, panneau d'assignation individuelle =
assign) — seul "view" manquait, d'où un seul bouton ajouté plutôt que
quatre par ligne (cohérent avec le "reduce the work" explicite).

**Mise à jour [2026-09-15] — page "Profils" séparée de "Privilèges"** :
demande explicite, "seperate the profile page to privi[leges]", juste
après la mise à jour précédente. La page unique portait deux
responsabilités distinctes — gérer le CATALOGUE de privilèges (créer/
modifier/supprimer + assignation individuelle par utilisateur) et
ASSIGNER des privilèges à un PROFIL (choisir/créer un profil + les deux
boîtes). Scindées en deux composants/pages : `PrivilegeList`
(`/admin/privileges`) garde le catalogue + l'assignation individuelle +
la vue d'ensemble "Utilisateurs et leurs privilèges" ; `ProfilList`
(nouveau, `/admin/profils`) reprend telle quelle la logique "deux boîtes"
(aucun changement de comportement, seulement de composant). Même ability
Policy (`gerer` sur `Privilege`) pour les deux pages — pas de nouvelle
Policy, la gestion des profils reste un aspect du même système de
privilèges. Lien "Profils" ajouté dans la sidebar à côté de "Privilèges".
Vérifié : 298/298 tests (suite complète), pint propre, 0 écart de
traduction (448 `__()` audités).

**Mise à jour [2026-09-15] — les deux boîtes visibles par défaut sur
`/admin/profils`, sélecteur en menu déroulant** : demande explicite,
"make the twoxes appear by default but only the first bax to show all
priveleges the should be inside a bex/contener and the other one empty
until we select a profile from a select dropdown". Jusqu'ici, tant
qu'aucun profil n'était sélectionné, les deux boîtes étaient remplacées
par un simple message d'invite — désormais elles sont toujours affichées
: la boîte de gauche ("Tous les privilèges") montre le catalogue complet
par défaut (et se réduit aux privilèges non-assignés dès qu'un profil est
choisi, comme avant), la boîte de droite reste vide avec son propre
message tant qu'aucun profil n'est sélectionné. La rangée de boutons
profils est remplacée par un `<flux:select wire:model.live="profilSelectionneId">`
— plus adapté qu'une rangée de boutons si le nombre de profils grandit,
et cohérent avec le reste du formulaire (label "Profil" au-dessus).
`selectionnerProfil()` reste disponible (tests, éventuel futur usage
programmatique) mais l'UI n'en a plus besoin, le `wire:model.live` liant
directement la propriété. Vérifié : 299/299 tests, pint propre, 0 écart
de traduction (451 `__()` audités).

**Mise à jour [2026-09-15] — recherche par boîte + boîtes réduites** :
demande explicite, "the should be a search function inside the boxes and
it should be scrolleble and reduce the boxes". Deux propriétés de
recherche INDÉPENDANTES (`$rechercheDisponibles`/`$rechercheAssignes`,
une par boîte, pas un filtre global partagé) — les deux boîtes ont des
usages différents (parcourir tout le catalogue vs. vérifier les
privilèges déjà sur le profil), filtrer les deux avec la même valeur
n'aurait pas de sens. Filtre sur nom OU clé (insensible à la casse),
appliqué en mémoire sur la collection déjà chargée (`privileges` reste
une seule requête, pas de round-trip DB par frappe). Hauteur des listes
réduite de `max-h-96` à `max-h-64` — elles restaient déjà scrollables
(`overflow-y-auto`), la réduction ("reduce the boxes") compense l'espace
pris par le nouveau champ de recherche au-dessus de chacune. Vérifié :
300/300 tests, pint propre, 0 écart de traduction (455 `__()` audités).

**Mise à jour [2026-09-15] — sélection multiple + flèches centrales** :
demande explicite, "add fleches that we can select in bulk then send
them or one by [one] in the middle of the two boxes". Une case à cocher
par ligne dans chaque boîte + deux flèches dans une colonne centrale (une
par sens) qui déplacent TOUTE la sélection cochée en un seul appel
(`Profil::privileges()->syncWithoutDetaching()`/`->detach()` avec la
liste d'ids, pas une boucle). Les flèches par ligne existantes restent
disponibles pour le cas "un par un", inchangées. La sélection est vidée
dès qu'on change de profil (`updatedProfilSelectionneId()`) — les ids
cochés ne correspondraient plus aux bonnes listes sinon.

Important — en quoi ceci NE contredit PAS le refus antérieur du
"bulk-select + assigner en masse" (voir la mise à jour du redesign en
matrice, plus haut) : ce qui avait été explicitement écarté visait à
assigner UN privilège à PLUSIEURS profils/utilisateurs à la fois, depuis
un formulaire séparé de la logique deux-boîtes. Ici, la sélection
multiple déplace PLUSIEURS privilèges vers/depuis LE profil déjà
sélectionné, DANS la même paire de boîtes déjà validée — un
raffinement de ce design, pas une réintroduction du concept écarté, et
demandé explicitement par l'utilisateur. Vérifié : 303/303 tests, pint
propre, 0 écart de traduction (457 `__()` audités).

**Mise à jour [2026-09-15] — catalogue de `/admin/privileges` en table +
bouton "+" détails** : demande explicite, "priv too table plus button
priv showin modal" — clarifiée via AskUserQuestion (le catalogue de
`/admin/privileges`, PAS la boîte "Tous les privilèges" de
`/admin/profils`, qui n'a pas été touchée). La liste `<ul>` du catalogue
devient une `<table>` (même style que "Utilisateurs et leurs
privilèges" plus bas sur la même page) avec un bouton "+" par ligne qui
ouvre une modale `privilege-details` (nom, clé, description, compteurs
profils/utilisateurs via `withCount(['users', 'profils'])`, et un
raccourci "Modifier"). Cohérent avec le bouton "Voir" déjà présent sur
le tableau des utilisateurs — même logique de détail-à-la-demande
plutôt que tout afficher en permanence. Vérifié : 304/304 tests, pint
propre, 0 écart de traduction (464 `__()` audités).

**Mise à jour [2026-09-15] — page `/admin/utilisateurs` séparée de
`/admin/privileges`** : demande explicite, "seoerate the user
manage[m]ent from priv" — même logique que la séparation de "Profils"
plus tôt le même jour. `/admin/privileges` portait encore deux
responsabilités distinctes : le catalogue de privilèges ET la gestion
des privilèges individuels par utilisateur (panneau "Utilisateurs
individuels" dans la modale d'un privilège + vue d'ensemble "qui a
quoi"). Les deux sont déplacés dans un nouveau composant `UserList`
(`/admin/utilisateurs`), qui reprend le MÊME design "deux boîtes" que
`ProfilList` (recherche par boîte, sélection multiple + flèches
centrales, flèche par ligne) — cohérence entre les trois pages
d'administration du système désormais : `/admin/privileges` (catalogue),
`/admin/profils` (assignation par profil), `/admin/utilisateurs`
(assignation individuelle + vue d'ensemble). L'ancien mécanisme
("Modifier" un privilège → cocher des utilisateurs → "Enregistrer les
assignations") disparaît complètement, remplacé par
`ajouterPrivilegeAUtilisateur()`/`retirerPrivilegeDeLutilisateur()` (un
par un) et `ajouterSelectionAUtilisateur()`/`retirerSelectionDeLutilisateur()`
(en bloc) sur `User::privilegesDirectes()`. Vérifié : 313/313 tests, pint
propre, 0 écart de traduction (476 `__()` audités).

## [2026-09-10] Les 5 constats restants de la revue de code (suite explicite : "correct them too")

**Décision 4 — verrou inter-onglets pour le dossier surveillé** : deux
onglets ouverts sur le même navigateur (ex. ScanPremier + RegistrationForm
en même temps), surveillant le même dossier, pouvaient chacun constater
indépendamment "pas encore traité" avant que l'un des deux n'écrive sa
marque dans IndexedDB — uploadant le même scan deux fois. Choix :
`navigator.locks.request()` (Web Locks API), natif du navigateur,
disponible sur Chrome/Edge de longue date (bien avant l'API File System
Access déjà exigée par cette fonctionnalité) — pas de nouvelle dépendance,
pas de détection de compatibilité supplémentaire. Un second contrôle
"déjà traité" À L'INTÉRIEUR du verrou couvre la fenêtre entre le premier
contrôle et l'obtention du verrou.

**Décision 5 — fin de la course formulaire manuel/watcher** : le
formulaire manuel de `ScanPremier` (upload direct, propriété `$document`
partagée avec le watcher) restait visible/utilisable pendant la fenêtre
asynchrone de `init()` (ouverture IndexedDB + `queryPermission()`), avant
que l'état ne soit connu. Corrigé en masquant aussi le formulaire manuel
pendant l'état `chargement`, pas seulement `en_surveillance` — ferme la
fenêtre réelle décrite par la revue sans toucher au comportement des
autres états (le formulaire manuel reste un vrai repli utilisable dès que
la surveillance n'est PAS active).

**Décision 6 — nettoyage de la copie de secours à la finalisation** : le
brouillon est répliqué vers `s3_backup` dès sa création
(`BrouillonScanService::creer()`), mais `finaliserBrouillon()` ne
supprimait que la copie primaire — la copie de secours au chemin
`brouillons/...` restait orpheline pour toujours. Corrigé avec la même
garde que `ReplicateFichierJob` (rien si aucun disque de secours configuré,
`exists()` avant `delete()` si la réplication n'avait pas encore eu lieu).

**Décision 7 — plus de troncature silencieuse dans la liste des
brouillons** : le plafond de 20 (pensé à l'origine pour la liste
personnelle d'UN agent) s'applique désormais, depuis la décision du
2026-09-09, à TOUS les agents à la fois pour un Administrateur — un
dépassement masquait alors silencieusement les brouillons les plus
anciens, recréant le problème "courriers perdus ou oubliés" (PRD.md) que
cette liste existe pour éviter. Plutôt que construire une vraie pagination
pour un simple `<select>`, la limite est relevée à 50
(`RegistrationForm::LIMITE_BROUILLONS_EN_ATTENTE`) et un compteur
(`autresBrouillonsNonAffiches()`, requête séparée) affiche explicitement ce
qui dépasse — jamais invisible.

**Décision 8 — validation calendaire avant `Carbon::createFromDate()`** :
constaté que PHP/Carbon ne lève pas d'exception pour un jour hors plage
(31 avril, 30 février...) — la date déborde silencieusement sur le mois
suivant. Comme la date extraite du tampon est la SEULE proposition
automatique jamais signalée "à vérifier" (jugée fiable par design), une
lecture OCR erronée aurait pu enregistrer une date de réception fausse
sans aucun signal. Ajout de `checkdate()` avant construction, dans
`dateDepuisTampon()` ET `dateDepuisTexteCourrier()` (même faille dans les
deux méthodes de parsing de date de ce fichier) — retourne `null` en cas
d'échec, comportement déjà géré par tous les appelants (repli sur la
méthode suivante, ou champ laissé vide pour saisie manuelle).

**Non testable en PHPUnit** : décisions 4 et 5 (comportement JS pur —
verrouillage inter-onglets, minutage d'un état asynchrone) — ce projet n'a
aucun outil de test navigateur (pas de Dusk/Pest browser testing, pas de
Vitest/Jest). Vérifiées par lecture de code et `npm run build` uniquement ;
vérification réelle en navigateur (deux onglets ouverts simultanément)
laissée à l'utilisateur s'il souhaite confirmer.

Vérifié : 3 nouveaux tests (6, 7, 8), pint propre, 280/280 tests, 0 écart
de traduction.

---

## [2026-09-15] Synchronisation avec le nouveau document SRS-GEC.pdf

Contexte : le client a transmis une version mise à jour des spécifications
fonctionnelles (`SRS-GEC.pdf`), remplaçant l'ancienne base de
`specifications-modules-GEC.md`. Revue demandée explicitement par
l'utilisateur ("check new update"), puis mise à jour documentaire demandée
avant tout code ("yes update first") — conformément à la Règle du projet
selon laquelle toute décision d'architecture reste validée manuellement,
pas déléguée à l'agent : ce document liste ce qui change, PRD.md et
specifications-modules-GEC.md sont mis à jour en conséquence, mais AUCUN
CODE n'est modifié par cette entrée — le travail d'implémentation reste à
planifier/prioriser avec l'utilisateur.

**Déjà couvert, aucun changement de comportement nécessaire** :
- Abandon du tampon papier (acté le 2026-09-04, "Abandon de la piste lire
  le tampon") — le nouveau document confirme la même décision, avec plus
  de détails sur le mécanisme de repli (extraction par motifs structurels
  du corps du courrier).
- DGA/ADJ DGA comme profil et circuit de validation du service pour le
  courrier entrant (acté le 2026-09-08, "Circuit courrier entrant :
  validation DGA/ADJ du service").
- "Superviseur" = `Responsable de service` existant, pas un nouveau rôle
  (déjà résolu, voir notes internes).
- Règle d'affectation automatique par charge de travail, workflow
  générique par étapes, historique append-only, SLA par type — déjà dans
  l'architecture existante (stubs pour SLA/alertes, voir plus bas).

**Contredit le comportement actuellement implémenté — nécessite une
décision de conception avant codage** :
- Le champ **Service** doit être **entièrement retiré** du formulaire de
  l'agent/réceptionniste (`RegistrationForm`/`EditForm` ont aujourd'hui un
  `form.service_id` select REQUIS rempli par l'agent). Le circuit DGA du
  2026-09-08 ne fait que permettre au DGA de CHANGER un service déjà saisi
  par l'agent — ce n'est plus ce que demande le client : l'agent ne doit
  plus voir ce champ du tout, le DGA/ADJ DGA le choisit entièrement au
  moment de valider le transfert.
- Le transfert réceptionniste → DGA/ADJ DGA doit avoir **3 sous-statuts
  explicites** (en attente de transfert / en cours de transfert /
  transféré), visibles comme des onglets côté réceptionniste, avec
  verrouillage de la réceptionniste dès "Transféré". Le statut actuel
  (`en_attente_validation_dga`) est un seul statut, pas trois.

**Net nouveau — rien construit à date** :
- **Confidentialité numérique hiérarchique** (1, 2, 3, 4… au lieu de
  l'enum `normale`/`confidentiel`/`tres_confidentiel` actuel sur
  `courriers.confidentialite`) : chaque utilisateur a un niveau d'accès
  maximum, comparé au niveau du courrier, et la règle se cumule avec (pas
  remplace) le système de privilèges/permissions déjà construit. Doit
  bloquer l'ouverture même via un lien direct (email/notification), pas
  seulement filtrer les listes. C'est le changement le plus structurant de
  ce document — touche migration, `CourrierPolicy::view()`, et l'UI
  d'assignation du niveau par utilisateur.
- **Dossiers de classement** créés par un chef de service ou ses
  collaborateurs, avec permissions par dossier (table dossier × profil),
  cumulatives avec la confidentialité numérique ET les privilèges
  existants (3 règles qui se cumulent).
- **Décharge** (Module 9) : reçu généré à l'emprunt d'un original physique
  archivé, tracé dans l'historique du courrier.
- **Référence de localisation physique** par dossier de classement (ex.
  "Armoire A, Niveau 2"), affichée dans les résultats de recherche.
- **Hiérarchie de services à plusieurs niveaux** (sous-départements avec
  leur propre chef, ex. DSIN → Sinistre Auto/Santé) — `Service` n'a pas de
  `parent_id` aujourd'hui.
- **Sous-type "Contentieux"** du type Sinistre — champs `partie_a`/
  `partie_b` (nom, rôle) et référence de dossier sinistre lié, à prévoir
  dès la phase 1 comme colonnes optionnelles.
- **Cas particulier "courrier confidentiel"** : flux d'enregistrement
  minimal dédié (jamais scanné/ouvert, uniquement nom sur enveloppe, envoyé
  à RH ou DGA/ADJ DGA, génère un accusé de réception dédié) — distinct du
  simple champ `confidentialite` actuel sur un enregistrement normal.
- **Notifications configurables par privilège** (arrivée d'un courrier
  pour le chef de service, courrier traité) — Module 7 reste un stub vide
  (`SendMailAlertJob`/`CourrierEnRetardNotification` jamais câblés), donc
  ce point dépend de faire d'abord fonctionner les notifications elles-mêmes.
- **SLA ajustable par document individuel** par le chef de service, pas
  seulement par type globalement — `SlaCalculatorService::calculerStatutDelai()`
  reste un stub retournant toujours `'a_temps'`, aucun scheduler enregistré.
- **Configuration administrateur des listes de référence** (types de
  document, modes de réception, priorité, échelle de confidentialité,
  services/sous-services, profils) — toutes actuellement codées en dur
  (`CourrierForm::TYPES_DOCUMENT`, options `<flux:select.option>`, enums de
  migration) plutôt que gérables sans développeur, contrairement à
  l'exigence transversale confirmée par le client.

**Fichiers mis à jour par cette entrée** : `PRD.md` (section 1, points 1/2/3/4/8 —
tampon abandonné, service retiré du formulaire agent, confidentialité
numérique, dossiers de classement, sous-départements), et
`specifications-modules-GEC.md` (réécriture complète module par module
pour refléter `SRS-GEC.pdf`, avec mention explicite en tête de document du
remplacement de l'ancienne version).

**Non fait par cette entrée, volontairement** : aucun code, migration, ou
test modifié — l'implémentation de chaque point "net nouveau"/"contredit"
ci-dessus reste à prioriser/planifier avec l'utilisateur avant d'être
codée, plusieurs touchant des décisions d'architecture significatives
(notamment la confidentialité numérique, qui doit composer avec le
système de privilèges déjà en place — voir les notes internes).

## [2026-09-15] Module 1 — le service n'est plus saisi par l'agent pour un courrier entrant

Contexte : demande explicite de l'utilisateur, "start with module 1 with
the contradiction if there is any", suite à l'entrée précédente. La seule
contradiction directement liée au Module 1 était le champ Service :
`SRS-GEC.pdf` demande qu'il soit entièrement retiré du formulaire de
l'agent/réceptionniste pour un courrier entrant (le DGA/ADJ DGA le choisit
seul, au transfert), alors que le circuit DGA construit le 2026-09-08 se
contentait de laisser le DGA CHANGER un service déjà saisi par l'agent.

**Portée confirmée avec l'utilisateur (AskUserQuestion)** : uniquement le
courrier **entrant**. Le sortant garde son circuit actuel inchangé —
service toujours saisi et requis par l'agent à l'enregistrement, comme
avant. Choix explicite plutôt que d'étendre le circuit DGA au sortant
(qui n'a aucun mécanisme de validation de ce type aujourd'hui — aurait été
un chantier bien plus large que "retirer un champ").

**Blocage architectural découvert en creusant l'implémentation** (avant
tout code, conformément à la contrainte du projet) : le numéro de
référence (`GEC-{année}-{code service}-{séquence}`, compteur
`numero_sequences` par (année, service)) et le chemin de stockage
(`courriers/{année}/{service}/...`, Règle n°4) dépendaient TOUS LES DEUX
du service — généré immédiatement à l'enregistrement (Module 1, étape 4 :
"le système génère le numéro de référence unique"), donc AVANT que le DGA
n'ait jamais l'occasion de choisir un service. Retirer le champ sans
résoudre ce point aurait rendu l'enregistrement d'un courrier entrant tout
simplement impossible (aucun service pour générer numéro/chemin).

**Décision (confirmée avec l'utilisateur, AskUserQuestion)** : numéro de
référence **global par année**, format `GEC-{année}-{séquence}` (compteur
`numero_sequences` désormais keyé par `annee` seule,
`NumeroReferenceGenerator::generer()` sans paramètre `Service`). Chemin de
stockage : nouveau segment provisoire `_en_attente`
(`Courrier::segmentClassement()`, replie sur `service?->code` sinon)
utilisé partout où un chemin `courriers/{année}/{segment}/...` est
construit (`RegistrationForm::finaliserBrouillon()`, `PieceJointeService::attacher()`,
`ScanForm::numeriser()`) ; `WorkflowService::validerService()` déplace le
document principal ET toutes les pièces jointes de `_en_attente` vers le
vrai dossier service une fois le DGA passé (`Storage::move()`, y compris
la copie de secours `s3_backup` si configurée), et ne met `fichier_path`
à jour QUE si le déplacement a réellement réussi — un échec S3 laisse le
fichier accessible à son ancien chemin plutôt que de faire échouer la
validation du service elle-même (même esprit que
`RegistrationForm::finaliserBrouillon()`, non bloquant).

**Cas sinistre (routage direct, sans DGA)** : un courrier entrant identifié
comme sinistre continue de sauter la validation DGA (décision du
2026-09-08, inchangée) via la règle de classement Module 3 déjà existante
("sinistre" → DSIN). Cette résolution devait auparavant compter sur le
pré-remplissage du formulaire (`preremplirDepuisBrouillon()`, qui
peuplait `form.service_id` depuis la classification) — impossible
maintenant que le champ n'existe plus. `RegistrationForm::enregistrer()`
appelle donc `ClassificationService::classer()` de façon SYNCHRONE
juste avant la création, uniquement pour ce cas. **Garde-fou ajouté** :
si la classification ne résout aucun service (règle absente ou mal
configurée), le courrier retombe sur `en_attente_validation_dga` au lieu
de rester "enregistre" sans service assigné — jamais de courrier orphelin
(Module 6 : "un courrier a toujours un responsable identifié"), un
sinistre mal classé attend simplement une intervention DGA comme n'importe
quel autre courrier entrant.

**Historique de validation DGA reformulé** : le commentaire d'historique
("Service confirmé"/"Service choisi, différent de la proposition")
comparait auparavant `serviceId` fourni par le DGA à `courrier->service_id`
— pertinent quand ce dernier portait la saisie initiale de l'agent, mais
`service_id` vaut désormais TOUJOURS null avant validation. Comparé
maintenant à `courrier->service_propose_id` (la suggestion réelle du
Module 3), la seule référence encore significative pour distinguer "la
DGA a suivi la suggestion" de "la DGA a choisi autre chose".

**CourrierPolicy rendue null-safe** : `view()`/`affecter()`/`valider()`
comparaient `$courrier->service->responsable_id` sans opérateur null-safe
— un courrier entrant en attente de validation DGA (service_id null)
aurait fait planter ces vérifications pour un Responsable de service.
Corrigé en `$courrier->service?->responsable_id` (compare simplement à
`false` si aucun service, refuse l'accès proprement au lieu de planter).

Alternatives envisagées : garder le format de numéro par service et
assigner un service "placeholder" caché (ex. "En attente d'affectation")
pour ne pas casser le format existant — écarté avec l'utilisateur
(AskUserQuestion) : le numéro de référence ne doit jamais changer une
fois attribué (règle métier Module 1), donc le code service resterait
figé sur le placeholder même après la vraie affectation par le DGA,
un numéro trompeur en permanence plutôt qu'une seule fois au moment de
l'enregistrement.

Vérifié : 318/318 tests (nouveaux : routage sinistre par classification
synchrone + son filet de sécurité DGA, absence de service non bloquante
pour un entrant, numérotation globale par année, déplacement de fichiers
hors de "_en_attente" — document principal et pièce jointe — après
validation DGA), pint propre, 0 écart de traduction (476 `__()` audités).
Migration testée sur MySQL réel (voir aussi la mise à jour de la
migration `numero_sequences` rendue idempotente par étape après un premier
échec constaté avec des données de test déjà présentes en base dev).

## [2026-09-15] Module 1/4 — le transfert réceptionniste → DGA suit 3 sous-statuts explicites

Contexte : demande explicite de l'utilisateur ("ok now the new change of
module 1 impliment it"), la deuxième contradiction Module 1/4 identifiée
lors de la synchronisation SRS-GEC.pdf. Le document décrit 3 sous-statuts
distincts pour le transfert d'un courrier entrant vers le DGA/ADJ DGA —
« en attente de transfert » (rien fait), « en cours de transfert » (la
réceptionniste a cliqué « Transférer », en attente du DGA), « Transféré »
(le DGA a validé/accepté) — alors que le code n'avait qu'un seul statut
(`en_attente_validation_dga`), atteint automatiquement et silencieusement
à l'enregistrement, sans jamais d'action explicite « Transférer » de la
réceptionniste.

**Décision** : `en_attente_validation_dga` scindé en deux nouvelles
valeurs d'ENUM — `en_attente_de_transfert` et `en_cours_de_transfert`.
Le 3e sous-statut du document (« Transféré ») **n'est PAS une nouvelle
valeur** : il correspond au `enregistre` déjà existant (le moment où le
DGA valide le service et où le circuit habituel démarre) — cohérent avec
la décision du 2026-09-08 de ne jamais renommer une valeur d'ENUM déjà en
place. Nouvelle méthode `WorkflowService::transferer()` : la
réceptionniste clique un bouton « Transférer » (nouveau panneau sur
`ShowCourrier`), transition `en_attente_de_transfert` → `en_cours_de_transfert`,
tracée (`action: 'transfert'`) — `WorkflowService::validerService()`
(déjà existant, 2026-09-08) n'est désormais atteignable QUE depuis
`en_cours_de_transfert`, jamais avant. Nouvelle ability `CourrierPolicy::transferer()`,
même forme que `update()` (`courriers.transferer_tout`/`courriers.transferer_propre`,
ce dernier assigné à Agent par défaut dans `PrivilegeSeeder`). Le scoping
de la file DGA (`CourrierPolicy::view()`, `WorkflowQueue`, `CourrierList`)
passe de `en_attente_validation_dga` à `en_cours_de_transfert` : le DGA
n'a rien à faire tant que la réceptionniste n'a pas explicitement transféré.

**Migration en 3 étapes** (`2026_09_15_150000_...`) pour zéro perte sur
les lignes déjà en `en_attente_validation_dga` : élargir l'ENUM (ancien +
nouveaux), migrer les données existantes vers `en_cours_de_transfert`
(la valeur la plus proche de leur état réel — déjà "envoyées" côté
réceptionniste dans l'ancien système, puisqu'aucune étape "Transférer"
n'existait avant), puis rétrécir au jeu final.

**Bugs latents découverts et corrigés en marge** : plusieurs vues
(`courrierList.blade.php`, `workflowQueue.blade.php`, `showCourrier.blade.php`
×3, `pdf/bordereau.blade.php`) affichaient `$courrier->service->nom` sans
opérateur null-safe — un crash immédiat dès qu'un courrier entrant en
attente de transfert (donc `service_id` null, retrofit du 2026-09-15
précédent) apparaissait dans ces vues. Ces bugs existaient déjà depuis
l'entrée précédente mais n'étaient pas visibles tant qu'aucun test/usage
réel n'affichait un tel courrier dans CES vues précisément — trouvés en
implémentant le panneau "Transférer" et corrigés dans la foulée
(`$courrier->service?->nom ?? '—'` ou équivalent).

**Volontairement pas fait dans cette entrée** (scope ambigu, pas tranché
avec l'utilisateur) :
- **Verrouillage de l'édition une fois "Transféré"** — le document dit
  que la réceptionniste ne peut plus modifier le courrier une fois
  "Transféré". Non implémenté : une règle naïve ("modifier_propre
  seulement si statut ∈ {en_attente_de_transfert, en_cours_de_transfert}")
  casserait l'édition d'un courrier SORTANT (qui démarre directement à
  `enregistre`, jamais dans ces deux statuts) et d'un SINISTRE
  auto-routé (même chose) — deux cas où l'agent DOIT pouvoir continuer à
  corriger son propre courrier aujourd'hui. Une règle correcte devrait
  distinguer "ce courrier est PASSÉ par le circuit DGA" de "ce courrier
  n'a jamais eu de circuit DGA", ce qui n'est pas trivial avec le seul
  statut courant — à trancher avec l'utilisateur avant de coder.
- **Page dédiée "mes courriers" en onglets** — le document décrit les 3
  sous-statuts comme des "onglets" visibles côté réceptionniste. Question
  posée à l'utilisateur en cours de route : la page de recherche
  existante (`CourrierList`, déjà accessible à l'Agent, déjà scopée à ses
  propres courriers, déjà filtrable par statut) couvre le même besoin
  fonctionnel sans nouvelle page — `WorkflowQueue` ("à traiter") n'est PAS
  la bonne page, l'Agent n'y a pas accès (`courriers.voir_file_attente`
  n'inclut pas Agent). Décision de construire des onglets dédiés ou de
  rester sur le filtre existant : en attente du retour utilisateur.

Vérifié : 322/322 tests (nouveaux : transferer() unitaire + via
ShowCourrier, garde-fou "un autre agent ne peut pas transférer le
courrier d'un collègue", double dataset de transition invalide pour les
deux nouveaux statuts), pint propre, 0 écart de traduction (482 `__()`
audités). Migration appliquée avec succès sur la base dev (MySQL réel).

## [2026-09-15] Module 1/4 — transfert en masse depuis "Rechercher un courrier"

Contexte : demande explicite de l'utilisateur ("where can i see all
receptionist register doc enttand and with buttons for select bulk and
transfere to a supervisor") — répond directement à la question laissée
ouverte dans l'entrée précédente ("page dédiée en onglets ou filtre
existant ?"), et ajoute une sélection multiple + transfert groupé, absents
jusqu'ici (`WorkflowService::transferer()` ne traitait qu'un courrier à
la fois).

**Décision — pas de nouvelle page** : `CourrierList` ("Rechercher un
courrier") reçoit la fonctionnalité plutôt qu'une page dédiée. Cette page
est déjà accessible à l'Agent/réceptionniste et déjà scopée à SES PROPRES
courriers (`resultats()`, filtre par `historiques.action = 'creation'`) —
il ne manquait qu'un moyen d'agir en masse dessus, pas une nouvelle
source de données. `WorkflowQueue` ("à traiter") reste écartée pour ce
rôle : l'Agent n'y a structurellement pas accès
(`courriers.voir_file_attente` ne lui est jamais assigné).

**Mécanique** : une case à cocher apparaît sur chaque ligne dont le statut
est `en_attente_de_transfert` ET que l'utilisateur a le droit de
transférer (`CourrierPolicy::transferer()`, revérifié à l'affichage ET à
l'action — jamais une case cochable qui mènerait à un refus silencieux).
Un bouton "Transférer la sélection (:n)" apparaît au-dessus du tableau,
avec confirmation (`wire:confirm`), désactivé tant que rien n'est coché.
`CourrierList::transfererSelection()` reboucle sur `$this->selection`
mais **revérifie chaque id individuellement** contre la base (statut
actuel + Policy) avant d'agir — Règle n°6 : jamais fait confiance à la
simple présence d'un id dans la sélection postée, un id périmé (déjà
transféré par une requête concurrente, courrier d'un autre agent malgré
tout injecté côté client) est silencieusement ignoré plutôt que de faire
échouer tout le lot ou, pire, d'agir sans droit. La sélection est vidée à
chaque changement de filtre/page (`updated()`) — des ids cochés sur une
page qui n'est plus affichée n'ont plus de sens visuel.

**Pattern Flux réutilisé sans redérive** : cases à cocher enveloppées
dans `<flux:checkbox.group wire:model.live="selection">` — jamais un
`wire:model` direct sur une case isolée, l'erreur déjà commise et
documentée dans les notes internes (flèches de
sélection du système de privilèges, plus tôt le même jour).

Vérifié : 325/325 tests (nouveaux : transfert groupé réussi avec 2
courriers, courrier d'un autre agent ignoré sans faire échouer le reste,
courrier déjà `en_cours_de_transfert` ignoré silencieusement), pint
propre, 0 écart de traduction (488 `__()` audités).

**Mise à jour [2026-09-15]** : décision annulée quelques minutes plus
tard par une instruction explicite de l'utilisateur reçue en cours de
tâche suivante, "new page for receptionist" — le choix "pas de nouvelle
page" ci-dessus ne tient plus. `CourrierList` a été intégralement
reverté (retrait de `$selection`/`transfererSelection()`/de la colonne
checkbox — seules les 2 nouvelles options de filtre statut sont
conservées, indépendantes du transfert en masse). Voir la nouvelle entrée
"Module 1/4 — page dédiée réceptionniste 'Mes courriers'" ci-dessous pour
la décision qui remplace celle-ci.

## [2026-09-15] Module 1/4 — page dédiée réceptionniste "Mes courriers"

Contexte : suite directe de l'entrée précédente, annulée par l'instruction
explicite de l'utilisateur "new page for receptionist" reçue en cours de
tâche — l'utilisateur tranche la question laissée ouverte deux entrées
plus haut ("page dédiée en onglets ou filtre existant ?") en faveur des
onglets dédiés, plutôt que du filtre statut de `CourrierList`.

**Décision — nouveau composant `MesCourriers`**, route
`courriers/mes-courriers` (placée avant la route générique
`courriers/{courrierId}`, même contrainte que `courriers.rechercher`),
lien de sidebar entre "Enregistrer un courrier" et "Courriers à traiter",
gardé par `@can('create', Courrier::class)` — même privilège que
l'enregistrement, aucun nouveau privilège introduit pour cette page.

**Mécanique** : 3 boutons d'onglet = les 3 sous-statuts du document
(`en_attente_de_transfert`, `en_cours_de_transfert`, et `enregistre` pour
"Transféré" — toujours pas de renommage de statut, décision du
2026-09-08 maintenue). `#[Url] public string $onglet` conserve l'onglet
actif dans l'URL. `#[Computed] courriers()` scope à `sens = 'entrant'` +
créé par l'utilisateur courant (même condition que
`Courrier::estCreeParUtilisateur()`, via l'historique append-only, Règle
n°5) + `statut = $this->onglet` — jamais de courrier SORTANT ni d'un
autre agent, quel que soit l'onglet. Changer d'onglet vide la sélection
et réinitialise la pagination (`changerOnglet()`), même logique que
`updated()` sur `CourrierList`.

**Transfert en masse déplacé ici tel quel** : `transfererSelection()` est
la même mécanique que l'entrée précédente (revérification par id contre
la base — statut ET Policy — Règle n°6 ; jamais fait confiance à
`$this->selection` postée), simplement rattachée à ce nouveau composant
et visible uniquement sur l'onglet "en_attente_de_transfert" (seul statut
où l'action a un sens). Même pattern `<flux:checkbox.group
wire:model.live="selection">` réutilisé sans redérive.

**Pourquoi pas une fusion avec `CourrierList`** : cette page mélange deux
usages différents — recherche multi-critères ponctuelle (`CourrierList`)
et geste quotidien de la réceptionniste sur SES courriers en cours de
transfert. Le document SRS-GEC.pdf décrit explicitement ce second usage
comme des "onglets" par sous-statut, ce que le filtre statut générique de
`CourrierList` n'exprime pas aussi directement. `WorkflowQueue` reste
écartée pour la même raison que dans les deux entrées précédentes :
l'Agent n'y a structurellement pas accès.

Vérifié : 330/330 tests (nouveaux dans `MesCourriersTest` : scoping par
agent/sens/onglet, transfert groupé nominal, courrier d'un autre agent
ignoré, courrier déjà transféré ignoré, reset de sélection au changement
d'onglet, accès refusé sans le privilège de création), pint propre, 0
écart de traduction (503 `__()` audités).

## [2026-09-15] Module 1/4 — Destinataires de transfert (la réceptionniste choisit QUI)

Contexte : demande explicite de l'utilisateur, "she should have the
possibility to chose the user cause there can be the dga itself and adj
dga or another supervisor to send when she click the system shows her a
modal to chose who to send to the confirm and it sends" — jusqu'ici
`transferer()` ne faisait que changer le statut, sans qu'aucune personne
précise ne soit réellement "adressée" : n'importe quel utilisateur
DGA-privilégié (`courriers.voir_dga`) pouvait valider n'importe quel
courrier `en_cours_de_transfert`.

**Clarifié avec l'utilisateur (AskUserQuestion, 2 séries de questions)** :
1. Qui apparaît dans la liste de la modale ? → **curatée par
   l'administrateur, PAR AGENT** ("those account that the admin gave her
   access to see") — PAS dérivée automatiquement d'un profil ou d'un
   privilège. Confirmé ensuite : l'admin peut y mettre littéralement
   n'importe quel utilisateur du système ("Any user in the system"), pas
   seulement ceux qui ont `courriers.dga_valider_service` — flexibilité
   assumée, l'admin reste responsable de configurer une liste qui a du sens.
2. Une fois choisi, le courrier devient-il visible SEULEMENT à cette
   personne ? → **Oui, restreint au destinataire choisi** (pas juste
   informationnel).
3. La modale s'applique-t-elle au transfert individuel, au transfert en
   masse, ou aux deux ? → **Les deux** (`ShowCourrier` et `MesCourriers`).
4. Où l'administrateur gère-t-il cette liste par agent ? → **Nouvelle
   section sur la page /admin/utilisateurs existante**, pas une nouvelle
   page — même pattern "deux boîtes" que l'assignation de privilèges
   individuels déjà sur cette page.

**Modèle de données** : nouvelle table pivot `destinataires_transfert`
(`agent_id`, `destinataire_id`, tous deux `users.id`) — relation
`User::destinatairesTransfert()` (`belongsToMany` auto-référençant). "Any
user in the system" au sens propre : aucune contrainte de profil/privilège
sur qui peut être ajouté à cette liste, seule contrainte : un agent ne
peut pas s'auto-désigner (exclu de sa propre boîte "disponibles",
refusé silencieusement même si l'id est posté directement — Règle n°6).
`courriers.destinataire_transfert_id` (nullable) mémorise qui a été
choisi au moment du transfert — `null` pour tout courrier transféré AVANT
ce changement, traité comme "visible à tout DGA-privilégié" (comportement
d'avant, jamais réassigné rétroactivement) dans `CourrierPolicy::view()`/
`validerService()`, `WorkflowQueue`, `CourrierList`.

**Mécanique** : `WorkflowService::transferer(Courrier, User $destinataire,
User $auteur)` (signature élargie) persiste `destinataire_transfert_id` et
nomme le destinataire dans le commentaire d'historique. Ni `ShowCourrier`
ni `MesCourriers` ne font confiance à l'id posté par la modale : chacun
revérifie que le destinataire choisi appartient bien à
`Auth::user()->destinatairesTransfert()` avant d'appeler le service (Règle
n°6) — un id qui ne correspond à aucun destinataire autorisé (manipulé
côté client, ou retiré par l'admin entre l'affichage et le clic) échoue
avec une erreur de validation, aucun courrier n'est transféré.

**Bug trouvé en testant** : `courriers.destinataire_transfert_id` n'était
pas dans `Courrier::$fillable` — `update(['destinataire_transfert_id' =>
...])` l'ignorait SILENCIEUSEMENT (pas d'exception, le reste de l'update
passait) plutôt que d'échouer bruyamment. Corrigé en l'ajoutant à la
liste.

Vérifié : 335/335 tests (nouveaux : destinataire hors liste autorisée
refusé côté `ShowCourrier` ET côté transfert en masse `MesCourriers`,
ajout/retrait individuel + flèches centrales sur la nouvelle boîte
`UserList`, auto-exclusion de l'utilisateur sélectionné de sa propre
liste de destinataires disponibles), pint propre, 0 écart de traduction
(524 `__()` audités). Migrations appliquées avec succès sur la base dev
(MySQL réel).

## [2026-09-15] Module 1/3 — Courrier confidentiel (jamais scanné, jamais d'OCR/extraction/classification)

Contexte : en comparant le SRS-GEC.pdf au code (voir notes internes, delta initial), trois points avaient été
signalés à l'utilisateur pour vérification. Le troisième — "confidential
courriers must never enter the OCR/extraction/classification pipeline" —
s'est confirmé être un vrai écart : `specifications-modules-GEC.md`
décrit un "Cas particulier : courrier confidentiel" en Module 1 (jamais
ouvert ni scanné, seul le nom sur l'enveloppe est relevé, envoyé
directement à une personne — RH ou DGA/ADJ DGA, pas un service — avec un
accusé de réception dédié comme seule preuve) et Module 3 ("le champ
confidentiel désactive tout le pipeline OCR/extraction/classification
pour ce courrier"). Aucun des deux n'existait : un courrier confidentiel
passait par le même flux "scan d'abord" que n'importe quel autre —
`RegistrationForm::updatedFormConfidentialite()` (2026-09-07/2026-09-10)
imposait seulement un OBJET générique une fois l'agent arrivé au
formulaire, mais l'OCR avait déjà tourné sur le brouillon AVANT que la
confidentialité ne soit choisie (le flux scan-first scanne avant que le
formulaire n'existe), et `IndexCourrierJob` était toujours dispatché sans
condition à l'enregistrement.

**Décision — composant dédié, pas une branche conditionnelle** :
`RegistrationFormConfidentiel` est un flux entièrement séparé de
`RegistrationForm`/`ScanPremier`/`ScanForm`, pas un `if` de plus dans
l'existant — cohérent avec la façon dont le spec lui-même le présente
("Processus différent"). Garantie structurelle plutôt qu'une simple
validation : ce composant n'utilise PAS `WithFileUploads`, donc aucun
champ d'upload n'existe nulle part dans son formulaire — impossible d'y
attacher un fichier par erreur, la garantie "jamais scanné" vient de
l'absence du mécanisme, pas d'un contrôle contournable.

**Champs et statut** : nom sur l'enveloppe (`destinataire`), date de
réception (`date_mouvement`), niveau de confidentialité
(`confidentiel`/`tres_confidentiel` — même enum déjà existant, pas de
nouvelle valeur), destinataire système. `objet` reçoit le même texte
générique que la mitigation déjà en place sur `RegistrationForm`
("Correspondance confidentielle (non ouverte)", clé de traduction
réutilisée telle quelle) ; `type_document` reçoit "Correspondance
confidentielle" (nouvelle valeur, hors `CourrierForm::TYPES_DOCUMENT` —
ce composant ne partage pas ce Form Object). `service_id` reste null (pas
de Module 3) et le **statut passe directement à `enregistre`** — pas
`en_attente_de_transfert`/`en_cours_de_transfert` : le spec dit "transmis
directement", il n'y a pas d'étape de validation DGA du service puisque
la classification n'a jamais lieu ; l'envoi EST l'enregistrement, pas une
action séparée après coup.

**Mise à jour 2026-10-07 (demande utilisateur : "a toggle that switch
between confidentiel and normal")** — la séparation ci-dessus N'A PAS été
rouverte : `RegistrationForm` et `RegistrationFormConfidentiel` restent
deux composants entièrement distincts, aucune fusion, aucun `if`. Seule la
NAVIGATION entre les deux pages a été rendue plus immédiate : un petit
composant Blade `<x-courrier-mode-toggle>` (deux liens `wire:navigate`,
visible seulement avec `courriers.creer_confidentiel`) ajouté en haut des
deux pages, remplaçant l'ancien encart "Courrier confidentiel ?" de
`RegistrationForm` (qui faisait déjà la même navigation, juste moins
visible). La garantie structurelle "jamais scanné" (pas de
`WithFileUploads` sur `RegistrationFormConfidentiel`) reste donc intacte.

**Mise à jour 2026-10-07 (bis) — fusion explicitement demandée, décision
rouverte en connaissance de cause.** Retour utilisateur sur le ressenti du
toggle ci-dessus ("shouldn't take long to change thos form") : mesuré en
direct (voir le journal de développement 13:40) — le rendu serveur de chaque page
est déjà rapide une fois le cache de vue chaud (~20ms), la lenteur
observée était un recompilation Blade ponctuelle. `wire:navigate.hover`
ajouté comme correctif ciblé. L'utilisateur a ensuite demandé explicitement
"it should on a same file not two different files", et face au choix
("une seule page mais deux composants séparés dessous" vs "fusion réelle
en un seul composant Livewire") a choisi explicitement la fusion réelle,
après avoir été prévenu que cela transforme la garantie "jamais scanné" de
STRUCTURELLE (mécanisme d'upload absent) à CODÉE (vérification à
l'exécution). Décision : `RegistrationFormConfidentiel` est supprimé,
fusionné dans `RegistrationForm` derrière une propriété
`$modeConfidentiel`. Ce que la fusion change concrètement :
- `WithFileUploads` reste présent sur la classe (ne peut pas être retiré
  conditionnellement, c'est un trait PHP) — la protection devient donc un
  GARDE-FOU EXPLICITE plutôt qu'une absence : `enregistrer()` bascule tout
  de suite vers une méthode privée dédiée `enregistrerConfidentiel()`
  quand `$modeConfidentiel` est vrai, qui ne touche JAMAIS
  `$this->pieceJointe`/`$this->document`/`$this->brouillon` (même
  structure de code que l'ancien composant, copiée telle quelle, juste
  déplacée) ; `numeriserAutomatique()`/`importerFichier()` (les deux
  points d'entrée du scan) retournent immédiatement si
  `$modeConfidentiel` est vrai, AVANT toute écriture — défense en
  profondeur contre une requête Livewire forgée qui activerait l'upload
  pendant que le mode confidentiel est actif côté serveur (Règle n°6).
- Les deux routes (`courriers.nouveau` et `courriers.confidentiel`)
  pointent désormais vers LE MÊME composant `RegistrationForm` — la
  route `courriers.confidentiel` reste un nom de route valide (liens
  existants du tableau de bord/sidebar inchangés), mais sert maintenant la
  même page avec `$modeConfidentiel` initialisé à `true` dans `mount()`
  (et `authorize('creerConfidentiel', ...)` vérifié À CE moment précis —
  donc toujours un vrai 403 sur accès direct sans le privilège, comme
  avant, voir `MenuPrivilegesTest`).
- Bascule de mode : un simple `wire:click` sur le composant déjà monté
  (plus de navigation du tout, `wire:navigate.hover` devient inutile pour
  CE toggle précis) — au passage en confidentiel, tout état propre au scan
  (brouillon, pièce jointe, indicateurs "proposé automatiquement") est
  réinitialisé ; au retour en normal, `destinataireChoix` et le niveau de
  confidentialité (forcé ≥2 en mode confidentiel) sont réinitialisés.
- Champs `destinataire`/`confidentialite` du `CourrierForm` existant
  RÉUTILISÉS pour le mode confidentiel (même colonnes, même échelle 1-5,
  déjà partagées) plutôt que dupliqués ; seul `destinataireChoix` (le
  routage système personne/service) reste une propriété à part sur
  `RegistrationForm`, hors `CourrierForm` — même raisonnement que
  `referenceExterne` juste au-dessus (EditForm) : un champ qui ne concerne
  qu'un sous-flux précis n'a pas sa place dans le Form Object partagé.

## [2026-10-07] Destinataire d'un pli confidentiel : un service entier, pas seulement une personne
Contexte : retour terrain de l'utilisateur ("sometimes it been send to the
Directions general or to the RH SERVICE or sometimes to a specific
person") — le destinataire réel d'un pli confidentiel n'est pas toujours
une personne nommée, parfois c'est explicitement l'adresse d'un service
entier sur l'enveloppe ("Direction Générale", "Service RH"). Confirmé via
AskUserQuestion : étendre la liste proposée aux services, qui route vers
le RESPONSABLE du service choisi.
Décision :
- Nouvelle table `destinataires_transfert_services` (agent_id, service_id),
  PARALLÈLE à `destinataires_transfert` (jamais modifiée) — même
  convention : curatée par l'administrateur, par agent, onglet "Destinataires
  de transfert" de `UserList` (nouvelle section "Services" sous les
  "Personnes" existantes, même patron deux-boîtes).
- `RegistrationFormConfidentiel::destinataireChoix` (remplace l'ancien
  `destinataireSystemeId` entier) encode `"user-<id>"` ou `"service-<id>"`
  — une seule liste de choix, deux types d'options, plutôt que deux
  propriétés à garder mutuellement exclusives à la main.
- Un service choisi résout vers `service->responsable_id` : **le modèle de
  données du courrier ne change pas**, `courriers.destinataire_transfert_id`
  reste toujours un utilisateur (le responsable), qui peut ensuite
  réaffecter en interne exactement comme pour un courrier normal déjà
  affecté à son service. Un service SANS responsable désigné est refusé
  (erreur de validation) — personne ne recevrait réellement le pli sinon.
- L'historique (Règle n°5) garde le libellé réel choisi ("service :nom
  (responsable : :resp)"), pas seulement le nom du responsable — la trace
  doit refléter ce que l'agent a réellement sélectionné.
Alternatives envisagées : stocker le service directement sur le courrier
(un nouveau `destinataire_service_id` à côté de `destinataire_transfert_id`)
— écarté, aurait dupliqué la logique de visibilité/clôture déjà construite
autour d'un destinataire UTILISATEUR unique (`CourrierPolicy::view()`,
`cloturerConfidentiel()`) pour un gain nul : un service a de toute façon
besoin d'un humain responsable pour agir dessus.

**Destinataire système — réutilisation de `destinatairesTransfert()`**
(voir l'entrée précédente "Destinataires de transfert") plutôt qu'un
nouveau concept ou une nouvelle table : "à qui l'agent a le droit
d'envoyer un courrier" est exactement la même question pour le transfert
différé d'un courrier normal et l'envoi immédiat d'un courrier
confidentiel — le spec nomme RH ou DGA/ADJ DGA comme destinataires
typiques, mais rien n'empêche l'administrateur d'y mettre qui il veut
(même règle "any user in the system" que l'entrée précédente). Revérifié
côté serveur avant d'agir (Règle n°6), même mécanique que ShowCourrier/MesCourriers.

**Accusé de réception — PDF dédié, pas le bordereau général** :
`CourrierAccuseReceptionController`/`pdf/accuse-reception.blade.php`
plutôt que de réutiliser `CourrierBordereauController` — le contenu exigé
diffère (numéro, date/heure d'enregistrement, nom sur l'enveloppe,
mention Confidentiel, **agent ayant enregistré** — ce dernier champ
n'existe pas sur le bordereau général) et le bordereau général afficherait
des champs vides/non pertinents (objet générique, expéditeur "—", service
"—") qui n'ont pas leur place sur "la seule preuve documentée de ce
courrier". 404 si appelé sur un courrier non confidentiel (le document
n'a de sens que pour ce cas précis).

**Défense en profondeur — `ScanForm::numeriser()`** : refuse désormais de
scanner un courrier dont `confidentialite !== 'normale'`, quel que soit
le point d'entrée. Ce garde-fou ne protège PAS un courrier déjà scanné
avant la bascule (chronologiquement impossible à corriger après coup),
mais empêche un re-scan ultérieur sur un courrier déjà marqué confidentiel
(ex. si quelqu'un navigue directement vers `/courriers/{id}/numeriser`).

**Volontairement pas fait dans cette entrée** :
- **EditForm ne bloque pas le passage en confidentiel d'un courrier déjà
  scanné/OCR'd** (remarqué en travaillant sur ce sujet — `EditForm.php`
  n'a même pas la mitigation "objet générique" que `RegistrationForm` a
  depuis le 2026-09-07). Un courrier normal, déjà scanné, dont on change
  ensuite la confidentialité via `EditForm`, garde son `texte_ocr` déjà
  extrait — la fuite existe toujours dans ce sens précis. Corriger ce cas
  demanderait une politique de rédaction rétroactive (purger `texte_ocr`/
  `fichier_path` à la bascule ?) qui est une décision différente, plus
  large, de "empêcher qu'un VRAI courrier confidentiel entre dans le
  pipeline dès le départ" — non tranchée avec l'utilisateur, à
  confirmer avant de coder.
- **Confidentialité numérique hiérarchique (niveaux 1,2,3,4…)** — reste
  l'item #1 de la liste "net nouveau" des notes internes de synchronisation SRS,
  distinct de ce champ `confidentialite` (normale/confidentiel/très
  confidentiel) qui ne gère que ce cas de registration précis.

Vérifié : 340/340 tests (nouveaux dans `RegistrationFormConfidentielTest` :
enregistrement nominal sans `fichier_path`/`texte_ocr`, destinataire hors
liste autorisée refusé, nom sur enveloppe obligatoire, accès refusé sans
`courriers.creer`, garde-fou `ScanForm` sur un courrier confidentiel déjà
enregistré), pint propre, 0 écart de traduction (548 `__()` audités).

## [2026-09-16] Module 3 — dossiers de classement : privilèges d'abord (chantier en plusieurs étapes)

Contexte : demande explicite de l'utilisateur ("first write the new
permissions"), après avoir choisi "Dossiers de classement" (Module 3)
parmi les points net-nouveaux listés dans les notes internes de synchronisation SRS.
Décision de construire cette fonctionnalité en plusieurs étapes
distinctes (privilèges → modèle/migration → Policy → UI) plutôt qu'en un
seul gros changement, sur demande explicite ("first") — cette entrée ne
couvre QUE la première étape.

**Deux privilèges, pas un par action CRUD** — texte du spec
(`specifications-modules-GEC.md`, "Dossiers de classement créés par
service") : "Le chef de service **ou les collaborateurs** de ce service
(pas uniquement un administrateur) peuvent créer leurs propres dossiers
de classement". Donc :
- `dossiers_classement.creer` — qui a le droit de créer un NOUVEAU
  dossier. Assigné par défaut à Responsable de service ET Collaborateur
  (pas seulement Administrateur, contrairement à `regles_classement.gerer`
  qui reste réservé à l'administrateur — la distinction "Module 3 :
  règles automatiques codées par un admin" vs "Module 3 : dossiers
  personnels créés par n'importe quel agent de terrain" est explicite
  dans le spec).
- `dossiers_classement.gerer_tout` — garde-fou administrateur, même esprit
  que `courriers.modifier_tout` vs `modifier_propre` : voir/renommer/
  gérer les accès/supprimer N'IMPORTE QUEL dossier, pas seulement ceux
  qu'on a soi-même créés. Assigné à Administrateur uniquement (comme tous
  les privilèges, via l'ajout automatique de `PrivilegeSeeder`).

**Volontairement PAS un privilège — l'accès À un dossier précis** : le
spec est explicite, "**Privilège d'accès par dossier** : chaque dossier
créé a ses propres droits de visualisation... le créateur du dossier
définit qui y a accès, plus finement que le niveau service." Malgré le
mot "privilège" dans le texte du client, ce n'est PAS un privilège au
sens du système déjà construit ici (assigné à un PROFIL ou un
UTILISATEUR de façon globale, catalogue fixe) — c'est une donnée assignée
PAR DOSSIER, par son créateur, cas par cas (comme `destinataires_transfert`
est une donnée assignée par agent, pas un privilège). Modélisée plus tard
par une table `dossier_classement_user` (ou équivalent), pas par le
système de privilèges. Le spec confirme explicitement la distinction : ce
droit par dossier "s'ajoute aux deux **autres règles d'accès** (niveau de
confidentialité hiérarchique, et permission par service/dossier × profil
— voir Module 9) — les trois se cumulent" — trois mécanismes différents
qui se cumulent, pas trois variantes du même système de privilèges.

Vérifié : seedé sur la base dev (MySQL réel) et contrôlé directement en
base — `dossiers_classement.creer` → [Collaborateur, Responsable de
service, Administrateur], `dossiers_classement.gerer_tout` →
[Administrateur]. 340/340 tests (le seed automatique de `Tests\TestCase`
couvre déjà la présence de ces deux privilèges pour tous les tests
existants ; pas de test dédié à cette étape — rien à tester tant que le
modèle/la Policy qui les consommeront n'existent pas), pint propre.
**Prochaine étape** (pas commencée) : modèle `DossierClassement` +
migration + `DossierClassementPolicy` + UI de création/gestion.

## [2026-09-16] Module 10 — Tableau de bord (maquette fournie par l'utilisateur)

Contexte : l'utilisateur a partagé une capture d'écran d'une maquette de
tableau de bord faite avec GPT (page d'accueil GEC — cartes de
statistiques, actions rapides, "Derniers courriers enregistrés",
formulaire d'enregistrement "tout en une page", notifications, tâches du
jour, calendrier). Module 10 (specifications-modules-GEC.md, "Tableau de
bord et statistiques") n'avait **jamais été construit** — `resources/views/dashboard.blade.php`
était encore le placeholder statique du starter-kit Laravel/Livewire
(3 blocs `x-placeholder-pattern` vides), inchangé depuis le tout début du
projet.

**Décision — une seule mise en page, contenu filtré par privilège**
(confirmé avec l'utilisateur, "on the dashboard is same accross all
account/profile but they don't see all... privileges too on those
content") : `Dashboard` est un unique composant Livewire, pas un tableau
de bord différent par profil codé en dur — chaque carte/action est
gardée par un `@can`, exactement le même mécanisme déjà utilisé pour les
liens de la sidebar. Les statistiques (cartes + "Derniers courriers
enregistrés") réutilisent le MÊME périmètre de visibilité que
`CourrierList::resultats()` (dupliqué à l'identique dans
`Dashboard::courriersVisibles()`, même convention de duplication que
CourrierList/WorkflowQueue dans ce projet) — ce ne sont PAS des chiffres
globaux à l'échelle de l'entreprise, seulement ce que CET utilisateur
pourrait ouvrir individuellement.

**Descope explicite négocié avec l'utilisateur** :
- Le formulaire "tout en une page" (refonte de `RegistrationForm` en
  assistant 3 étapes) — **volontairement pas construit**, demande
  explicite ("you will not add this ... do it but it should be like
  comming soon message") : remplacé par un encart "Bientôt disponible".
  Reste un chantier séparé et conséquent.
- Notifications et Calendrier — **placeholders "Bientôt disponible"**,
  aucune donnée réelle ne les alimente (Module 7 est un stub,
  `SendMailAlertJob` n'est jamais dispatché ; aucun concept de calendrier
  n'existe dans le spec).
- "Tâches du jour" — **clarifié en cours de route** ("and tache du will
  be for collaborateur and responsable service sometimes but mainly for
  collaborateur so admin will give them that privileges") : PAS un
  placeholder comme les deux précédents. Nouveau privilège
  `dashboard.taches_du_jour` (Collaborateur par défaut ; Responsable de
  service l'obtient au cas par cas via `/admin/utilisateurs`, pas un
  défaut de profil — "mainly... sometimes" traduit en défaut de profil
  vs assignation individuelle). Le contenu n'est PAS une liste de tâches
  inventée — un aperçu réel des 5 plus anciens éléments que
  `WorkflowQueue` montrerait à CET utilisateur (même périmètre
  Collaborateur/Responsable), avec un lien "Voir tout" vers la vraie file
  d'attente.

**"Courriers urgents"** : `priorite = 'urgente'` (champ réel déjà saisi à
l'enregistrement) plutôt que le SLA — `SlaCalculatorService` est un stub
qui retourne toujours `'a_temps'`, une carte basée dessus aurait été
fausse. Le delta "vs hier" des cartes entrant/sortant est en valeur
ABSOLUE (":n de plus/moins qu'hier"), pas en pourcentage comme la
maquette — plus robuste (évite une division par zéro quand "hier" vaut 0,
cas fréquent sur le faible volume du pilote).

**Couleurs** (demande explicite, "the design colors and all the rest
too") : cartes/actions alignées sur la maquette — bleu (entrant), vert
(sortant/confidentiel), ambre (en attente/traiter), rouge (urgent),
violet (mes enregistrements) — badges de statut existants inchangés.

Vérifié : 346/346 tests (nouveaux dans `DashboardTest` : accès de tout
utilisateur connecté, périmètre par profil sur chaque statistique, delta
vs hier, "courriers urgents" limité au statut actif + priorité urgente,
"tâches du jour" scopé au Collaborateur et absent sans le privilège,
actions rapides filtrées par privilège), pint propre, 0 écart de
traduction.

## [2026-09-16] Module 1/4/8/10 — Navigation (navbar desktop + sidebar)

Contexte : suite directe de l'entrée "Tableau de bord" ci-dessus — la
maquette montrait aussi une barre de navigation desktop (recherche,
notifications, aide, profil) qui n'existait pas dans l'app (seule une
version MOBILE de cette barre existait, cachée en desktop —
`class="lg:hidden"` — le desktop n'avait que la sidebar + un menu profil
en bas de celle-ci) et une sidebar réorganisée par groupe. Demande
explicite ("yes the same design as in the image don't forget navbar and
all the rest even the sidebar").

**Trois liens de sidebar clarifiés via AskUserQuestion avant de toucher
un fichier partagé par TOUTES les pages** (`sidebar.blade.php`) :
- **"Courrier entrant"/"Courrier sortant"** → lien pré-filtré vers
  `CourrierList` (`?sens=entrant`/`?sens=sortant`), pas de nouvelle page.
- **"Courriers incomplets"** (concept nouveau, n'existait nulle part) →
  lien vers `RegistrationForm`, où la liste des brouillons scannés en
  attente (`brouillonsEnAttente()`) vit déjà, pas une nouvelle page
  dédiée.
- **"Courriers enregistrés"** → réponse initiale proposée (lien
  pré-filtré `statut=enregistre` vers CourrierList) **corrigée par
  l'utilisateur** : "no it is courrier list it will be combine there all
  courier already register inside the system but it should follow the
  logic of the 3 tab enattend encour and transfere new page" — en
  réalité une VRAIE page (`CourriersEnregistres`, nouveau composant),
  reprenant exactement la logique des 3 onglets de `MesCourriers` (en
  attente de transfert / en cours de transfert / transféré), mais à
  l'échelle de tout ce que l'utilisateur peut voir (même périmètre que
  `CourrierList::resultats()`) plutôt que limitée à ses PROPRES
  courriers comme `MesCourriers`. Volontairement SANS transfert en
  masse : cette action reste le rôle de `MesCourriers` pour la
  réceptionniste sur ses propres courriers, `CourriersEnregistres` est
  une vue de consultation.

**Recherche navbar → `CourrierList` existant, pas un nouveau moteur** :
nouvelle propriété `#[Url] public string $q` sur `CourrierList`, qui
cherche dans numero_reference/objet/expediteur_nom/expediteur_organisation
à la fois (OR) — distincte des filtres avancés existants (numero/objet/
expediteur restent des filtres structurés séparés). La navbar elle-même
reste un simple `<form method="GET">` HTML, PAS un composant Livewire :
`?q=...` remplit `$q` au chargement grâce à `#[Url]`, sans avoir à
transformer toute la navbar (partagée par toutes les pages) en composant
Livewire.

**Notifications et aide restent honnêtes, pas de fausses données** :
la cloche de notification ouvre un menu qui dit explicitement "Aucune
notification pour l'instant" plutôt qu'un badge avec un chiffre inventé
(Module 7 n'existe pas) ; l'icône d'aide reste décorative (aucune page
d'aide/documentation n'existe dans ce projet).

**Menu profil dédupliqué** : extrait dans
`resources/views/partials/user-menu-items.blade.php`, partagé entre la
navbar mobile (déjà existante) et la nouvelle navbar desktop — évite une
3e copie du même bloc (avatar/nom/email, Settings, Log out).
`<x-desktop-user-menu>` (bas de la sidebar) retiré : redondant avec le
profil maintenant dans la navbar desktop.

Vérifié : 353/353 tests (nouveaux dans `CourriersEnregistresTest` :
périmètre par profil identique à CourrierList, entrant uniquement, les 3
onglets ; nouveau test dans `CourrierListTest` pour `$q`), pint propre, 0
écart de traduction (612 `__()` audités).

## [2026-09-16] Couleur d'accent bleue sur toute l'application

Contexte : après avoir coloré les cartes/tuiles du tableau de bord
(bleu/vert/ambre/rouge, entrée précédente), l'utilisateur a redemandé "the
color" — clarifié via AskUserQuestion : "the whole app", pas un élément
précis du tableau de bord.

**Décision — un seul point de changement plutôt que page par page** :
`resources/css/app.css` définit `--color-accent`/`--color-accent-content`/
`--color-accent-foreground` dans un bloc `@theme` (+ une surcharge
`.dark`) — c'est la variable dont **Flux UI Pro tire directement** ses
boutons `variant="primary"`, ses anneaux de focus, et ses états actifs
(case cochée, item de `flux:radio.group variant="segmented"` sélectionné,
etc.) dans **tous** les composants de l'app, sans exception par page.
Avant ce changement, elle valait `neutral-800` (quasi noir) — chaque
bouton primaire de l'application, sur chaque page construite depuis le
début du projet, était donc gris foncé/noir. Changée en `#2563eb` (clair)
et `#3b82f6` (sombre, ajusté pour le contraste sur fond zinc-900/950) —
un bleu qui correspond à la couleur de marque dominante de la maquette
GPT du tableau de bord (boutons, éléments actifs).

**Ce qui n'a volontairement PAS changé** : les couleurs SÉMANTIQUES déjà
utilisées ailleurs (badges de statut — vert/ambre/rouge/bleu selon le
statut d'un courrier, cartes de statistiques du tableau de bord) restent
telles quelles ; seule la couleur de marque/action (`--color-accent`)
change. Mélanger les deux aurait fait perdre le sens des badges de statut
existants (ex. "rejeté" en rouge redeviendrait juste "la couleur
d'accent" si accent devenait rouge).

Vérifié : 353/353 tests (changement CSS pur, aucun impact PHP), pint
propre, `npm run build` recompilé avec succès.

## [2026-09-16] Thème clair par défaut

Contexte : après la couleur d'accent bleue (entrée précédente), l'app
rendait toujours visiblement plus sombre que la maquette GPT (qui est un
thème clair, fond blanc). Message de l'utilisateur : "the background
colors frontend solore style color idiot" — frustration compréhensible,
un simple changement de couleur d'accent ne suffisait pas tant que
l'ensemble de la page reste en thème sombre.

**Cause trouvée** (pas les `<html class="dark">` codés en dur qu'on
pourrait soupçonner en premier) : `vendor/livewire/flux/src/
AssetManager.php::fluxAppearance()` génère un script exécuté de façon
SYNCHRONE dans `<head>` (avant toute peinture — technique standard
anti-FOUC) qui appelle `window.Flux.applyAppearance(localStorage.getItem('flux.appearance')
|| 'system')`. Sans préférence déjà enregistrée (compte/navigateur
jamais passé par Réglages > Apparence), la valeur retombe sur `'system'`
— qui bascule l'app en sombre dès que l'OS/le navigateur de la personne
préfère le sombre. Les 5 `<html class="dark">` codés en dur dans les
layouts n'étaient donc PAS la cause réelle du rendu sombre (ce script
corrige toujours la classe selon la préférence réelle avant peinture,
donc pas de flash visible) — juste trompeurs à la lecture, retirés par
cohérence avec le comportement réel.

**Décision — pré-remplir `light` avant que `@fluxAppearance` ne lise la
préférence**, plutôt que modifier le vendor Flux (jamais de patch
vendor — écrasé au prochain `composer update`) : petit script inline
dans `resources/views/partials/head.blade.php`, placé AVANT
`@fluxAppearance`, qui fait `localStorage.setItem('flux.appearance', 'light')`
**uniquement si rien n'est encore enregistré**
(`!localStorage.getItem('flux.appearance')`). Un choix explicite déjà
fait par l'utilisateur (via la page Réglages > Apparence, déjà existante
— light/dark/system) n'est jamais écrasé : ce garde-fou "seulement si
vide" est ce qui distingue "l'app s'ouvre en clair par défaut" de "on a
retiré la possibilité de choisir le sombre".

Vérifié : 353/353 tests (changement HTML/JS pur, aucun impact PHP), pint
propre, `npm run build` recompilé avec succès.

## [2026-09-16] Badge de statut partagé + contraste sidebar

Contexte : l'utilisateur a envoyé une capture d'écran réelle de l'app
("Courriers à traiter") pour comparaison avec la maquette. Deux écarts
visuels trouvés :

**1. Badges de statut incohérents** — "enregistre"/"en cours de
transfert"/"affecte" s'affichaient tous en gris neutre sur cette page,
alors que la recherche colore déjà certains statuts. Cause : le mapping
`match ($courrier->statut) { ... }` → couleur avait été copié-collé dans
4 fichiers (`workflowQueue`/`courrierList`/`dashboard`/`showCourrier`
blade) et avait dérivé à chaque copie (ex. `showCourrier` utilisait
"green" pour traité/archivé, les 3 autres n'avaient rien pour "traite" et
un mapping "archive" différent). Extrait en `<x-statut-badge
:statut="...">` (`resources/views/components/statut-badge.blade.php`) —
un seul point de vérité désormais : vert (traité/archivé), rouge
(rejeté), ambre (en attente d'info/en attente de transfert), bleu (en
validation/en cours de transfert/affecté/en traitement), zinc par défaut
(enregistré).

**2. Contraste sidebar** ("look the sidebar bg color the main colors and
the whole website bg color compare to that one i send you") —
`bg-zinc-50` (#fafafa, palette custom du projet) contre `bg-white` sur le
contenu principal ne laissait quasiment aucun écart visible (~2 % de
luminosité), alors que la maquette montre une sidebar nettement
distincte du contenu. Changé en `bg-zinc-100` (#f5f5f5) — suffisant pour
une séparation visible sans casser la palette existante. Mode sombre
inchangé (`bg-zinc-900` déjà bien distinct de `bg-zinc-800`).

Vérifié : 353/353 tests, pint propre, 0 écart de traduction (612 `__()`
audités), `npm run build` recompilé avec succès.

## [2026-09-16] Cartes/tableaux uniformisés + retour en arrière sur le gris de la sidebar

Contexte : après avoir coloré le tableau de bord (accent bleu, badges,
tuiles), l'utilisateur a envoyé une capture d'écran réelle de "Courriers
à traiter" et a réagi fortement ("everything is gray ... redo the
design"). En creusant, la cause n'était PAS une couleur mal choisie mais
un manque total de traitement visuel : `courrierList`/`workflowQueue`/
`regleList` avaient des tableaux **sans aucun conteneur** (`overflow-x-auto`
nu — pas de bordure, pas de fond, pas d'ombre), donc rendaient à plat sur
le fond de page. D'autres pages (`userList`/`profilList`/`privilegeList`/
`mesCourriers`/`courriersEnregistres`) avaient bien un conteneur bordé
mais sans fond ni ombre, donc tout aussi invisibles sur un fond de page
blanc.

**Décision — un seul style de carte partout** : `rounded-2xl border
border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900`
appliqué systématiquement à chaque conteneur de tableau/panneau à
travers l'app (8 fichiers), plutôt qu'une pièce par pièce sans règle
commune. Les 6 fichiers qui avaient déjà `rounded-xl border
border-zinc-200 dark:border-zinc-700` (juste sans fond/ombre) ont été
corrigés en une seule passe via un script PHP scratch (remplacement de
chaîne exacte, vérifié par re-grep) plutôt qu'édition manuelle
répétitive — 3 fichiers avec un mélange de classes légèrement différent
(`courrierList`/`workflowQueue`/`regleList`) ont été corrigés à la main.

**Correction immédiate sur la sidebar** : l'entrée précédente
("Contraste sidebar") avait changé `bg-zinc-50` en `bg-zinc-100` pour la
rendre visuellement distincte — l'utilisateur a immédiatement réagi ("i
dont want the gray sidebar ... use ... the blue and white colors").
En réexaminant la maquette de près, la sidebar y est en réalité
**blanche comme le contenu principal**, séparée seulement par une fine
bordure verticale (`border-e`) — le contraste dans la maquette vient des
CARTES blanches + ombre qui se détachent du fond, pas d'un remplissage
gris de la sidebar. Reverti en `bg-white`. Leçon : une intention
correcte (rendre la sidebar visible) avait la mauvaise solution (un
aplat gris au lieu d'ombre/bordure sur les cartes).

Vérifié : 353/353 tests, pint propre, 0 écart de traduction (612 `__()`
audités), `npm run build` recompilé avec succès.

## [2026-09-16] Rebranding APP_NAME + bug de directive Blade dans un commentaire

Contexte : deux problèmes trouvés en comparant l'app à la maquette et
en réponse à un message d'erreur de l'utilisateur (un extrait de script
cassé collé tel quel, se terminant par "error").

**1. `APP_NAME` jamais configuré** — le logo de la sidebar (`config('app.name',
'Laravel')`) affichait encore "Laravel" depuis le tout début du projet.
Changé en `GEC` dans `.env` ET `.env.example` (garder les deux
synchronisés), suivi d'un `config:clear`.

**2. Bug réel — directive Blade écrite en toutes lettres dans un
commentaire JavaScript** : le script de "thème clair par défaut" (voir
entrée "Thème clair par défaut" plus haut) avait des commentaires `//`
qui mentionnaient littéralement `@fluxAppearance` (le nom de la
directive juste en dessous, pour expliquer la relation). Blade ne
connaît PAS la syntaxe de commentaire JavaScript — il scanne le texte
BRUT de tout le fichier `.blade.php` à la recherche de `@nomDeDirective`
et le remplace, y compris à l'intérieur d'un commentaire `//` ou même
d'une chaîne de caractères. Le nom de la directive dans le commentaire a
donc été remplacé par tout le script/style vendu par Flux, EN PLEIN
MILIEU du commentaire JS — cassant la syntaxe et la page. Corrigé en
reformulant l'explication dans un commentaire Blade `{{-- --}}` (jamais
interprété comme du texte à substituer) placé avant le `<script>`, sans
jamais écrire le nom de la directive en toutes lettres dans un
commentaire JS. Vérifié par un rendu direct (`view('partials.head')
->render()`) avant/après, pas seulement en relisant le code.

**Leçon générale, à appliquer partout dans ce projet** : ne jamais
écrire le nom d'une directive Blade personnalisée (`@fluxAppearance`,
`@fonts`, etc.) dans un commentaire `//` ou `/* */` JavaScript/CSS à
l'intérieur d'un fichier `.blade.php` — utiliser un commentaire Blade
`{{-- --}}`, ou reformuler pour ne pas répéter le nom exact de la
directive.

Vérifié : 353/353 tests, pint propre, `npm run build` recompilé avec
succès.

## [2026-09-16] Sidebar bleue + item actif en aplat plein

Contexte : après le retour en arrière sur le fond de la sidebar (entrée
"Cartes/tableaux uniformisés", qui l'avait remise en `bg-white`),
l'utilisateur a redemandé du bleu, à trois reprises de plus en plus
directement : "it should take the blue one not that white completely
white no" → "this eaxct blue" (en renvoyant la maquette) → "i want the
exact color solid why are you not doing what am saying to you".

**Deux écarts distincts, pas un seul** :
1. **Fond de sidebar** — simplement repassé en `bg-blue-50` (bleu clair),
   pas blanc. Correction directe, pas de nouvelle investigation
   nécessaire.
2. **L'item actif ne devenait jamais un aplat de couleur plein** — celui-là
   nécessitait de comprendre POURQUOI aucun changement de couleur
   d'accent n'avait d'effet visible sur cet élément précis. Trouvé en
   lisant le stub Flux (`vendor/livewire/flux/stubs/resources/views/flux/sidebar/item.blade.php`) :
   l'état actif par défaut de `flux:sidebar.item` (`accent=true`) donne
   `data-current:bg-white dark:data-current:bg-white/[7%]` — un fond
   QUASI BLANC — avec seulement `data-current:text-(--color-accent-content)`
   pour le texte. Changer `--color-accent` ne pouvait donc JAMAIS
   produire un aplat plein sur cet élément, quelle que soit la couleur
   choisie — le composant Flux lui-même ne le permet pas par défaut.

**Décision — surcharge CSS ciblée, jamais de patch vendor** (cohérent
avec la leçon déjà actée pour `@fluxAppearance`, voir notes internes) :
```css
[data-flux-sidebar-item][data-current] {
    background-color: var(--color-accent) !important;
    color: var(--color-accent-foreground) !important;
    border-color: transparent !important;
}
```
dans `resources/css/app.css`. Vérifié dans le bundle CSS compilé
(`grep` sur `public/build/assets/app-*.css`) avant même de demander à
l'utilisateur de recharger — pas seulement "ça devrait marcher".

**Leçon** : quand un changement de VARIABLE (`--color-accent`) n'a
visiblement aucun effet sur un élément précis malgré plusieurs
tentatives, le problème n'est probablement pas la valeur de la variable
mais que cet élément n'utilise PAS cette variable de la façon supposée —
lire le composant source (ici, un stub vendor) plutôt que de re-deviner
une autre valeur de couleur.

Vérifié : 353/353 tests, pint propre, 0 écart de traduction, confirmé
visuellement par l'utilisateur après rebuild (capture d'écran : item
actif en bleu plein, texte blanc, sidebar bleu clair).

## [2026-09-16] Palette de marque exacte

Contexte : après plusieurs allers-retours à deviner des couleurs
approximatives depuis une capture d'écran (gris → blanc → bleu, voir les
deux entrées précédentes), l'utilisateur a fourni une palette
hexadécimale précise avec labels d'usage explicites pour chaque couleur
("Here is the color palette used in the GEC web interface... Dark Navy
#12396B — Sidebar background, brand identity... Primary Blue #0D6ECA —
Buttons, active menu, icons..." etc., plus un bloc `:root { --primary-navy:
#12396B; ... }` prêt à l'emploi).

**Décision — jetons de marque en CSS natif Tailwind v4**, pas des
classes de valeur arbitraire dispersées : chaque couleur devient une
variable `--color-brand-*` dans le bloc `@theme` de `resources/css/app.css`,
ce qui génère automatiquement les utilitaires Tailwind correspondants
(`bg-brand-navy`, `text-brand-urgent`, etc. — mécanisme natif de
Tailwind v4, pas de plugin). `--color-accent` (celle que Flux utilise
pour boutons primaires/anneaux de focus/états actifs partout dans l'app)
alignée sur `--color-brand-blue` (Primary Blue), avec la MÊME valeur en
clair et en sombre — la sidebar reste teintée navy en permanence (voir
entrée "Sidebar bleue"), l'accent ne doit donc pas varier selon ce
contexte pour rester identique aux boutons du contenu principal.

**Deux corrections sémantiques trouvées en appliquant la palette à la
lettre, pas en la survolant** :
- "Courriers urgents" (carte du tableau de bord) était en ROUGE, construit
  par déduction visuelle lors de la première passe couleur. Le ROUGE de
  cette palette est réservé aux "notifications urgentes, alertes,
  erreurs" (système) ; le VIOLET couvre explicitement "courriers
  urgents, confidentialité, modules spéciaux" — corrigé en violet
  (`--color-brand-urgent`).
- "Courrier confidentiel" (tuile d'action rapide) était en emerald/vert,
  proche visuellement du vert de succès. La palette distingue
  explicitement GREEN ("succès") de TEAL ("confidentialité/courrier
  sécurisé") comme deux couleurs séparées — corrigé en teal
  (`--color-brand-secure`), y compris le badge "Confidentiel"/"Très
  confidentiel" affiché dans `courrierList.blade.php` (déplacé d'amber à
  teal, pour la même raison).

Bannière de bienvenue ("Bonjour :nom") habillée en Pale Blue avec une
icône enveloppe — la palette nomme explicitement cet usage
("Welcome banner, soft backgrounds"), qui n'existait pas du tout
auparavant (texte brut sans fond).

Vérifié : 353/353 tests, pint propre, 0 écart de traduction, valeurs
hexadécimales exactes confirmées présentes dans le bundle CSS compilé
(`grep` sur `public/build/assets/app-*.css`).

## [2026-09-16] Application complète de la palette — les couleurs neutres avaient été oubliées

Contexte : immédiatement après l'entrée précédente, l'utilisateur a
réagi ("why are you doing this to me reade the color palte i gave with
thier labels before working") en renvoyant la MÊME palette une seconde
fois. En relisant chaque ligne un label à la fois plutôt que seulement
les couleurs de statut les plus visibles, six jetons avaient bien été
**définis** dans `app.css` (`--color-brand-surface`, `-table-header`,
`-border`, `-text-secondary`, `-text-primary`) mais **jamais appliqués
nulle part** dans les templates — "Very light gray — Page surfaces",
"Border gray — Card and input borders", "Medium gray — Secondary text,
captions", "Dark text navy — Main headings and body text" restaient donc
sans aucun effet visible malgré leur présence dans le fichier CSS. La
première passe s'était arrêtée aux couleurs "de marque" les plus
évidentes (navy, blue, success, warning, urgent, secure) sans dérouler
la section "Neutral colors" du tableau fourni jusqu'au bout.

**Décision — remapper les nuances zinc partagées plutôt que de traquer
chaque occurrence** : `border-zinc-200`, `text-zinc-500` et `bg-zinc-50`
sont déjà utilisés des dizaines de fois dans ~25 fichiers Blade pour
exactement les rôles que la palette nomme (bordures de carte, texte
secondaire, fonds de `<thead>`/fonds discrets). Plutôt que de retrouver
et éditer chaque occurrence individuellement, `--color-zinc-50/200/500`
sont redéfinies directement sur les valeurs exactes de la palette
(`#F1F5F9`/`#DCE5EF`/`#64748B`) dans le bloc `@theme` — un seul point de
changement, effet immédiat partout où ces classes existent déjà.
`zinc-100/300/400/600-950` volontairement non touchées : massivement
utilisées pour les FONDS du vrai mode sombre de l'app (`dark:bg-zinc-900`
etc.), un remap global y aurait teinté ces fonds par effet de bord, hors
sujet ici.

**Titres** : `flux:heading` code en dur `text-zinc-800` par défaut
(`vendor/livewire/flux/stubs/resources/views/flux/heading.blade.php`) —
zinc-800 n'a volontairement pas été remappé globalement pour la même
raison que ci-dessus (fonds de mode sombre). Corrigé par une surcharge
CSS ciblée sur l'attribut `[data-flux-heading]` : couleur `--color-brand-text-primary`
en clair, blanc explicite sous `.dark [data-flux-heading]` (couvre à la
fois un vrai mode sombre applicatif ET la sidebar en permanence sombre).

**Fond de page** : `<body>` passe de `bg-white` à `bg-brand-surface`
(`#F8FAFC`) — la palette distingue "White — Main page background,
CARDS" de "Very light gray — Page SURFACES" comme deux couleurs
séparées ; les cartes blanches (déjà `bg-white shadow-sm`, voir l'entrée
"Cartes/tableaux uniformisés") se détachent maintenant réellement du
fond de page au lieu d'être blanc sur blanc.

**Leçon, déjà notée une fois mais qui mérite d'être répétée** : quand un
utilisateur fournit une spécification structurée avec labels explicites
(un tableau, une liste étiquetée), la dérouler ENTIÈREMENT avant de
considérer la tâche terminée — s'arrêter aux entrées les plus visibles/
évidentes et laisser les jetons "neutres"/"secondaires" définis sans
être appliqués nulle part est exactement le genre d'écart qui, du point
de vue de l'utilisateur, a l'air d'un travail bâclé même si chaque ligne
de code écrite est individuellement correcte.

Vérifié : 353/353 tests, pint propre, 0 écart de traduction, valeurs
hexadécimales exactes confirmées présentes dans le bundle CSS compilé.

## [2026-09-23] Périmètre de visibilité unique
Contexte : audit des pages du 2026-09-23. Le tableau de bord, "Courriers
enregistrés", la file d'attente et "Mes courriers" tenaient chacun leur
propre copie du périmètre, basée sur le NOM du profil. Aucune ne
vérifiait le niveau de confidentialité, l'accès dossier ni le périmètre
organisationnel : un courrier de niveau 5 refusé en consultation (403)
restait listé (numéro + objet) pour un utilisateur de niveau 1. Et un
utilisateur sans profil (inscription publique) n'était filtré par aucun
bloc : il voyait tous les courriers sur le tableau de bord.
Décision : `Courrier::scopeVisiblePar()` est le SEUL périmètre de liste.
Toute nouvelle liste de courriers doit partir de
`Courrier::query()->visiblePar($user)` puis ajouter ses propres filtres.
Son bloc "par profil" est désormais basé sur les privilèges
(`courriers.voir_tout/voir_service/voir_propre/voir_affecte/voir_dga`),
miroir exact de `CourrierPolicy::view()` ; aucun privilège de
consultation => aucun résultat. Garde-fou : `VisibiliteListesTest`.
Alternatives envisagées : garder les copies locales et y ajouter les
gates manquants — écarté, c'est exactement ce qui avait divergé.

## [2026-09-23] SLA et alertes (Modules 5 et 7)
Contexte : `SlaCalculatorService` renvoyait toujours "à temps" et
`SendMailAlertJob`/`CourrierEnRetardNotification` étaient vides et jamais
planifiés — aucun retard n'était jamais détecté ni signalé.
Décision :
- Date limite = `echeance` si saisie, sinon `date_mouvement` + délai ;
  délai = `sla_jours` du courrier, sinon `config('gec.sla.par_type')[type]`,
  sinon `config('gec.sla.jours_defaut')` (10). Jours calendaires, pas de
  pause pendant "en attente d'information" (hors scope phase 1).
- Stockée dans `courriers.date_limite` (indexée) et recalculée par
  `Courrier::booted()` : "en retard" = statut actif ET date limite passée,
  une simple requête indexée partout (`Courrier::scopeEnRetard()`).
- `SendMailAlertJob` toutes les heures (Scheduler) : "bientôt en retard"
  une fois par date limite (au collaborateur affecté, sinon au
  responsable du service) quand il reste ≤ `seuil_risque_jours` (2) ;
  "en retard" au collaborateur + responsable, puis relance tous les
  `relance_jours` (2). Selon l'étape, destinataire du transfert DGA ou
  agent créateur. Jamais envoyé à qui ne passe pas `CourrierPolicy::view()`.
  Chaque alerte trace une entrée d'historique (auteur null). Changer la
  date limite ré-arme les alertes.
- Email uniquement (pas de table `notifications`, pas de cloche) ; l'objet
  d'un courrier de confidentialité > 1 n'est jamais mis dans l'email.
- Délais dans `config/gec.php` : **les délais par type restent à fournir
  par Nsia Assurances** — seule la valeur par défaut est posée. La page
  d'administration "SLA & Alertes" reste "Bientôt".
- Tableau de bord : "En retard" (COUNT indexé, à l'affichage) et "Délai
  moyen de traitement" (clôture `validation_acceptee` − `date_mouvement`,
  90 derniers jours) pré-calculé par `RefreshDashboardStatsJob` toutes les
  15 min, en cache par service ; moyenne globale pour `voir_tout`, sur ses
  services pour `voir_service`, carte masquée sinon.
- `courriers:nettoyer-brouillons` reste volontairement NON planifié
  (décision d'origine confirmée : un scan non traité en 7 jours serait
  supprimé, risque de perte de courrier réel).
Alternatives envisagées : calcul de la date limite en SQL à chaque
requête (non portable MySQL/SQLite, non indexable) ; notifications en
base avec cloche (pas de table ni d'UI existante — plus tard).

## [2026-09-23] Inscription publique désactivée
Contexte : `/register` (starter kit Fortify) permettait à n'importe qui de
créer un compte, sans profil ni niveau de confidentialité, sur un outil
interne.
Décision : `Features::registration()` retiré de `config/fortify.php`. Les
comptes sont créés uniquement par un administrateur ("Utilisateurs &
Accès"). Le lien "Sign up" de la page de connexion n'apparaît que si la
route existe.
Alternatives envisagées : garder l'inscription avec un profil par défaut
— écarté, l'attribution d'un profil et d'un niveau est une décision
d'administration.

## [2026-09-23] Menus pilotés par privilège
Contexte : demande explicite de l'utilisateur — "all in sidebar should be
permission even the submenu and menu". Plusieurs entrées étaient visibles
par tous (Numérisation, Archives, tous les "Bientôt", Notifications, Aide,
Statistiques), certaines partageaient un privilège sans rapport
(Numérisation = courriers.creer ; Utilisateurs & Accès et Profils =
privileges.gerer), et des titres de groupe s'affichaient vides.
Décision :
- Chaque entrée de la sidebar (et de la barre supérieure : cloche, aide)
  dépend d'un privilège ; chaque groupe n'apparaît que si au moins une de
  ses entrées est visible. 13 nouvelles clés : `dashboard.voir`,
  `courriers.numeriser`, `courriers.creer_confidentiel`,
  `dossiers_classement.archives`, `utilisateurs.gerer`,
  `statistiques.consulter`, `statistiques.rapports`,
  `administration.automatisation|workflows|sla|audit`,
  `general.notifications`, `general.aide`.
- **Défauts = préserver l'accès existant** : chaque clé est donnée aux
  profils qui voyaient déjà l'entrée. Exception : les pages "à venir"
  (Statistiques/Rapports → DGA + Responsable de service ; Automatisation,
  Workflows, SLA, Audit → Administrateur seul).
- La page ET ses raccourcis exigent le même privilège que l'entrée de menu
  (Règle n°6) : ScanPremier/ScanForm (`numeriser`/`renumeriser`),
  formulaire confidentiel (`creerConfidentiel`), nœud Archives vérifié
  côté serveur, tuiles du tableau de bord une par une.
- Sans `dashboard.voir`, le tableau de bord (page d'accueil après
  connexion) redirige vers les paramètres du compte plutôt qu'un 403.
- **Jamais gouvernés par privilège** : "Paramètres" (son propre compte,
  mot de passe, 2FA) et "Déconnexion" — les retirer enfermerait
  l'utilisateur.
- `utilisateurs.gerer` (page "Utilisateurs & Accès" : coordonnées,
  activation, mot de passe, destinataires) est séparé de `privileges.gerer`
  (Profils, permissions). Créer un compte, changer profil / niveau de
  confidentialité / périmètre / permissions exige `privileges.gerer`
  (valeurs postées ignorées côté serveur sinon). Un compte Administrateur
  ou détenteur de `privileges.gerer` est intouchable sans
  `privileges.gerer` — sinon changer son email puis réinitialiser son mot
  de passe suffirait à en prendre le contrôle. Même garde-fou
  anti-verrouillage Administrateur que `gerer()`.
Alternatives envisagées : cacher les entrées sans vérifier les pages
(écarté, un lien direct suffirait) ; un privilège unique par groupe
(écarté, la demande porte aussi sur les sous-menus) ; laisser "Utilisateurs
& Accès" et "Profils" sur la même clé (écarté, deux entrées = deux droits).

**Complément (même jour) — actions sur un courrier** : `courriers.telecharger`
(document principal, pièces jointes, brouillons scannés) et
`courriers.imprimer_bordereau` exigent le privilège ET le droit de
consulter (`view()`), jamais l'un sans l'autre ; défaut = tous les profils
(comportement inchangé). L'aperçu à l'écran reste soumis à la seule
consultation — limite assumée : un navigateur peut toujours enregistrer un
PDF affiché. `courriers.supprimer` (Administrateur seul par défaut) :
suppression LOGIQUE (SoftDeletes), motif obligatoire écrit dans
l'historique dans la même transaction, refusée sur un courrier archivé
(Règle n°5). L'accusé de réception confidentiel garde sa propre règle
(`imprimerAccuseReception`, créateur ou consultation).

## [2026-09-23] Services créés depuis l'Organisation
Contexte : l'utilisateur confirme qu'il n'y aura PAS de page "Services" — les
services se gèrent dans l'Organisation. Or l'organigramme (`organization_units`)
et la table `services` (routage DGA, `courriers.service_id`, règles,
recherche, dossiers, responsable qui reçoit courriers et alertes SLA) étaient
deux listes séparées, reliées seulement à la main vers les 17 services seedés.
Décision : `App\Services\ServiceReelSynchroniseur`, appelé par
OrganisationIndex (création/modification, activation, responsable) :
- un nœud Service ou Sous-service sans service choisi relie le service
  existant de même nom (jamais de doublon, `services.nom` est unique), sinon
  en crée un (code ≤ 10 caractères unique, dérivé du code du nœud ou des
  initiales du nom) ;
- nom et responsable ne sont reportés que si nœud et service étaient
  alignés — un service relié à la main sous un autre nom n'est jamais renommé ;
- désactiver le nœud désactive le service sauf si un autre nœud actif
  l'utilise ; jamais de suppression.
- Les Départements ne créent pas de service (les courriers sont routés à
  des services).
Alternatives envisagées (Services) : page Services dédiée (refusée par l'utilisateur) ;
événements de modèle sur OrganizationUnit (écarté : factories et seeders
créeraient des services en effet de bord) ; fusionner les deux tables
(écarté : toucherait toutes les FK `service_id` existantes).

## [2026-09-23] Chaque action / lecture = un privilège
Contexte : demande explicite de l'utilisateur ("make every action/read on
this app a permission"). Inventaire fait sur chaque méthode d'action des
composants Livewire : toutes étaient déjà autorisées, mais plusieurs
actions distinctes partageaient un même droit (valider = valider +
renvoyer + rejeter ; traiter = démarrer + soumettre ; modifier = modifier +
valider le classement + mots-clés ; regles_classement.gerer = voir + gérer +
réanalyser ; privileges.gerer = assigner + créer un profil) et plusieurs
lectures n'avaient aucun privilège (historique, texte OCR, pièces jointes,
blocs du tableau de bord et de la liste, "Mes courriers", "Courriers
enregistrés").
Décision :
- **Deux couches cumulées, jamais l'une sans l'autre** : les privilèges de
  PORTÉE existants (`…_tout` / `…_service` / `…_propre` / `…_affecte`,
  niveau de confidentialité, dossier, périmètre) disent SUR QUOI on peut
  agir ; les nouveaux privilèges d'ACTION / de LECTURE disent QUOI faire.
  Ex. `rejeter` = `courriers.rejeter` ET `valider()`.
- 26 nouvelles clés (liste dans PrivilegeSeeder, commentaire "Chaque action
  / lecture = un privilège"). Défaut = exactement les profils qui pouvaient
  déjà faire l'action : aucun changement de comportement au déploiement.
- Toute nouvelle action ou lecture ajoutée à l'application doit recevoir sa
  clé de la même façon (ability de Policy + privilège + vérification serveur
  + bouton masqué).
- Non gouvernés, volontairement : navigation/tri/filtres d'affichage d'une
  page déjà autorisée, et son propre compte (Paramètres, Déconnexion).
Alternatives envisagées (actions/lectures) : remplacer les privilèges de portée par des
privilèges d'action (écarté : perdrait "son service" / "ses courriers") ;
une clé par bouton y compris les filtres d'affichage (écarté : aucune
donnée ni action nouvelle derrière).

## [2026-09-23] Page Profils alignée sur Utilisateurs & Accès
Contexte : demande explicite de l'utilisateur ("make profile page as
userlist"). La page Profils utilisait encore le sélecteur + "deux boîtes"
retenu le 2026-09-15, alors que "Utilisateurs & Accès" (2026-09-21) a un
tableau et une modale "Gestion des permissions" groupée par module ; avec 75
privilèges, les deux boîtes devenaient longues à parcourir.
Décision : même présentation que Utilisateurs & Accès — tableau des profils
(utilisateurs, permissions, répartition par type), menu d'actions par ligne
("Gérer les permissions", "Voir les utilisateurs" filtré), et la même
modale par module (case = assignation immédiate au profil, "Tout
sélectionner" limité au module affiché). Créer un profil ouvre directement
ses permissions. Les deux boîtes sont retirées de cette page (elles restent
pour les Destinataires de transfert et le Périmètre d'accès dans
Utilisateurs & Accès). Même garde-fou Administrateur (PrivilegePolicy::gerer()).
Alternatives envisagées : garder les deux boîtes à côté du tableau (écarté :
deux façons de faire la même chose).

## [2026-09-23] Chaque carte du tableau de bord sa propre permission
Contexte : demande explicite de l'utilisateur ("on tableau de board all kpi
and card there should be permission"). Les 5 cartes KPI principales
(Courrier entrant/sortant, En attente, Urgents, En retard) partageaient
toutes `dashboard.statistiques` — impossible de montrer "Urgents" à
quelqu'un sans lui montrer aussi "En retard". Les cartes décoratives
"Notifications" et "Calendrier" du panneau latéral n'avaient QUANT À ELLES
aucune permission — visibles par tout utilisateur connecté, seul le
tableau de bord dans son ensemble (`dashboard.voir`) était gardé.
Décision : `dashboard.statistiques` scindée en 5 clés, une par carte
(`dashboard.courrier_entrant`/`courrier_sortant`/`en_attente`/`urgents`/
`en_retard`), mêmes 4 profils par défaut (Agent, DGA, Responsable de
service, Collaborateur) qu'avant — comportement observé inchangé au
déploiement. Deux clés nouvelles, mêmes profils par défaut :
`dashboard.notifications`, `dashboard.calendrier`. L'ancienne clé partagée
est supprimée du catalogue (pas seulement retirée de la liste — la ligne
`privileges` elle-même, cascade sur ses assignations) plutôt que laissée
comme entrée morte. `dashboard.delai_moyen` et `dashboard.derniers_courriers`
existaient déjà avec leur propre clé (2026-09-23, plus tôt le même jour) et
ne changent pas.
Alternatives envisagées : garder dashboard.statistiques et n'ajouter des
clés que pour Notifications/Calendrier (écarté : ne répond pas à "ALL kpi
and card", et un Responsable de service ne peut de toute façon montrer
"Urgents" à un collaborateur sans "En retard" avec une clé partagée).

**Complément (même jour) — étendu à toutes les pages, une par une.**
Demande explicite : "same thing for all the pages, each card should be a
permission, go one page by page". Audit systématique : grep de tous les
conteneurs "carte" (`rounded-2xl border ... bg-white`, 71 occurrences sur 14
vues) + revue des `#[Computed]` de chaque composant Backend. Seules deux
AUTRES pages avaient un écart du même type que le tableau de bord :
- **"Tous les courriers"** : 4 cartes (Total/En traitement/Terminés/En
  erreur) partageaient `courriers.voir_statistiques` — scindée en
  `courriers.voir_carte_total/en_traitement/termines/en_erreur`, même
  traitement que dashboard.statistiques (clé unique supprimée, pas gardée
  comme entrée morte).
- **"Règles de classement"** : la carte "X courrier(s) sans décision de
  classement" (compteur) n'avait AUCUN privilège — seul le bouton
  "Réanalyser" à côté était gardé (`regles_classement.reanalyser`). Le
  compteur n'a de sens que pour justifier ce bouton : toute la carte est
  désormais derrière le même privilège plutôt que d'en créer un nouveau
  rien que pour un chiffre.
**Décision : pas de nouveau privilège sur les autres pages.** Revues et
écartées : Mes courriers, Courriers enregistrés, file d'attente (Courriers
à traiter), Organisation, Dossiers & Archives, Utilisateurs & Accès,
Profils, ScanPremier, les formulaires d'enregistrement — aucune n'a de
carte de statistiques AGRÉGÉES ni de panneau totalement ungated. Leurs
panneaux "carte" (Informations générales/Parcours du courrier/Détails
complémentaires sur la fiche courrier, arbre + panneau "Détails" sur
Organisation/Dossiers, panneau "Aperçu" sur la file d'attente) sont le
contenu même de l'ÉLÉMENT SÉLECTIONNÉ (un courrier, un dossier, un nœud
d'organigramme) — déjà entièrement couvert par le droit de CONSULTER cet
élément précis, exactement comme "Informations générales" du tableau de
bord (la bannière de bienvenue) n'a jamais eu besoin de sa propre clé. Leur
donner un privilège séparé fragmenterait un même droit de lecture en
plusieurs clés sans qu'aucune nouvelle DONNÉE ne soit réellement isolée —
le piège que DECISIONS.md "Chaque action / lecture = un privilège" mettait
déjà en garde ("une clé par bouton y compris les filtres d'affichage").
Alternatives envisagées : gater aussi les panneaux détail (Informations
générales, Parcours du courrier...) — écarté par cohérence avec ce
précédent et pour éviter la fragmentation sans bénéfice réel.

## [2026-09-23] Paramètres système configurables
Contexte : demande explicite de l'utilisateur — "things like reference
format and that can be configurable those small thing that usually need to
be coded has to [be] done through the UI now". PRD.md/DECISIONS.md
notaient déjà ce manque transversal ("Configuration administrateur des
listes de référence... toutes actuellement codées en dur... contrairement
à l'exigence transversale confirmée par le client", et "délais SLA...
actuellement dans config/gec.php" pour Module 5/7 spécifiquement).
Décision — périmètre retenu pour cette itération :
- **Table singleton `parametres`** (une seule ligne, id=1, créée par la
  migration elle-même) plutôt qu'un magasin clé/valeur générique — colonnes
  typées, même convention que le reste du projet. `App\Models\Parametre::actuel()`
  la lit, mise en cache Redis/DB (Règle n°3 — lue à CHAQUE génération de
  numéro et à CHAQUE sauvegarde de courrier via `Courrier::booted()`).
  **Piège réel rencontré** : mettre en cache l'objet Eloquent complet
  revient parfois en `__PHP_Incomplete_Class` une fois désérialisé par le
  driver `database` (invisible avec le driver `array` des tests) — corrigé
  en ne cachant QUE le tableau d'attributs bruts (même raison que
  `SerializesModels` pour les Jobs en file d'attente : jamais sérialiser un
  modèle Eloquent brut). `invaliderCache()` explicite après chaque écriture,
  jamais un observer caché.
- **Numéro de référence** (`App\Services\NumeroReferenceGenerator`) :
  préfixe et nombre de chiffres de la séquence configurables — remplace le
  `sprintf('GEC-%d-%06d', ...)` codé en dur. La séquence déjà consommée en
  base (`NumeroSequence`) n'est jamais rejouée par un changement de format
  ("le numéro de référence... une fois confirmé ne changera plus jamais",
  PRD.md) — seul l'AFFICHAGE du prochain numéro change.
- **SLA** (`SlaCalculatorService`, `SendMailAlertJob`) : délai par défaut,
  seuil "bientôt en retard", fréquence de relance, ET délai par type de
  document (carte `type_document → jours`, JSON — même pattern déjà utilisé
  par `RegleClassement::mots_cles/champs/tags`) — remplace entièrement
  `config/gec.php` (fichier supprimé, plus jamais lu).
- **Page "Paramètres système"** (`admin/parametres`) : réutilise le
  privilège `administration.sla` déjà existant (l'entrée sidebar "SLA &
  Alertes", jusque-là "Bientôt disponible", devient cette vraie page,
  relabellée en conséquence) — pas de nouvelle clé, mêmes profils par
  défaut (Administrateur seul).
- **Explicitement HORS PÉRIMÈTRE de cette itération** (à faire séparément
  si demandé) : types de document (`CourrierForm::TYPES_DOCUMENT`), modes
  de réception, niveaux de priorité, échelle de confidentialité,
  résolution minimale de scan (`ScanForm::RESOLUTION_MINIMALE`), champs
  surveillés par une règle de classement (`RegleClassement::CHAMPS`) — ce
  sont des LISTES de valeurs utilisées à plusieurs endroits (options de
  `<flux:select>`, comparaisons de code), pas des réglages scalaires
  simples comme le format du numéro ou un délai ; les ajouter exige un vrai
  CRUD de liste de référence (nom + éventuel impact sur les enregistrements
  existants qui utilisent déjà une valeur), un chantier distinct.
Alternatives envisagées : tout mettre dans `.env`/`config/gec.php` avec
juste un rappel "modifiable en éditant le fichier" (écarté : c'est
exactement ce que l'utilisateur demande de ne plus faire) ; un magasin
clé/valeur JSON générique unique pour tout paramètre futur (écarté : moins
lisible/validable que des colonnes typées pour un petit nombre de réglages
connus).

## [2026-09-24] Paramètres système configurables — Groupe A/B
Contexte : suite directe de la décision ci-dessus. Demande à l'utilisateur
"is it the only ones we need to configure" (audité les points HORS
PÉRIMÈTRE listés ci-dessus) → proposition Groupe A (5 réglages numériques
encore codés en dur : `User::NIVEAU_CONFIDENTIALITE_MAX`,
`ScanForm::RESOLUTION_MINIMALE`, `ProcessDocumentOcr::CONFIANCE_MINIMALE`/
`LONGUEUR_MINIMALE_TEXTE`, `RefreshDashboardStatsJob::PERIODE_JOURS`) vs
Groupe B (3 vraies listes de référence nécessitant un CRUD : type de
document, mode de réception, priorité) → l'utilisateur a choisi **les
deux, en une seule itération** ("Group A + Group B").
Décision — Groupe A : chaque `const` devient une méthode statique qui lit
`Parametre::actuel()` (ex. `User::niveauConfidentialiteMax()`), plutôt
qu'une constante ré-exportée ou un alias — la valeur doit pouvoir changer
sans redéploiement, une `const` PHP ne le permet jamais. Mêmes bornes
raisonnables ajoutées côté validation de `ParametreSysteme::enregistrer()`
(ex. confiance OCR 0-100 %) pour rester cohérentes avec le domaine métier
de chaque seuil, pas juste "un entier".
Décision — Groupe B : nouvelle table `listes_reference` (type/valeur/
ordre/actif/**protege**) plutôt que 3 tables séparées — même structure
pour les 3 listes, un seul modèle `ListeReference`. Sémantique de
`protege` (le point le plus important de ce chantier) :
- Bloque **uniquement le renommage** de `valeur` — jamais la désactivation
  ni le réordonnancement. Une valeur protégée reste désactivable/déplaçable
  librement.
- S'applique aux valeurs dont une partie du CODE (pas juste l'UI) compare
  la chaîne exacte : "Sinistre" (`CourrierForm::estUnSinistre()`,
  `str_contains(..., 'sinistre')`, dont dépend le circuit de validation
  DGA — Module 4), "email"/"fax" (détectés et ÉCRITS tels quels par
  `ProcessDocumentOcr`, indépendamment de cette table), "depot_physique"
  (valeur par défaut du formulaire et du flux courrier confidentiel), et
  les 4 priorités de base (`match()` de couleur codés en dur dans
  plusieurs vues Blade, avec un `default =>` de repli).
- Une valeur ajoutée par un administrateur (non protégée par construction)
  reste librement renommable/désactivable/supprimable — seules les valeurs
  seedées par la migration ci-dessus portent `protege=true` là où identifié.
Décision — désactivation : retire uniquement une valeur des **nouveaux**
formulaires (`Rule::in(ListeReference::valeursActives($type))` désormais
dynamique dans `CourrierForm::rules()`) — ne touche JAMAIS aux courriers
déjà enregistrés avec cette valeur (le champ stocke une chaîne, pas une
clé étrangère), ni à la détection OCR (qui écrit ses valeurs littérales
indépendamment de l'état actif/inactif de cette table). Garde-fou
obligatoire : `ParametreSysteme::basculerActif()` refuse de désactiver la
DERNIÈRE valeur active d'une liste (sinon `Rule::in([])` rejetterait tout
nouvel enregistrement) — pas d'équivalent pour le renommage/réordonnancement,
sans risque similaire.
Décision — UI : les actions Groupe B (ajouter/renommer/activer/réordonner)
prennent effet **immédiatement** (comme `UserList::ajouter()`), à la
différence des réglages scalaires (Numéro/SLA/Groupe A) qui restent
groupés derrière le bouton "Enregistrer" unique du formulaire — une liste
de référence n'a pas de sens à "prévisualiser puis valider en bloc" de la
même façon qu'un format de numéro.
Alternatives envisagées : un flag `verrouille` empêchant TOUTE
modification (renommage ET désactivation) sur les valeurs protégées —
écarté, trop restrictif : rien n'empêche un administrateur de vouloir
retirer "fax" des nouveaux formulaires si le service ne l'utilise plus,
tant que le code qui en dépend (l'OCR) continue de fonctionner
indépendamment de cette table. Une colonne `code` interne distincte de
`valeur` (affichage traduisible séparé de la clé métier) — écartée : toute
l'application compare déjà `type_document`/`mode_reception`/`priorite` en
texte brut non traduit (voir décision du 2026-09-23 ci-dessus), ajouter un
niveau d'indirection ici aurait été une incohérence avec le reste du code.

**Complément (même jour) — mise en page.** Demande explicite : "use the
design of organisation for parametre a side panel showing all config with
a main displaying them". La première version (3 cartes empilées, tout
visible en un seul défilement) est remplacée par le même patron à deux
colonnes que la page Organisation : panneau latéral gauche STICKY listant
TOUTES les catégories de paramètres (nav cliquable, même carte que
"Structure de l'organisation"), colonne principale affichant la catégorie
active. Bascule purement côté client (Alpine `x-show`, même patron que les
onglets déjà utilisés sur la fiche courrier/le formulaire de modification)
— aucune propriété Livewire ajoutée, le composant PHP reste inchangé. Un
seul formulaire sous-jacent : "Enregistrer" (placé sous la nav, toujours
visible) soumet toutes les catégories d'un coup, quel que soit l'onglet
actuellement affiché à l'écran — pas de sauvegarde partielle par
catégorie, pour rester cohérent avec la ligne singleton unique en base.

## [2026-09-24] Courrier confidentiel : accès et clôture par le destinataire
Contexte : la simulation du parcours réel (tests/Feature/Courriers/
SimulationParcoursReelTest.php, demandée par l'utilisateur) a montré qu'un
pli enregistré via RegistrationFormConfidentiel était une impasse : envoyé
"directement" à la DGA (statut `enregistre`, sans service), il lui était
refusé (403) — la branche `voir_dga` de CourrierPolicy::view() ne couvre
que `en_cours_de_transfert` —, n'apparaissait dans aucune liste, n'offrait
aucune action et n'était donc jamais archivé. L'utilisateur a demandé de
corriger ("fix all of them").
Décision :
- Colonne explicite `courriers.confidentiel_direct` (posée par
  RegistrationFormConfidentiel, rattrapage des plis existants dans la
  migration) plutôt que de déduire ce cas de service_id null +
  destinataire_transfert_id + statut, trop fragile.
- Le DESTINATAIRE désigné (et lui seul) consulte le pli, quel que soit son
  statut (il le garde après archivage) — privilège de portée
  `courriers.voir_confidentiel_recu`, miroir dans Courrier::scopeVisiblePar()
  (le pli apparaît donc dans sa file "À traiter"). Le niveau de
  confidentialité reste vérifié : un destinataire de niveau insuffisant
  reste bloqué (l'administrateur doit relever son niveau).
- Bouton "Marquer comme remis" (privilège `courriers.cloturer_confidentiel`)
  → `WorkflowService::cloturerConfidentiel()` : `enregistre` → `traite` +
  historique `confidentiel_remis`, puis archivage automatique habituel.
- Défaut des 2 clés : DGA + Responsable de service (destinataires typiques
  cités par le spec Module 1 : DGA/ADJ DGA, RH).
Alternatives envisagées : ajouter `enregistre` → `traite` dans
WorkflowService::TRANSITIONS (écarté : valider() permettrait alors à un
responsable de clore un courrier normal sans affectation ni traitement —
"un courrier ne peut pas sauter une étape") ; faire affecter le pli par
le destinataire (écarté : le spec dit que le pli n'entre dans aucun
circuit ni service, seul son destinataire l'ouvre).

## [2026-09-24] Dossier de classement : les acteurs du circuit gardent l'accès
Contexte : même simulation — dès qu'un collaborateur rangeait un courrier
dans son dossier personnel, le responsable du service ET l'agent qui
l'avait enregistré perdaient l'accès (application stricte de "les trois se
cumulent", 2026-09-16) : un collaborateur pouvait ainsi masquer un courrier
à son propre responsable, et le responsable ne pouvait plus le valider.
Décision (amende la décision du 2026-09-16) : le gate dossier ne
s'applique pas aux personnes directement impliquées dans CE courrier —
`Courrier::impliqueUtilisateur()` : son créateur, le collaborateur
actuellement affecté, le responsable de son service, le destinataire de
son transfert. Miroir exact dans le bloc dossier de scopeVisiblePar().
Cette exemption ne DONNE aucun accès : les privilèges de portée de view()
et le niveau de confidentialité s'appliquent toujours ensuite. Le dossier
continue de restreindre tous les autres utilisateurs.
Alternatives envisagées : n'exempter que le responsable du service
(écarté : l'agent créateur et le collaborateur affecté par la suite
seraient bloqués de la même façon) ; interdire de classer un courrier
encore en circuit (écarté : "Classer dans un dossier" est volontairement
disponible à tout moment, voir 2026-09-22).

## [2026-09-24] Dossier surveillé : configuration dans l'administration
Contexte : pendant la simulation manuelle, l'utilisateur a déposé deux scans
dans le dossier surveillé ; ils ont bien été importés et lus par l'OCR en
~20 s, mais la page Numérisation ne les montrait qu'après rechargement
(`ScanPremier::numeriserAutomatique()` était `#[Renderless]` et la page
n'écoutait pas `brouillon.ocr.termine`). Il a aussi demandé de déplacer
"Démarrer la surveillance" dans le menu Administration, comme un réglage.
Décision :
- Nouvelle page **Administration › Dossier surveillé** (privilège
  `administration.dossier_surveille`, Administrateur par défaut) :
  choisir le dossier, démarrer/arrêter, réautoriser l'accès, retirer.
- `scan-watcher.js` a deux modes : `config` (cette page — ne fait jamais
  d'import) et `execution` (Numérisation, Nouveau courrier — importe, sans
  aucun bouton de configuration). Drapeau `actif` persistant dans
  IndexedDB ; un changement fait dans un onglet est appliqué aux autres
  via BroadcastChannel, sans rechargement.
- La configuration RESTE dans le navigateur (par poste, par profil
  Chrome/Edge) : l'API File System Access n'autorise un dossier que sur
  l'ordinateur où il a été choisi. La page le dit explicitement — l'admin
  fait le réglage sur le poste de la réception.
- Numérisation : `#[Renderless]` retiré (même choix que RegistrationForm,
  déjà validé en navigateur) + écouteur echo-private de fin d'OCR.
Alternatives envisagées : stocker le chemin du dossier en base et importer
côté serveur par une tâche planifiée (écarté pour l'instant : le serveur ne
voit pas le disque du poste de la réception, il faudrait un partage réseau
— à reconsidérer au déploiement si le scanner peut écrire sur un partage
accessible au serveur) ; laisser le bouton aussi sur Numérisation (écarté :
demande explicite de l'utilisateur d'en faire un réglage d'administration).

## [2026-09-24] DGA : consultation des courriers transférés
Contexte : demande explicite de l'utilisateur ("dga doesn't see all the
courier he transfered"). Depuis le 2026-09-08, la DGA ne voyait un courrier
que pendant l'étape `en_cours_de_transfert` ("périmètre = ce qui la
concerne actuellement") : dès le service validé, il disparaissait de toutes
ses listes et sa fiche lui était refusée.
Décision (amende 2026-09-08) : nouveau privilège de portée
`courriers.voir_transferes` (DGA par défaut) — la DGA garde la CONSULTATION
de tout courrier qu'elle a transféré : destinataire du transfert, ou auteur
de la validation du service dans l'historique (couvre les courriers
antérieurs à `destinataire_transfert_id`). Lecture seule : aucune autre
ability ne s'appuie dessus ; `validerService` exige désormais aussi le
statut `en_cours_de_transfert`. Ces courriers restent HORS de sa file
"À traiter" (`visiblePar($user, false)` dans WorkflowQueue) : ils ne
l'attendent plus.
Alternatives envisagées : réutiliser `courriers.voir_dga` sans condition de
statut (écarté : mélangerait "à traiter" et "historique", et la file de la
DGA se remplirait de courriers traités par les services) ; lui donner
`courriers.voir_tout` (écarté : bien plus large que ce qu'elle a transféré,
et sans rapport avec la demande).

## [2026-09-24] Suppression de la page "Transferts" (file d'attente)
Contexte : demande explicite de l'utilisateur ("remove the transfer page
since it same with tous les courier"). La file WorkflowQueue (menu
"Transferts", /courriers/a-traiter) affichait le même périmètre que "Tous
les courriers" (Courrier::scopeVisiblePar()), seulement restreint aux
statuts encore en circuit.
Décision : page, composant et privilège `courriers.voir_file_attente`
supprimés. "Tous les courriers" reçoit une option de statut "En cours
(tous)" (`statut=actifs`) qui reproduit exactement ce filtre ; l'ancienne
adresse redirige dessus. La DGA retrouve ce qui attend sa validation via
le filtre "En cours de transfert" (ou Courriers enregistrés), et suit ses
transferts via l'onglet "Suivi". La notion "à traiter pour moi" reste dans
les Tâches du jour du tableau de bord (`visiblePar($user, false)`).
Alternatives envisagées : garder la page sans l'entrée de menu (écarté :
code mort) ; rediriger sans ajouter de filtre (écarté : la redirection
aurait montré aussi les courriers clôturés, perdant le seul apport réel de
l'ancienne page).

## [2026-09-24] "En retard" (Module 5/10) : cohérence avec le chronomètre
Décision : tranchée par l'utilisateur ("fixed all as you see fit") — voir
l'entrée précédente pour le contexte complet du constat. Choix retenu :
(b), `scopeEnRetard()` reflète désormais EXACTEMENT ce que
`Courrier::chronoFin()` affiche sur le badge chronomètre (chrono_fin_le à
la minute près quand un délai a été fixé par le responsable, sinon
comportement STRICTEMENT INCHANGÉ — la comparaison à la journée équivalait
déjà exactement à `date_limite->endOfDay()`, le cas par défaut de
chronoFin()). Justification : le chronomètre est une fonctionnalité
délibérément précise à la minute (demande explicite de l'utilisateur plus
tôt le même jour) ; laisser la carte KPI et les alertes SLA en retard
d'un jour entier sur ce que le responsable a lui-même fixé contredit
l'intention même de donner un délai précis. Effet de bord assumé :
`SendMailAlertJob` peut désormais alerter dans l'heure suivant un délai
fixé par le responsable plutôt qu'au plus tôt le lendemain — pour les
courriers SANS délai fixé (l'immense majorité, SLA classique par type),
aucun changement de comportement.

## [2026-09-24] (contexte du constat ci-dessus) "En retard" à la journée près, chronomètre à la minute près
Contexte : audit du tableau de bord demandé par l'utilisateur ("find bugs
... and simulate it"), simulation avec un courrier dont le délai a été
fixé par le responsable il y a 3 h (donc affiché "EN RETARD" par son
propre chronomètre, voir plus haut "Chronomètre de traitement") : la carte
KPI "En retard" du tableau de bord ne le compte PAS, car
`Courrier::scopeEnRetard()` compare `whereDate('date_limite', '<',
today())` — une comparaison à la JOURNÉE près, alors que `date_limite` est
recalculée à la journée même quand le responsable a fixé une échéance
précise à l'heure près. Les deux indicateurs (badge chronomètre sur une
fiche, carte KPI du tableau de bord) peuvent donc afficher des messages
contradictoires pour le MÊME courrier, le même jour.
**Pas corrigé automatiquement** : `scopeEnRetard()` est aussi utilisé par
`SendMailAlertJob` (Module 7, alertes SLA) — le rendre plus strict
(granularité à l'heure) changerait aussi À QUELLE HEURE une alerte de
retard part, pas seulement l'affichage du tableau de bord. Décision à
prendre avec l'utilisateur :
- (a) garder la granularité JOUR pour "en retard" partout (le
  chronomètre, plus précis, resterait le seul indicateur fin — la carte
  KPI et les alertes gardent une tolérance jusqu'à minuit) ;
- (b) faire de `scopeEnRetard()` un reflet exact du chronomètre
  (`chrono_fin_le` si fixé, sinon fin de journée de `date_limite`),
  cohérent partout, mais des alertes SLA potentiellement envoyées en plein
  après-midi au lieu du lendemain.

## [2026-09-24] Chronomètre de traitement
Contexte : demande explicite de l'utilisateur — le responsable de service
ne pouvait pas fixer le délai d'un courrier (la page "Modifier" exige
`modifier_propre/tout`, qu'il n'a pas) ; le délai venait seulement du SLA
par type (Paramètres système). Précisions : "it should be as a
chronometer", "it appear as chronometer in the sla detail".
Décision :
- Le responsable fixe le délai DANS le formulaire "Affecter" (jours ou
  heures, prérempli avec le SLA actuel), puis peut le modifier en cours de
  circuit avec un motif obligatoire. Privilège d'action
  `courriers.fixer_delai` (Responsable de service), cumulé avec la portée
  d'affectation (CourrierPolicy::fixerDelai()).
- Nouvelles colonnes `chrono_debut_le` / `chrono_fin_le` (indexée) /
  `chrono_arrete_le` : `date_limite` reste une DATE (SLA quotidien,
  alertes, tris) et SUIT la fin du chronomètre via `echeance` — une seule
  vérité pour les listes et les alertes, la minute près pour l'affichage.
- Le chronomètre part quand le délai est fixé (sinon il s'appuie sur la
  date limite SLA, fin de journée), compte le dépassement au-delà, et se
  FIGE à la clôture (traité/rejeté/pli confidentiel remis) sur le temps
  réellement passé.
- Affichage : composant `<x-chronometre>` dans les détails SLA de la fiche
  (grand) et dans les Tâches du jour (compact). Compte à rebours 100 %
  navigateur (Alpine), recalé sur l'heure du serveur — aucune requête par
  seconde, jamais de wire:poll (Règle n°2).
Alternatives envisagées : passer `date_limite` en datetime (écarté :
touche le SLA quotidien, les alertes, les index et une migration de
rattrapage pour un gain d'affichage) ; ouvrir la page "Modifier" au
responsable (écarté : donnerait accès à tous les champs de
l'enregistrement, pas seulement au délai) ; mettre le chronomètre en pause
pendant "en attente d'information" (hors périmètre phase 1, déjà noté dans
SlaCalculatorService).

## [2026-09-24] Pentest "Tous les courriers" — IDOR sur extraitTexteOcr()
Contexte : demande explicite de l'utilisateur ("now total courier go
throughtly even pentest it") — revue de sécurité ciblée du composant
`CourrierList` (module 8/9). Faille réelle trouvée : `extraitTexteOcr(Courrier
$courrier)` type-hintait un modèle Eloquent en paramètre d'action Livewire ;
Livewire résout ce genre de paramètre via `resolveRouteBinding()` (un simple
`Courrier::find($id)`) AVANT d'entrer dans la méthode, sans jamais passer par
`Courrier::visiblePar()` ni une policy — contrairement à toutes les autres
méthodes du même fichier (`courrierApercu()`, `supprimerCourrier()`,
`transfererSelection()`, `classerSelection()`), qui revérifient explicitement
tout id reçu du client (Règle n°6 de CONVENTIONS.md). Un utilisateur disposant de
`courriers.voir_texte_ocr` (Responsable de service par défaut, voir
PrivilegeSeeder) pouvait donc appeler cette méthode directement avec l'id
d'un courrier hors de son périmètre (autre service, confidentiel...) — en
contournant entièrement l'UI, qui ne l'affiche jamais dans son tableau — et
lire jusqu'à ~120 caractères de son texte OCR.
Décision : revérifier `CourrierPolicy::voirTexteOcr()` (qui inclut déjà
`view()`, donc confidentialité + accès dossier + périmètre de service) au
début de `extraitTexteOcr()`, au lieu du seul `peutRechercherContenu`
(privilège global, insuffisant seul). Prouvé par un test qui échoue sur le
code non corrigé (extrait effectivement lu) puis passe après le correctif.
Reste du composant audité à la même occasion (chaque action de masse, le
scope de `resultats()`/`statistiques()`, le tri whitelisté, les recherches
LIKE/FULLTEXT paramétrées) : aucune autre faille trouvée, tout revérifie déjà
correctement côté serveur. Voir aussi la correction de cohérence apportée à
`PieceJointeDownloadController` le même jour (garde-fou fichier manquant, pas
une faille — juste harmonisé avec les deux autres contrôleurs de document).

## [2026-09-24] Pentest "Détail/Modifier" — IDOR sur EditForm::courrier()
Contexte : suite directe du pentest ci-dessus, demande explicite de
l'utilisateur ("have go throught the view/edit too") — même méthodologie
appliquée à ShowCourrier (fiche "Détail") et EditForm (fiche "Modifier").
ShowCourrier : RAS, chaque action revérifie déjà l'autorisation via
`courrierPour()`/`courrierModifiable()`, le panneau "Aperçu" (`courrier()`)
revérifie déjà `view` à chaque accès, et le canal Echo privé
`courrier.{courrierId}` (routes/channels.php) est déjà gardé par
`$user->can('view', $courrier)`.
EditForm : faille réelle trouvée, même classe que celle d'extraitTexteOcr()
(CourrierList, plus tôt le même jour) mais par un mécanisme différent.
`$courrierId` est une propriété publique SANS `#[Locked]` — confirmé en
lisant `Livewire\Features\SupportLockedProperties\BaseLocked` dans le
vendor : cet attribut n'existe QUE pour empêcher un client de modifier une
propriété publique directement depuis une requête forgée, et n'était posé
sur aucun `courrierId` du projet. `mount()` n'autorise `update` qu'UNE FOIS
au chargement initial de la page ; le `#[Computed] courrier()` qui alimente
ensuite le panneau "Aperçu" (expéditeur, statut, confidentialité, pièces
jointes, numéro) rechargeait le courrier depuis `$this->courrierId` à
CHAQUE accès SANS jamais revérifier l'autorisation — contrairement à
`ShowCourrier::courrier()`, qui applique exactement ce contrôle sur la même
page sœur. Un utilisateur ayant légitimement accès à SA page d'édition
pouvait donc reprogrammer `courrierId` vers un courrier hors de son
périmètre et voir son détail dans le panneau, sans repasser par la policy
(le chemin d'écriture `enregistrerModification()` restait, lui, protégé —
il refait son propre `findOrFail()` + `authorize('update')`).
Décision : ajouter `$this->authorize('view', $courrier)` dans `courrier()`,
exactement le même correctif que `ShowCourrier::courrier()` applique déjà —
pas une nouvelle convention, l'alignement d'un oubli sur un pattern déjà
correct ailleurs dans le même module. Prouvé par un test qui échoue sur le
code non corrigé puis passe après le correctif.
Balayage de tout `app/Livewire` (grep `findOrFail($this->xId)`/
`::find($this->xId)`) effectué à cette occasion : DossierClassementList,
OrganisationIndex, ProfilList, ParametreSysteme, ScanForm, RegleList,
UserList, `RegistrationForm::brouillon()` autorisent déjà correctement à
chaque accès — aucune autre instance de cette faille trouvée dans le
projet.

**Correction (2026-09-24, plus tard le même jour)** : ce balayage était
incomplet — le pattern `::find($this->xId)` ne capture qu'un appel STATIQUE ;
`DossierClassementList::dossierAPartager()` utilisait
`->find($this->dossierAPartagerId)` chaîné après `->with(...)`, une forme que
le grep ne matchait pas, et contenait bien la même faille (voir entrée
"Pentest 'Dossiers & Archives'" plus bas). Leçon retenue : un grep sur un
motif syntaxique précis ne remplace pas une lecture complète page par page —
utile pour une première passe, jamais suffisant seul pour clore un audit.

## [2026-09-24] Pentest "Dossiers & Archives" — 2 fuites dans la modale Partager
Contexte : suite du pentest page par page ("next page then"), après
ScanForm (RAS). Deux faiblesses trouvées dans `DossierClassementList`,
toutes deux dans la modale "Partager" (deux boîtes, noms/emails).
**(1) IDOR, même mécanisme que EditForm::courrier()** — `dossierAPartager()`
chargeait le dossier depuis `$this->dossierAPartagerId` (propriété publique
sans `#[Locked]`) sans jamais revérifier l'autorisation, contrairement à
`dossierSelectionne()` juste au-dessus qui revérifie déjà `view`. La modale
étant rendue sans condition dans la vue, changer cette propriété suffisait
à faire fuir les bénéficiaires d'un dossier hors périmètre.
**(2) Fuite plus large, PRÉ-EXISTANTE, découverte en corrigeant (1)** —
`partageDisponibles()` n'avait pas le garde-fou "`$dossier` null → liste
vide" que `partageAssignes()` a pourtant juste en dessous. Résultat : sans
dossier sélectionné — l'état PAR DÉFAUT au tout premier chargement de la
page, avant même d'avoir cliqué une seule fois sur "Partager" — cette
méthode listait TOUS les utilisateurs de l'organisation (nom + email) sans
filtre. Combinée au rendu sans condition de la modale, cette fuite touchait
CHAQUE chargement de "Dossiers & Archives", pour quiconque a seulement
`dossiers_classement.voir` (accordé largement : Agent/DGA/Responsable de
service/Collaborateur) — aucun droit de partage nécessaire pour la
déclencher. C'est la fuite la plus sérieuse trouvée dans cette série de
pentests : aucune action du client n'était même requise.
Décision : `dossierAPartager()` revérifie désormais
`Auth::user()->can('partager', $dossier)`, comme `dossierSelectionne()` le
fait déjà pour `view` ; `partageDisponibles()` reçoit le même garde-fou
"`$dossier` null → `collect()` vide" que `partageAssignes()`. Les 4 actions
d'écriture (ajouterPartage/retirerPartage/ajouterSelectionPartage/
retirerSelectionPartage) remplacent leur `$this->authorize('partager',
$dossier)` — qui aurait échoué bruyamment sur un `$dossier` maintenant
`null` pour le cas non autorisé — par un simple `if ($dossier === null)
return;`, puisque le computed porte désormais toute l'autorisation réelle.
Les deux prouvés par des tests qui échouent sur le code non corrigé et
passent après. Reste du composant (arborescence, contenu du dossier,
création/renommage/déplacement/suppression, ajout de courriers) déjà
correctement scopé — aucune autre faille trouvée.

## [2026-10-02] Restyle de l'aperçu document — chrome de la maquette repris littéralement, contenu de la maquette jamais repris
Demande explicite de l'utilisateur : restyler les 8 instances existantes de
l'aperçu document (showCourrier/courrierList/editForm/registrationForm,
panneau compact + modale "Agrandir" de chacune) pour qu'elles ressemblent à
`replica.html`, une maquette capturée d'un aperçu de pièce jointe Outlook/
Word réel, avec ses couleurs/polices/rayons comme source de vérité visuelle
pour ce composant précis — volontairement différents de la palette
`--color-brand-*` du reste de l'application (voir les notes
internes), scopés aux 4 nouveaux composants `resources/views/components/
apercu/*.blade.php` (barre-outils, visionneuse, panneau-correspondance,
signature-expediteur), jamais propagés ailleurs.
Deux écarts volontaires par rapport à la demande, décidés sans bloquer sur
une confirmation (jugés non ambigus) :
- **Avatar/bandeau personnels de la maquette NON réutilisés.** L'e-mail
  d'exemple de `replica.html` est un VRAI e-mail d'un VRAI collaborateur
  NSIA (nom, photo, signature complète) capturé tel quel. Les réutiliser
  comme image "par défaut" affichée pour N'IMPORTE QUEL courrier de
  N'IMPORTE QUEL expéditeur, pour toute l'entreprise, aurait détourné la
  photo d'une personne réelle de son contexte sans rapport avec le
  courrier affiché. `<flux:avatar>` à initiales est utilisé à la place
  (même convention que `resources/views/components/desktop-user-menu.blade.php`).
  Seul le logo NSIA (`public/images/apercu/nsia-logo.png`) a été extrait —
  actif de marque de l'entreprise elle-même, propriétaire de cet outil.
- **Pas de "corps de message" dans le panneau "correspondance".** Un
  courrier GEC n'est pas un e-mail : aucun champ du modèle Courrier ne
  correspond à un texte d'accompagnement (`texte_ocr` est le texte DU
  DOCUMENT scanné lui-même, pas un message séparé — l'afficher comme corps
  de message aurait été trompeur). Le panneau affiche donc uniquement objet/
  expéditeur/destinataire/date/pièce jointe réels, plus une carte
  "signature" (nom/fonction/organisation/adresse/téléphone/email de
  l'expéditeur), qui est la correspondance la plus proche des champs
  réellement saisis sur un courrier.
Effet de bord découvert en re-testant : le bouton "Voir en plein écran" du
panneau compact de showCourrier (texte visible, hors du nouveau chrome)
faisait doublon avec l'icône "agrandir" du nouveau chrome — supprimé, seule
l'icône du chrome subsiste (même patron que les 3 autres pages, qui
n'avaient jamais eu ce bouton texte séparé).

## [2026-10-06] Entretien terrain réceptionniste — confirmations et un point net-nouveau
Contexte : premier vrai entretien terrain mené avec une réceptionniste Nsia
(guide d'entretien construit le 2026-09-?? pour ce module), dont les
réponses recoupent plusieurs choix déjà pris dans ce projet et en ajoutent
un nouveau, jamais discuté jusqu'ici : le plan de délégation en l'absence du
DGA ET de l'Adjoint DGA simultanément.
Décision :
- **Confidentiel = mention "CONFIDENTIEL" visible sur l'enveloppe**
  (exemples cités : RH, retour de contrat, DAO). Confirme et précise la
  règle déjà en place (PRD §1 point 2 / CONVENTIONS.md) : la détection ne
  nécessite pas d'ouvrir le pli, elle est purement visuelle côté
  réceptionniste — aucun changement de code nécessaire, juste une
  confirmation terrain que la règle existante est la bonne.
- **Absence simultanée DGA + Adjoint DGA → bascule informelle vers les RH**
  aujourd'hui (processus papier). POINT NET-NOUVEAU, jamais tranché dans ce
  projet (voir notes internes, qui ne couvrait que
  le cas DGA seul absent). Pas encore implémenté côté workflow — à
  reprendre explicitement avant de considérer le Module 4 (circuit de
  validation) complet : il faudra un mécanisme de délégation (DGA → RH)
  déclenché par une indisponibilité des deux rôles, pas seulement un
  privilège statique supplémentaire sur les RH.
- **La recherche (texte/objet/expéditeur/date, Module 8) est perçue comme
  LA fonctionnalité à plus fort gain de temps.** Confirme que l'OCR et
  l'indexation plein texte (déjà livrés, voir notes internes)
  doivent rester fiables en priorité pour l'adoption au pilote — pas un
  changement de scope, un signal de priorité.
- **Volumes réels observés** : 14 à 100 courriers/jour à la réception selon
  le jour (pics lundi/vendredi), soit environ 200 à 860+/semaine à l'échelle
  de l'entreprise. Amplitude large, à affiner avec d'autres entretiens avant
  de s'en servir pour dimensionner la charge (Règle n°1).
- **Trajet scan → DGA/ADJ ≈ une demi-journée aujourd'hui**, sans perte
  constatée ensuite jusqu'à la distribution au service (preuve de remise =
  signature papier, non recherchable). Objectif implicite du dossier
  surveillé/OCR (déjà livré) : réduire ce délai, pas le fiabiliser — il
  n'y avait pas de problème de perte à corriger.
- **Automatisation prioritaire selon le terrain** : les dossiers de
  remboursement santé — à garder en tête si une phase ultérieure du Module
  3 (règles de classement) ou une extension cible un type de courrier en
  particulier.
Alternatives envisagées : aucune — ce sont des constats d'entretien, pas un
choix d'architecture ; seul le point "délégation DGA+ADJ absents" ouvre une
vraie décision à prendre plus tard (non tranchée ici, juste consignée pour
ne pas être oubliée).

## [2026-10-06] Tests de charge/concurrence réels (2 cycles casse → correctif → re-test) + max_connections MySQL relevé
Contexte : demande explicite de l'utilisateur ("TEST WITH DATA UNTIL THE
SYSTEM WANT TO BREAK THEN STOP REPAIRE IT AND CONTINUE THE TESTING"), suite
aux questions sur la capacité réelle (14-100 courriers/jour, 200-390/semaine,
voir entretien terrain ci-dessus). Série de tests RÉELS contre la vraie base
`gec` (toujours avec données taguées + nettoyage complet vérifié après coup,
jamais laissés en l'état) :
- **OCR/queue (volume réaliste, 100 documents réels via Dompdf → Ghostscript
  600dpi → Tesseract double-passe)** : ~9-10s/job, 0 échec, 100/100 réussis.
  Découverte en cours de route : Herd fait déjà tourner son propre
  `queue:work` permanent en arrière-plan pour ce site (process actif depuis
  le 2026-10-02) — mes jobs ont donc été traités par 2 workers concurrents
  sans que je l'aie demandé. À savoir pour toute future mesure de débit.
- **Concurrence réelle (processus OS séparés et simultanés, pas une boucle)**
  sur `NumeroReferenceGenerator::generer()` + `Courrier::create()` — le point
  précis que Règle n°3 de CONVENTIONS.md dit protégé par contrainte d'unicité en
  base : 10, 30, 60, 150 processus simultanés → 0 doublon, 0 erreur à chaque
  palier.
- **CASSE #1** à 300 processus simultanés : `SQLSTATE[08004] [1040] Too many
  connections` (défaut MySQL `max_connections=151`, XAMPP jamais ajusté
  depuis l'installation). 149/300 postes échoués. **Corrigé** (approbation
  explicite de l'utilisateur après qu'une 1ère tentative sans confirmation
  a été bloquée par le classifieur auto-mode) : `max_connections` relevé à
  500 (`SET GLOBAL`, effet immédiat + persisté dans `C:\xampp\mysql\bin\
  my.ini`, survit à un redémarrage MySQL). Re-test à 300 : 0 erreur.
- **CASSE #2** à 600 processus simultanés : même erreur, au nouveau plafond
  (≈500-600). **Corrigé** : relevé à 1000 (même procédure, `my.ini` mis à
  jour une 2e fois). Re-test à 600 : 0 erreur. Poussé ensuite à 1000
  processus simultanés / 10 000 courriers : 0 erreur, 0 doublon de
  `numero_reference` sur les 10 000.
- **Performance des pages réelles à grande échelle** : les ~28 000 courriers
  de test accumulés pendant ces vagues ont servi à chronométrer en HTTP réel
  (curl, session authentifiée) `/dashboard`, `/courriers/enregistres`,
  `/courriers/rechercher` (avec et sans filtre) — tous sous 1s après le
  premier hit (le premier chargement de `/courriers/rechercher` à 2.6s était
  un simple échauffement OPcache/première compilation, pas une régression —
  confirmé en répétant 4 fois, toujours <0.55s ensuite). Aucune dégradation
  de Règle n°3 (pagination/eager loading) détectée à cette échelle, bien
  au-delà du volume réel attendu.
Décision : `max_connections=1000` conservé en l'état (large marge au-dessus
du besoin réel — même 1000 inscriptions simultanées, un volume jamais
observé dans l'entretien terrain, passent sans erreur). `innodb_buffer_pool_size`
et les autres réglages de `my.ini` (hérités tels quels du fichier d'exemple
XAMPP "small systems <= 64M") n'ont PAS été revus dans ce passage — seul
`max_connections` a été touché, sur demande explicite ; à reconsidérer
globalement avant un déploiement réel si le serveur de production n'est pas
XAMPP.
Alternatives envisagées : laisser `max_connections=151` et documenter la
limite sans la changer (écarté — demande explicite de pousser jusqu'à la
casse PUIS corriger) ; automatiser une détection applicative de ce cas
(retry avec backoff côté `NumeroReferenceGenerator`, qui ne retente qu'une
fois sur `QueryException` générique) — pas nécessaire ici, la vraie cause
était un plafond serveur sous-dimensionné, pas un défaut du code applicatif.
