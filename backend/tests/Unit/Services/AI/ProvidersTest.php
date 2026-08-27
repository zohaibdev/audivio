<?php

namespace Tests\Unit\Services\AI;

use App\Models\ApiProvider;
use App\Services\AI\AiProviderException;
use App\Services\AI\ClaudeProvider;
use App\Services\AI\GeminiProvider;
use App\Services\AI\OpenAiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProvidersTest extends TestCase
{
    use RefreshDatabase;

    private function seedProvider(string $slug, string $model, ?string $apiKey = 'test-key', bool $enabled = true): ApiProvider
    {
        return ApiProvider::create([
            'slug' => $slug,
            'name' => $slug,
            'provider_type' => 'ai',
            'is_enabled' => $enabled,
            'api_key' => $apiKey,
            'model' => $model,
        ]);
    }

    public function test_claude_provider_parses_response_and_usage(): void
    {
        $this->seedProvider('claude', 'claude-sonnet-5');

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Hello from Claude']],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 20],
            ]),
        ]);

        $response = (new ClaudeProvider)->analyze('Say hello');

        $this->assertSame('Hello from Claude', $response->content);
        $this->assertSame(10, $response->promptTokens);
        $this->assertSame(20, $response->completionTokens);
        $this->assertSame('claude', $response->provider);
        $this->assertNotNull($response->responseTimeMs);

        Http::assertSent(function ($request) {
            return $request->hasHeader('x-api-key', 'test-key')
                && $request['messages'][0]['content'] === 'Say hello';
        });
    }

    public function test_claude_provider_appends_context_as_json(): void
    {
        $this->seedProvider('claude', 'claude-sonnet-5');
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [], 'usage' => []])]);

        (new ClaudeProvider)->analyze('Prompt', ['pages' => ['a', 'b']]);

        Http::assertSent(fn ($request) => str_contains($request['messages'][0]['content'], '"pages"'));
    }

    public function test_claude_provider_throws_without_api_key(): void
    {
        // No ApiProvider row seeded at all — the natural "not configured" state.
        $this->expectException(AiProviderException::class);

        (new ClaudeProvider)->analyze('Prompt');
    }

    public function test_claude_provider_throws_when_disabled(): void
    {
        $this->seedProvider('claude', 'claude-sonnet-5', enabled: false);

        $this->expectException(AiProviderException::class);

        (new ClaudeProvider)->analyze('Prompt');
    }

    public function test_claude_provider_throws_on_error_status(): void
    {
        $this->seedProvider('claude', 'claude-sonnet-5');
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'bad request'], 400)]);

        $this->expectException(AiProviderException::class);

        (new ClaudeProvider)->analyze('Prompt');
    }

    public function test_claude_provider_records_failure_on_the_provider_row(): void
    {
        $provider = $this->seedProvider('claude', 'claude-sonnet-5');
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'bad request'], 400)]);

        try {
            (new ClaudeProvider)->analyze('Prompt');
        } catch (AiProviderException) {
            // expected
        }

        $provider->refresh();
        $this->assertNotNull($provider->last_failure_at);
        $this->assertNotNull($provider->last_error);
    }

    public function test_openai_provider_parses_response_and_usage(): void
    {
        $this->seedProvider('openai', 'gpt-5');

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'Hello from GPT']]],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 15],
            ]),
        ]);

        $response = (new OpenAiProvider)->analyze('Say hello');

        $this->assertSame('Hello from GPT', $response->content);
        $this->assertSame(5, $response->promptTokens);
        $this->assertSame(15, $response->completionTokens);

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-key'));
    }

    public function test_openai_provider_throws_without_api_key(): void
    {
        $this->expectException(AiProviderException::class);

        (new OpenAiProvider)->analyze('Prompt');
    }

    public function test_openai_provider_throws_on_error_status(): void
    {
        $this->seedProvider('openai', 'gpt-5');
        Http::fake(['api.openai.com/*' => Http::response(['error' => 'bad request'], 400)]);

        $this->expectException(AiProviderException::class);

        (new OpenAiProvider)->analyze('Prompt');
    }

    public function test_gemini_provider_parses_response_and_usage(): void
    {
        $this->seedProvider('gemini', 'gemini-3-pro');

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'Hello from Gemini']]]]],
                'usageMetadata' => ['promptTokenCount' => 7, 'candidatesTokenCount' => 12],
            ]),
        ]);

        $response = (new GeminiProvider)->analyze('Say hello');

        $this->assertSame('Hello from Gemini', $response->content);
        $this->assertSame(7, $response->promptTokens);
        $this->assertSame(12, $response->completionTokens);

        Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'key=test-key'));
    }

    public function test_gemini_provider_throws_without_api_key(): void
    {
        $this->expectException(AiProviderException::class);

        (new GeminiProvider)->analyze('Prompt');
    }

    public function test_gemini_provider_throws_on_error_status(): void
    {
        $this->seedProvider('gemini', 'gemini-3-pro');
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => 'bad request'], 400)]);

        $this->expectException(AiProviderException::class);

        (new GeminiProvider)->analyze('Prompt');
    }

    public function test_claude_test_connection_succeeds_even_when_disabled(): void
    {
        $this->seedProvider('claude', 'claude-sonnet-5', enabled: false);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'OK']],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])]);

        $result = (new ClaudeProvider)->testConnection();

        $this->assertTrue($result['success']);
        $this->assertNotNull($result['response_time_ms']);
    }

    public function test_claude_test_connection_fails_safely_without_a_key(): void
    {
        $result = (new ClaudeProvider)->testConnection();

        $this->assertFalse($result['success']);
        $this->assertIsString($result['message']);
    }
}
