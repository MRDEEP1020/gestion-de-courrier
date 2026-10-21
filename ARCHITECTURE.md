# ARCHITECTURE.md — GEC

## Stack

- Laravel 11/13, Livewire 3, Flux UI Pro, Alpine.js, Tailwind CSS v4
- Queue/Cache : Redis + Laravel Horizon
- Stockage documents : stockage hybride — un disque S3-compatible **primaire**
  (MinIO en dev/local, lu et écrit par toute l'application) + un second disque
  `s3_backup` recevant une **réplication asynchrone** de secours (cloud réel
  en pilote/production) — jamais le disque local du serveur applicatif, et
  jamais de réplication synchrone bloquante (voir DECISIONS.md "Stockage hybride")
- Base de données : MySQL (décidé — voir DECISIONS.md)

## Principe directeur

Tout traitement qui n'a pas besoin d'une réponse HTTP immédiate part en Job asynchrone.
C'est la règle n°1 du projet — voir CONVENTIONS.md pour le détail d'application.

## Structure de dossiers (MVC Laravel classique — volontairement à plat)

Choix : pas de sous-dossiers par module (Courriers/, Workflow/, Dashboard/...) —
tout est à plat dans son type (Models, Livewire, Jobs, Policies). Plus proche du
Laravel par défaut, plus simple à naviguer pour un seul développeur. Le nom du
fichier suffit à identifier le module concerné (voir commentaires).

```
app/
  Models/
    Courrier.php
    CourrierHistorique.php
    PieceJointe.php
    Service.php
    Affectation.php
    NumeroSequence.php
    CourrierBrouillon.php          # Module 1/2 — flux scan-first, table isolée de Courrier
    SlaRegle.php
    RegleClassement.php            # Module 3 — règle admin (mots-clés → type/service/tags)
    MotCle.php                     # Module 3 — tag secondaire (pivot courrier_mot_cle)
    Profil.php                     # Agent, Collaborateur, Responsable de service, Administrateur
    User.php                       # service_id (Module 4/6) — appartenance, distincte de services.responsable_id
  Livewire/
    Backend/                        # Composants Livewire GEC (noms en anglais, voir DECISIONS.md)
      RegistrationForm.php           # Module 1 — accepte ?brouillonId= (flux scan-first)
      EditForm.php                   # Module 1
      ScanForm.php                    # Module 2 — numériser un courrier déjà créé
      ScanPremier.php                  # Module 1/2 — scan-first, sans courrier existant
      ShowCourrier.php                # Module 1/2/3/4/6/9 — fiche : OCR, classement, tags, circuit, historique
      RegleList.php                   # Module 3 — administration des règles de classement
      WorkflowQueue.php               # Module 4 — file d'attente ("courriers à traiter")
      CourrierList.php                # Module 8 — recherche multi-critères
      Dashboard.php                   # Module 10 — tableau de bord (retards, délai moyen via RefreshDashboardStatsJob)
      ValidationCircuit.php           # Stub vide du scaffold, jamais routé (Module 4 = WorkflowQueue + ShowCourrier) — à supprimer
      DashboardHome.php               # Stub vide du scaffold, jamais routé (remplacé par Dashboard.php) — à supprimer
      Forms/
        CourrierForm.php              # Form Object partagé (Règle n°2)
    Settings/, Actions/              # Starter kit (inchangé, ne pas déplacer)
  Http/Controllers/
    CourrierBordereauController.php # Module 1 — bordereau PDF (dompdf)
    PieceJointeDownloadController.php
  Console/Commands/
    ReanalyserCourriersCommand.php # Module 3 — `courriers:reanalyser [--tous]`
  Console/Commands/
    NettoyerBrouillonsCommand.php  # Module 1/2 — courriers:nettoyer-brouillons (pas planifiée par défaut)
  Jobs/
    ProcessDocumentOcr.php         # Module 2 — OCR d'un Courrier déjà créé
    ProcessBrouillonOcr.php        # Module 1/2 — OCR d'un brouillon (flux scan-first), réutilise les méthodes statiques de ProcessDocumentOcr
    IndexCourrierJob.php           # Module 3 — un courrier
    ReanalyserCourriersJob.php     # Module 3 — parcourt les courriers par paquets, délègue à IndexCourrierJob
    ReplicateFichierJob.php        # Règle n°4 — copie asynchrone disque primaire → s3_backup
    SendMailAlertJob.php           # Module 7
    RefreshDashboardStatsJob.php   # Module 10
  Policies/
    CourrierPolicy.php             # Module 9 — vérifie $user->profil, jamais $user->role
    CourrierBrouillonPolicy.php    # Module 1/2 — "panier personnel", cree_par_id
    RegleClassementPolicy.php      # Module 3 — Administrateur uniquement
  Events/
    OcrTermine.php                 # Module 2 — diffusé sur courrier.{id}
    BrouillonOcrTermine.php        # Module 1/2 — diffusé sur brouillon.{id}
    ClassementPropose.php          # Module 3 — idem
  Services/
    ClassificationService.php      # Module 3 — classer() + extraireMotsCles() (point d'extension ML phase 2)
    WorkflowService.php            # Module 4/6 — machine à états du circuit, (ré)affectation, charge par collaborateur
    SlaCalculatorService.php       # Module 5
    NumeroReferenceGenerator.php   # Module 1
    PieceJointeService.php         # Module 1
  Notifications/
    CourrierEnRetardNotification.php
routes/
  console.php                      # Scheduler (relances, calcul SLA)
database/
  migrations/
  seeders/
    ProfilSeeder.php                # 4 profils phase 1
    ServiceSeeder.php               # 14 services réels (tampon papier) — voir DECISIONS.md
tests/
  Feature/
  Unit/
resources/views/livewire/
  frontend/                        # Vues Blade des composants GEC (namespace de vue `frontend::`)
    registrationForm.blade.php
    editForm.blade.php
    scanForm.blade.php
    scanPremier.blade.php
    showCourrier.blade.php
    regleList.blade.php
    workflowQueue.blade.php
```

Note : `app/Livewire/Backend` regroupe la logique (PHP) des composants Livewire
GEC, `resources/views/livewire/frontend` leurs vues Blade — séparation
logique/affichage, pas une séparation par module métier (qui reste à plat à
l'intérieur de chacun de ces deux dossiers). Livewire ne connaît par défaut que
`App\Livewire` : `App\Livewire\Backend` et le namespace de vue `frontend::`
sont enregistrés explicitement dans `AppServiceProvider::boot()`.
`Forms/` et `Services/` restent des sous-dossiers *par type*, pas par module.

## Modèle de données — grandes lignes

- `courriers` : numéro de référence unique (index unique DB), sens, expéditeur,
  destinataire, objet, type, service_id, statut, priorité, fichier_path (document
  principal numérisé — voir Module 2)
- `pieces_jointes` : courrier_id (FK), fichier_path, nom_original, type_mime, taille
  — relation un-à-plusieurs avec `courriers` (un courrier peut avoir 0 ou plusieurs
  pièces jointes : constat amiable, photos, factures, etc. — distinct du document
  principal). Même règle de stockage que le document principal (S3-compatible,
  jamais modifiable après validation).
- `courrier_historiques` : courrier_id, action, auteur_id, horodatage, commentaire
  (table append-only, jamais de update/delete)
- `services` : nom, code (court, unique — segment du numéro de référence), responsable_id
- `numero_sequences` : annee, service_id, dernier_numero — compteur consommé par
  `NumeroReferenceGenerator` pour produire `GEC-{annee}-{code}-{sequence}`
- `profils` : nom (Agent, Collaborateur, Responsable de service, Administrateur) —
  jamais nommé `roles` (voir DECISIONS.md)
- `users` : ..., profil_id (FK vers `profils`)
- `affectations` : courrier_id, user_id, date_affectation, motif_reaffectation (nullable)
- `sla_regles` : type_courrier, delai_max_heures
- `regles_classement` (Module 3) : nom, mots_cles (JSON), champs surveillés (JSON,
  null = tous), type_document_propose, service_propose_id, tags (JSON), priorite,
  actif — éditées par un Administrateur (`RegleClassementPolicy`)
- `mots_cles` + pivot `courrier_mot_cle` (source : regle | ocr | manuel) —
  tags secondaires, un courrier en a 0..N
- `courriers` reçoit aussi la proposition du système, à part des valeurs
  saisies : classement_statut (non_classe | propose | valide | ignore),
  type_document_propose, service_propose_id, classement_regle_id,
  classement_propose_le — "l'agent valide ou corrige" ; classement_analyse_le
  (posé à chaque passage du job, même sans correspondance : distingue « en
  attente » de « aucune règle »)
- `courriers.sous_type_sinistre` (Module 3, nullable) : materiel | corporel —
  n'a de sens que si `type_document` désigne un sinistre (comparaison
  normalisée, pas un enum DB — `type_document` reste du texte libre)
- `courriers.numero_tampon_detecte` (Module 1/2, nullable) : numéro lu par OCR
  sur le tampon d'entrée existant, **indicatif uniquement** — n'alimente
  jamais `numero_reference` (voir DECISIONS.md)
- `courrier_brouillons` (Module 1/2, flux scan-first) : document scanné avant
  qu'aucun Courrier n'existe — table isolée, aucune FK vers `courriers` ni les
  tables des Modules 3/4/8/9/10 (voir DECISIONS.md "Flux scan-first"). Consommée
  (fichier déplacé, champs OCR copiés, ligne supprimée) à la confirmation dans
  `RegistrationForm::enregistrer()`.
- `courriers.statut` (Module 4) pilote le circuit générique unique : voir
  `WorkflowService::TRANSITIONS` pour la machine à états complète.
  `affectations` reste append-only ; `Courrier::affectationCourante()` (relation
  `hasOne`/`latestOfMany()`) donne l'affectation en cours sans charger tout
  l'historique — utilisable dans `whereHas()`/`with()`.
- `users.service_id` (Module 4/6, nullable) : appartenance d'un utilisateur à
  un service (Collaborateur, en pratique aussi Responsable) — **distincte**
  de `services.responsable_id` (qui identifie le responsable d'un service) ;
  ne jamais confondre les deux (bug réel rencontré, voir DECISIONS.md).

## Stratégie de queue

| Job | Queue | Priorité |
|---|---|---|
| ProcessDocumentOcr | `ocr` | normale |
| ProcessBrouillonOcr | `ocr` | normale |
| IndexCourrierJob | `indexation` | normale |
| ReanalyserCourriersJob | `indexation` | normale |
| ReplicateFichierJob | `replication` | basse |
| SendMailAlertJob | `alertes` | haute |
| RefreshDashboardStatsJob | `stats` | basse |

Tous les jobs : `ShouldQueue`, `$tries = 3`, `backoff()` exponentiel.

## MinIO local (dev)

Binaire autonome (pas de Docker sur cette machine), installé dans
`C:\Users\NSIANV-EDS-ANK\minio\` (`minio.exe` + `mc.exe`, hors du repo).
Identifiants dans `.env` (`AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY`).

Démarrage (si le processus ne tourne plus) :
```
$env:MINIO_ROOT_USER = "<voir .env AWS_ACCESS_KEY_ID>"
$env:MINIO_ROOT_PASSWORD = "<voir .env AWS_SECRET_ACCESS_KEY>"
C:\Users\NSIANV-EDS-ANK\minio\minio.exe server C:\Users\NSIANV-EDS-ANK\minio\data --address :9000 --console-address :9101
```
API S3 sur `http://127.0.0.1:9000` (déjà dans `AWS_ENDPOINT`), console web sur
`:9101` (le port `9001` par défaut est déjà pris par Herd sur cette machine).
Bucket `gec` déjà créé (disque primaire `s3`) ainsi que `gec-backup` (disque
`s3_backup`, secours — voir DECISIONS.md "Stockage hybride" ; en dev, les deux
buckets vivent sur le même MinIO local faute d'un vrai compte cloud).

## Tesseract OCR local (dev) — Module 2

Installé via `winget install UB-Mannheim.TesseractOCR` (v5.4) dans
`C:\Program Files\Tesseract-OCR\`. Les packs de langue ne pouvant pas y être
ajoutés sans droits admin, ils sont dans `C:\Users\NSIANV-EDS-ANK\tessdata\`
(`eng`, `fra`, `osd`) **ainsi que les sous-dossiers `configs/` et
`tessconfigs/`** copiés depuis l'installation (le job utilise le fichier de
configuration `tsv` pour obtenir texte + confiance ; sans `configs/tsv`,
Tesseract retombe silencieusement en texte brut et le job échoue), le tout
pointé par `TESSERACT_TESSDATA_PATH` dans `.env` (`TESSERACT_PATH` pour le
binaire). Sur Linux : `apt install tesseract-ocr tesseract-ocr-fra` et laisser
les défauts (les configs sont dans le tessdata système).

Les PDF sont convertis en image (une par page, Ghostscript, 300 dpi) avant
l'OCR — Tesseract ne lit pas un PDF lui-même sur ce build, même Ghostscript
disponible (voir DECISIONS.md "OCR des PDF via Ghostscript"). Installé sous
`C:\Program Files\gs\gs10.07.1\`, chemin complet dans `.env` (`GHOSTSCRIPT_PATH`,
pas seulement le PATH système — le worker de queue ne verrait sinon un
changement de PATH qu'après redémarrage). Sur Linux : `apt install ghostscript`
(le binaire `gs` par défaut suffit, `GHOSTSCRIPT_PATH` peut rester vide).
Sans `GHOSTSCRIPT_PATH` configuré, seules les images sont OCRisées (repli
historique, échec propre pour un PDF).

Traitement des jobs en local : `php artisan queue:work --queue=ocr,indexation,default`
(QUEUE_CONNECTION=database en dev ; Redis + Horizon en production, voir Stack).
Le worker charge le code au démarrage : le relancer après toute modification
d'un Job.

## Temps réel — Reverb (dev)

Serveur WebSocket auto-hébergé (voir DECISIONS.md). En local, deux processus
à laisser tourner en plus de Herd : le worker de queue ci-dessus et
`php artisan reverb:start` (port 8080, clés dans `.env` `REVERB_*`). Après
toute modification de `resources/js/*` ou des variables `VITE_*` :
`npm run build` (ou `npm run dev` pendant le développement).
Les composants Livewire s'abonnent avec `#[On('echo-private:courrier.{id},.ocr.termine')]` ;
l'accès aux canaux privés est contrôlé dans `routes/channels.php` par les
Policies — jamais un simple contrôle côté client (Règle n°6).

## Déploiement

Progressif — voir `plan-projet-gec-scalabilite.md` section 4 : pilote sur un service,
test de charge à chaque palier, élargissement service par service.

## Ce document évolue

Toute décision technique significative doit être ajoutée à `DECISIONS.md` avec la date
et la justification — ne pas modifier silencieusement l'architecture définie ici sans
tracer pourquoi.
