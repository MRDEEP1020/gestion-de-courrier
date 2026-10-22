<?php

use App\Services\SlaCalculatorService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            // Module 5/7 (2026-09-23, voir DECISIONS.md "SLA et alertes") —
            // date limite STOCKÉE (et indexée, Règle n°3) plutôt que
            // recalculée en SQL à chaque requête : "en retard" devient un
            // simple `date_limite < aujourd'hui` sur les listes, le tableau
            // de bord et le job d'alertes. Recalculée par Courrier::booted()
            // quand echeance/sla_jours/type_document/date_mouvement changent.
            $table->date('date_limite')->nullable()->after('echeance')->index();
            // Anti-doublon des alertes (Module 7) : une alerte "bientôt en
            // retard" par date limite, puis une relance au plus tous les
            // config('gec.sla.relance_jours') tant que le retard dure.
            $table->timestamp('alerte_risque_le')->nullable()->after('date_limite');
            $table->timestamp('alerte_retard_le')->nullable()->after('alerte_risque_le');
        });

        // Rattrapage des courriers existants — mêmes règles que Courrier::booted().
        DB::table('courriers')->orderBy('id')->chunkById(500, function ($courriers) {
            foreach ($courriers as $courrier) {
                $dateLimite = SlaCalculatorService::calculerDateLimite(
                    $courrier->echeance,
                    $courrier->sla_jours,
                    $courrier->type_document,
                    $courrier->date_mouvement,
                );

                DB::table('courriers')->where('id', $courrier->id)->update(['date_limite' => $dateLimite?->toDateString()]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            // SQLite : l'index doit disparaître avant la colonne indexée.
            $table->dropIndex(['date_limite']);
            $table->dropColumn(['date_limite', 'alerte_risque_le', 'alerte_retard_le']);
        });
    }
};
