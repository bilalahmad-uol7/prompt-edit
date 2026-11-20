<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_root_url_sends_guests_to_the_login_page()
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertOk();
    }

    public function test_the_root_url_sends_signed_in_users_on_to_the_generate_page()
    {
        Http::preventStrayRequests();
        Http::fake(['*/pricing' => Http::response(pixelforgeFixture('pricing'))]);

        $this->actingAs(User::factory()->create())
            ->followingRedirects()
            ->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('generations/Create'));
    }
}
