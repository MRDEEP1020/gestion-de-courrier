<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Inscription publique désactivée (2026-09-23, voir DECISIONS.md
// "Inscription publique désactivée") : les comptes sont créés par un
// administrateur ("Utilisateurs & Accès"). Remplace les tests du starter
// kit qui vérifiaient l'inscription libre.
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_page_dinscription_nexiste_plus(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_on_ne_peut_pas_creer_de_compte_soi_meme(): void
    {
        $this->post('/register', [
            'name' => 'John Doe',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
    }

    public function test_la_page_de_connexion_saffiche_sans_lien_dinscription(): void
    {
        $this->get(route('login'))->assertOk()->assertDontSee('/register');
    }
}
