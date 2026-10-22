<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('id')
                ->constrained('services')->nullOnDelete();
            // 'direction' | 'service' | 'sous_service' — défaut 'service' :
            // les 14 services réels existants deviennent des nœuds racine de
            // type "service", strictement inchangés (aucune direction
            // fabriquée au-dessus).
            $table->string('type')->default('service')->after('parent_id');
            $table->softDeletes();
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn('type');
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
