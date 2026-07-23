<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientExample;
use App\Models\ContentItem;
use App\Services\OpenAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\TestCase;

class OpenAiTokenUsageTest extends TestCase
{
    use RefreshDatabase;

    private function makeItemWithExamples(int $exampleCount): ContentItem
    {
        $client = Client::factory()->create(['tone_of_voice' => 'Testprompt.']);

        for ($i = 0; $i < $exampleCount; $i++) {
            ClientExample::create([
                'client_id' => $client->id,
                'network'   => 'linkedin',
                'label'     => "voorbeeld {$i}",
                'content'   => "Voorbeeldpost {$i}",
                'sort_order' => $i,
            ]);
        }

        return ContentItem::create([
            'client_id'      => $client->id,
            'title'          => 'Test',
            'brief'          => 'Schrijf iets.',
            'generated_text' => 'Bestaande tekst.',
        ]);
    }

    public function test_generate_limits_examples_to_config_max(): void
    {
        config(['openai.max_examples' => 4]);
        OpenAI::fake([CreateResponse::fake()]);

        $item = $this->makeItemWithExamples(15);
        app(OpenAiService::class)->generateText($item);

        OpenAI::assertSent(\OpenAI\Resources\Chat::class, function (string $method, array $parameters) {
            // 1 system prompt + 1 voorbeeld-instructie + 4 × (user+assistant) + 1 brief = 11
            return count($parameters['messages']) === 11
                && $parameters['max_tokens'] === 600;
        });
    }

    public function test_refine_sends_no_examples(): void
    {
        OpenAI::fake([CreateResponse::fake()]);

        $item = $this->makeItemWithExamples(15);
        app(OpenAiService::class)->refineText($item, 'Maak korter.');

        OpenAI::assertSent(\OpenAI\Resources\Chat::class, function (string $method, array $parameters) {
            // Alleen system prompt + de bewerkingsopdracht = 2 berichten
            return count($parameters['messages']) === 2;
        });
    }

    public function test_asterisks_are_stripped_from_output(): void
    {
        OpenAI::fake([CreateResponse::fake([
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => "Titel met **Paraboolvering** en *cursief* woord.\n* opsomming blijft staan"],
                'finish_reason' => 'stop',
            ]],
        ])]);

        $item = $this->makeItemWithExamples(0);
        $result = app(OpenAiService::class)->generateText($item);

        $this->assertStringNotContainsString('**', $result);
        $this->assertStringContainsString('Paraboolvering', $result);
        $this->assertStringContainsString('cursief', $result);
        $this->assertStringNotContainsString('*cursief*', $result);
        $this->assertStringContainsString("\n* opsomming blijft staan", $result);
    }

    public function test_model_is_configurable(): void
    {
        config(['openai.model' => 'gpt-4o-mini']);
        OpenAI::fake([CreateResponse::fake()]);

        $item = $this->makeItemWithExamples(0);
        app(OpenAiService::class)->generateText($item);

        OpenAI::assertSent(\OpenAI\Resources\Chat::class, function (string $method, array $parameters) {
            return $parameters['model'] === 'gpt-4o-mini';
        });
    }
}
