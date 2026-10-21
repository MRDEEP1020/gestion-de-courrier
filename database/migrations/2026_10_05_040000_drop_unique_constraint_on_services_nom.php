<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module "Organisation" — multi-site (2026-10-05, demande explicite de
// l'utilisateur : "EACH CITY HAS HIS OWN DEPARTMENTS... USERS ON IT SHOULD
// SEE THE DATAS OF THAT SITE NOT THE OTHERS"). `services.nom` était unique
// GLOBALEMENT depuis la toute première migration (2026_09_03_100002) — un
// héritage de l'époque où un seul organigramme existait. Avec plusieurs
// Sites/Agences, deux agences ont légitimement chacune leur propre "DSIN",
// "DAF", etc. — deux lignes RÉELLEMENT distinctes dans `services`, pas une
// seule partagée. Voir App\Services\ServiceReelSynchroniseur, modifié le
// même jour pour ne plus réutiliser un service existant que s'il appartient
// au MÊME Site (jamais plus par simple égalité de nom à travers toute
// l'entreprise). `services.code` reste unique (ServiceReelSynchroniseur::
// codeUnique() le garantit déjà), donc chaque service reste identifiable
// sans ambiguïté même si plusieurs partagent le même nom affiché.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropUnique(['nom']);
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->unique('nom');
        });
    }
};
