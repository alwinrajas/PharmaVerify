<?php

namespace Tests\Feature;

use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What an API route says to a caller that is not signed in.
 *
 * The SPA and the handheld terminals always ask for JSON, so this was only
 * reachable by something that did not — a browser opening the URL directly, a
 * health check, a proxy probe, curl. Laravel answers an unauthenticated request
 * by redirecting a guest to a sign-in page, and this application has none: the
 * SPA owns signing in. Resolving that non-existent route raised a routing error
 * inside the auth middleware, before the exception renderer could see it, and
 * the caller received 500 with an internal exception name instead of 401.
 *
 * These tests pin the answer to a refusal, in the same envelope as every other
 * failure, whatever the caller asked for.
 */
class UnauthenticatedApiResponseTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_request_that_did_not_ask_for_json_is_refused_rather_than_broken(): void
    {
        // No Accept header at all.
        $response = $this->call('GET', '/api/auth/me');

        $response->assertStatus(401);
    }

    public function test_a_browser_style_request_is_refused_the_same_way(): void
    {
        $response = $this->withHeaders(['Accept' => 'text/html,application/xhtml+xml'])
            ->get('/api/auth/me');

        $response->assertStatus(401);
    }

    public function test_the_refusal_uses_the_standard_envelope(): void
    {
        $response = $this->call('GET', '/api/auth/me');

        $response->assertStatus(401);
        $response->assertJson([
            'success' => false,
            'message' => 'Your session has expired. Please sign in again.',
        ]);
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
    }

    public function test_it_exposes_no_exception_trace_or_internal_route_detail(): void
    {
        // Debug is deliberately on: this path must not leak even when the
        // environment is at its most talkative, because the value of the fix is
        // that the failure never becomes an unhandled exception in the first
        // place.
        config(['app.debug' => true]);

        $body = $this->call('GET', '/api/auth/me')->getContent();

        foreach (['Route [', 'login', 'UrlGenerator', 'RouteNotFoundException', 'trace', 'exception', 'vendor', '.php'] as $leak) {
            $this->assertStringNotContainsString(
                $leak,
                $body,
                "The unauthenticated response exposed '{$leak}'."
            );
        }
    }

    public function test_other_guarded_endpoints_answer_the_same_way(): void
    {
        // The defect was in the shared auth middleware, so it was never
        // specific to one route.
        foreach (['/api/shops', '/api/audits', '/api/final-outputs'] as $path) {
            $this->call('GET', $path)->assertStatus(401);
        }
    }

    // --------------------------------------------------- nothing else moved

    public function test_a_json_request_is_unchanged(): void
    {
        $response = $this->getJson('/api/auth/me');

        $response->assertStatus(401);
        $response->assertJson([
            'success' => false,
            'message' => 'Your session has expired. Please sign in again.',
        ]);
    }

    public function test_a_signed_in_caller_still_reaches_the_endpoint(): void
    {
        $user = $this->userWithRole(Roles::ADMINISTRATOR, ['email' => 'admin@pharmaverify.test']);

        $token = $this->postJson('/api/auth/login', [
            'email' => 'admin@pharmaverify.test',
            'password' => 'Pharma@2026',
        ])->json('data.token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_a_valid_token_works_even_without_a_json_accept_header(): void
    {
        $this->userWithRole(Roles::ADMINISTRATOR, ['email' => 'admin@pharmaverify.test']);

        $token = $this->postJson('/api/auth/login', [
            'email' => 'admin@pharmaverify.test',
            'password' => 'Pharma@2026',
        ])->json('data.token');

        $this->call('GET', '/api/auth/me', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ])->assertOk();
    }

    public function test_an_invalid_token_is_refused_in_the_same_envelope(): void
    {
        $response = $this->call('GET', '/api/auth/me', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer not-a-real-token',
        ]);

        $response->assertStatus(401);
        $response->assertJson(['success' => false]);
        $this->assertStringNotContainsString('not-a-real-token', $response->getContent());
    }
}
