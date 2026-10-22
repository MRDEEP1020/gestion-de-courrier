<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// "Group B" (2026-09-24, voir DECISIONS.md "Paramètres système
// configurables — Groupe A/B") : listes de référence gérables depuis
// l'UI (Module 1 : type de document, mode de réception, priorité).
// `valeur` reste la chaîne exacte utilisée par le code métier (Rule::in,
// match() de couleur, OCR, WorkflowService::estUnSinistre()) — jamais
// traduite/reformatée ailleurs. `protege = true` empêche seulement le
// RENOMMAGE d'une valeur dont une logique métier dépend de la chaîne
// exacte (ex. "Sinistre", "email", "fax") ; ça ne bloque jamais sa
// désactivation (qui ne fait que la retirer des nouveaux formulaires,
// sans toucher aux courriers déjà enregistrés avec cette valeur).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listes_reference', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // type_document | mode_reception | priorite
            $table->string('valeur');
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->boolean('actif')->default(true);
            $table->boolean('protege')->default(false);
            $table->timestamps();

            $table->unique(['type', 'valeur']);
            $table->index(['type', 'actif', 'ordre']);
        });

        $maintenant = now();

        $typesDocument = [
            'Lettre', 'Sinistre', 'Réclamation', 'Facture', 'Demande',
            'Proposition commerciale', 'Convocation', 'Relevé ou bordereau',
            'Contrat ou avenant',
        ];
        $lignes = [];
        foreach ($typesDocument as $i => $valeur) {
            $lignes[] = [
                'type' => 'type_document',
                'valeur' => $valeur,
                'ordre' => $i,
                'actif' => true,
                // "Sinistre" : CourrierForm::estUnSinistre() dépend de
                // str_contains(..., 'sinistre') pour le circuit DGA.
                'protege' => $valeur === 'Sinistre',
                'created_at' => $maintenant,
                'updated_at' => $maintenant,
            ];
        }

        $modesReception = ['depot_physique', 'email', 'poste', 'fax'];
        foreach ($modesReception as $i => $valeur) {
            $lignes[] = [
                'type' => 'mode_reception',
                'valeur' => $valeur,
                'ordre' => $i,
                'actif' => true,
                // 'email'/'fax' : détectés et écrits tels quels par l'OCR
                // (ProcessDocumentOcr) ; 'depot_physique' : valeur par
                // défaut du flux courrier confidentiel. 'poste' n'est lié
                // à aucune logique par chaîne exacte, reste renommable.
                'protege' => in_array($valeur, ['depot_physique', 'email', 'fax'], true),
                'created_at' => $maintenant,
                'updated_at' => $maintenant,
            ];
        }

        $priorites = ['basse', 'normale', 'haute', 'urgente'];
        foreach ($priorites as $i => $valeur) {
            $lignes[] = [
                'type' => 'priorite',
                'valeur' => $valeur,
                'ordre' => $i,
                'actif' => true,
                // Les 4 valeurs pilotent des match() de couleur en dur
                // dans plusieurs vues Blade (avec un default => de repli,
                // mais on protège quand même les 4 pour rester cohérent).
                'protege' => true,
                'created_at' => $maintenant,
                'updated_at' => $maintenant,
            ];
        }

        DB::table('listes_reference')->insert($lignes);
    }

    public function down(): void
    {
        Schema::dropIfExists('listes_reference');
    }
};
