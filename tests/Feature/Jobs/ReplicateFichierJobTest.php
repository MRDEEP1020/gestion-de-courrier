<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ReplicateFichierJob;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReplicateFichierJobTest extends TestCase
{
    // Règle n°4 (complétée) — "stockage local en priorité + réplication
    // automatique vers un stockage cloud de secours" (voir DECISIONS.md).
    // AWS_BACKUP_BUCKET est vide par défaut en test (phpunit.xml) : chaque
    // test qui a besoin d'un disque de secours actif le configure lui-même,
    // pour ne jamais dépendre d'un vrai MinIO/S3 en tâche de fond.

    public function test_le_fichier_est_copie_du_disque_primaire_vers_le_disque_de_secours(): void
    {
        config(['filesystems.disks.s3_backup.bucket' => 'test-backup']);
        Storage::fake('s3');
        Storage::fake('s3_backup');

        Storage::disk('s3')->put('courriers/2026/DIR/doc.pdf', 'contenu du document');

        app()->call([new ReplicateFichierJob('courriers/2026/DIR/doc.pdf'), 'handle']);

        Storage::disk('s3_backup')->assertExists('courriers/2026/DIR/doc.pdf');
        $this->assertSame('contenu du document', Storage::disk('s3_backup')->get('courriers/2026/DIR/doc.pdf'));
    }

    public function test_sans_disque_de_secours_configure_la_replication_est_ignoree(): void
    {
        // AWS_BACKUP_BUCKET vide (défaut de test) : le job ne doit toucher à
        // aucun disque et ne jamais échouer (Règle n°1 — jamais bloquant).
        $this->assertSame('', config('filesystems.disks.s3_backup.bucket'));

        app()->call([new ReplicateFichierJob('courriers/2026/DIR/doc.pdf'), 'handle']);

        $this->addToAssertionCount(1); // aucune exception = comportement attendu
    }

    public function test_un_fichier_primaire_disparu_nest_pas_une_panne(): void
    {
        config(['filesystems.disks.s3_backup.bucket' => 'test-backup']);
        Storage::fake('s3');
        Storage::fake('s3_backup');

        app()->call([new ReplicateFichierJob('courriers/2026/DIR/absent.pdf'), 'handle']);

        Storage::disk('s3_backup')->assertMissing('courriers/2026/DIR/absent.pdf');
    }
}
