<?php

namespace Database\Seeders;

use App\Models\Service;
use Illuminate\Database\Seeder;

class ServiceSeeder extends Seeder
{
    // Liste réelle des services confirmée par le client (sigles lus sur le
    // tampon papier existant) — voir specifications-modules-GEC.md, annexe,
    // et DECISIONS.md. Intitulés complets à confirmer pour SANTE/RH/RAG (RAG
    // en particulier : sigle non expliqué par le client, laissé tel quel
    // plutôt que d'inventer une signification). `responsable_id` reste vide
    // ici : à assigner une fois le service pilote choisi (voir DECISIONS.md
    // "Déploiement — pilote sur un service").
    private const SERVICES = [
        'SDG' => 'Secrétariat du Directeur Général',
        'DAF' => 'Direction des Affaires Financières et de la Comptabilité',
        'DT' => 'Direction Technique',
        'DSIN' => 'Direction des Sinistres',
        'ACG' => 'Audit, Contrôle et Gestion',
        'DI' => 'Direction Informatique',
        'DC' => 'Direction Commerciale',
        'SANTE' => 'Santé',
        'SJ' => 'Service Juridique',
        'DCOM' => 'Direction de la Communication',
        'RH' => 'Ressources Humaines',
        'RAG' => 'RAG (intitulé à confirmer avec le client)',
        'TRANS' => 'Transport',
        'SG' => 'Secrétariat Général',
    ];

    public function run(): void
    {
        foreach (self::SERVICES as $code => $nom) {
            // firstOrCreate sur le code : idempotent, ne duplique jamais un
            // service déjà présent (ex. un service de pilote déjà créé/renommé
            // à la main), cohérent avec ProfilSeeder.
            Service::firstOrCreate(['code' => $code], ['nom' => $nom]);
        }
    }
}
