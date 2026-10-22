<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    // Bascule FR/EN demandée par l'utilisateur (2026-09-07) — voir
    // App\Http\Middleware\SetLocale et DECISIONS.md.

    public function test_le_francais_est_la_langue_par_defaut_sans_choix_en_session(): void
    {
        $this->get(route('home'));

        $this->assertSame('fr', app()->getLocale());
    }

    public function test_changer_de_langue_persiste_le_choix_en_session(): void
    {
        $this->get(route('langue.changer', 'en'))->assertRedirect();

        $this->assertSame('en', session('locale'));

        $this->get(route('home'));

        $this->assertSame('en', app()->getLocale());
    }

    public function test_une_langue_non_reconnue_est_ignoree_sans_erreur(): void
    {
        $this->get(route('langue.changer', 'de'))->assertRedirect();

        $this->assertNull(session('locale'));

        $this->get(route('home'));

        $this->assertSame('fr', app()->getLocale());
    }

    public function test_les_traductions_anglaises_sont_appliquees_apres_le_changement(): void
    {
        $this->get(route('langue.changer', 'en'));

        $this->get(route('home'));

        $this->assertSame('Subject', __('Objet'));
        $this->assertSame('Register the document', __('Enregistrer le courrier'));
    }
}
