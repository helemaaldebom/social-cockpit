<?php

namespace Tests\Feature;

use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    private function makeSignature(string $payload, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $payload, $secret);
    }

    public function test_valid_webhook_creates_content_item(): void
    {
        Queue::fake();

        $secret = 'test-secret';
        config(['services.webhook.secret' => $secret]);

        Client::factory()->create(['slug' => 'test-client', 'active' => true]);

        $payload = json_encode([
            'client' => 'test-client',
            'brief' => 'Test brief content',
            'title' => 'Test title',
        ]);

        $response = $this->call(
            'POST',
            '/api/webhook/content',
            [],
            [],
            [],
            ['HTTP_X_WEBHOOK_SIGNATURE' => $this->makeSignature($payload, $secret), 'CONTENT_TYPE' => 'application/json'],
            $payload
        );

        $response->assertStatus(201);
        $this->assertDatabaseHas('content_items', ['title' => 'Test title']);
    }

    public function test_invalid_signature_returns_403(): void
    {
        $payload = json_encode(['client' => 'test', 'brief' => 'test']);

        $response = $this->call(
            'POST',
            '/api/webhook/content',
            [],
            [],
            [],
            ['HTTP_X_WEBHOOK_SIGNATURE' => 'sha256=invalid', 'CONTENT_TYPE' => 'application/json'],
            $payload
        );

        $response->assertStatus(403);
    }

    public function test_missing_signature_returns_401(): void
    {
        $payload = json_encode(['client' => 'test', 'brief' => 'test']);

        $response = $this->call(
            'POST',
            '/api/webhook/content',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $payload
        );

        $response->assertStatus(401);
    }

    public function test_webhook_stores_original_text(): void
    {
        Queue::fake();
        $secret = 'test-secret';
        config(['services.webhook.secret' => $secret]);
        Client::factory()->create(['slug' => 'test-client', 'active' => true]);

        $payload = json_encode([
            'client'        => 'test-client',
            'brief'         => 'Brief',
            'title'         => 'Met originele tekst',
            'original_text' => 'Dit is de letterlijke tekst van de klant.',
        ]);

        $this->call('POST', '/api/webhook/content', [], [], [], [
            'HTTP_X_WEBHOOK_SIGNATURE' => $this->makeSignature($payload, $secret),
            'CONTENT_TYPE'             => 'application/json',
        ], $payload)->assertStatus(201);

        $this->assertDatabaseHas('content_items', [
            'title'         => 'Met originele tekst',
            'original_text' => 'Dit is de letterlijke tekst van de klant.',
        ]);
    }

    public function test_webhook_is_idempotent_per_external_id(): void
    {
        Queue::fake();
        $secret = 'test-secret';
        config(['services.webhook.secret' => $secret]);
        Client::factory()->create(['slug' => 'test-client', 'active' => true]);

        $payload = json_encode([
            'client'      => 'test-client',
            'brief'       => 'Brief',
            'title'       => 'Inzending',
            'external_id' => 'zts-42',
        ]);

        $headers = [
            'HTTP_X_WEBHOOK_SIGNATURE' => $this->makeSignature($payload, $secret),
            'CONTENT_TYPE'             => 'application/json',
        ];

        $first  = $this->call('POST', '/api/webhook/content', [], [], [], $headers, $payload);
        $second = $this->call('POST', '/api/webhook/content', [], [], [], $headers, $payload);

        $first->assertStatus(201);
        $second->assertStatus(200);
        $this->assertSame(1, \App\Models\ContentItem::count());
    }
}
