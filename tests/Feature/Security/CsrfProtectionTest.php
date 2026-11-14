<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CsrfProtectionTest extends TestCase
{
    public function test_csrf_cookie_endpoint_issues_session(): void
    {
        $this->getJson('/api/v1/auth/csrf-cookie')
            ->assertOk()
            ->assertJsonPath('data.csrf', true);
    }

    public function test_mutating_request_without_csrf_token_is_rejected(): void
    {
        $user = User::factory()->platformAdmin()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/tenants', [
                'name' => 'No CSRF',
                'slug' => 'no-csrf',
            ])
            ->assertStatus(419)
            ->assertJsonPath('error.code', 'csrf_token_mismatch');
    }

    public function test_mutating_request_with_invalid_csrf_token_is_rejected(): void
    {
        $user = User::factory()->platformAdmin()->create();

        $this->actingAs($user)
            ->withSession(['_token' => 'expected-token'])
            ->withHeader('X-CSRF-TOKEN', 'wrong-token')
            ->postJson('/api/v1/tenants', [
                'name' => 'Bad CSRF',
                'slug' => 'bad-csrf',
            ])
            ->assertStatus(419)
            ->assertJsonPath('error.code', 'csrf_token_mismatch');
    }

    public function test_mutating_request_with_valid_csrf_token_succeeds(): void
    {
        $user = User::factory()->platformAdmin()->create();

        $this->actingAs($user)
            ->withCsrf('valid-token')
            ->postJson('/api/v1/tenants', [
                'name' => 'Good CSRF',
                'slug' => 'good-csrf',
            ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'good-csrf');
    }

    public function test_login_requires_csrf_token(): void
    {
        User::factory()->create([
            'email' => 'csrf-login@codina.local',
            'password' => Hash::make('password'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'csrf-login@codina.local',
            'password' => 'password',
        ])->assertStatus(419);

        $this->postJsonWithCsrf('/api/v1/auth/login', [
            'email' => 'csrf-login@codina.local',
            'password' => 'password',
        ])->assertOk();
    }

    public function test_safe_methods_do_not_require_csrf(): void
    {
        $this->getJson('/api/v1/health')->assertOk();
    }
}
