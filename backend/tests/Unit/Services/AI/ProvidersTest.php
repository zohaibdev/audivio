<?php

namespace Tests\Unit\Services\AI;

use App\Services\AI\AiProviderException;
use App\Services\AI\ClaudeProvider;
use App\Services\AI\GeminiProvider;
use App\Services\AI\OpenAiProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProvidersTest extends TestCase
{
    public function test_claude_provider_parses_response_and_usage(): void
    {
        config(['ai.providers.claude.api_key' => 'test-key']);

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

        Http::assertSent(function ($request) {
            return $request->hasHeader('x-api-key', 'test-key')
                && $request['messages'][0]['content'] === 'Say hello';
        });
    }

    public function test_claude_provider_appends_context_as_json(): void
    {
        config(['ai.providers.claude.api_key' => 'test-key']);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [], 'usage' => []])]);

        (new ClaudeProvider)->analyze('Prompt', ['pages' => ['a', 'b']]);

        Http::assertSent(fn ($request) => str_contains($request['messages'][0]['content'], '"pages"'));
    }

    public function test_claude_provider_throws_without_api_key(): void
    {
        config(['ai.providers.claude.api_key' => null]);

        $this->expectException(AiProviderException::class);

        (new ClaudeProvider)->analyze('Prompt');
    }

    public function test_claude_provider_throws_on_error_status(): void
    {
        config(['ai.providers.claude.api_key' => 'test-key']);
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'bad request'], 400)]);

        $this->expectException(AiProviderException::class);

        (new ClaudeProvider)->analyze('Prompt');
    }

    public function test_openai_provider_parses_response_and_usage(): void
    {
        config(['ai.providers.openai.api_key' => 'test-key']);

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

    public function test_gemini_provider_parses_response_and_usage(): void
    {
        config(['ai.providers.gemini.api_key' => 'test-key']);

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
}
