<?php

namespace App\Services;

use App\Models\ContentItem;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;

class OpenAiService
{
    public function generateText(ContentItem $item): string
    {
        // Generatie: tone-of-voice + een beperkt aantal few-shot voorbeelden.
        $messages = $this->buildMessages($item, withExamples: true);
        $messages[] = ['role' => 'user', 'content' => $item->brief];

        return $this->chat($messages, $item, 'generate');
    }

    public function refineText(ContentItem $item, string $instruction): string
    {
        // Verfijning: GEEN voorbeeldposts meesturen — de huidige tekst toont de
        // stijl al en is zelf het onderwerp van de bewerking. Dit scheelt
        // duizenden prompt-tokens per Telegram-edit zonder kwaliteitsverlies.
        $messages = $this->buildMessages($item, withExamples: false);
        $messages[] = [
            'role' => 'user',
            'content' => "Huidige tekst:\n{$item->generated_text}\n\nPas deze tekst aan op basis van de volgende instructie:\n{$instruction}",
        ];

        return $this->chat($messages, $item, 'refine');
    }

    /**
     * Voer de chat-call uit en log het tokenverbruik (geen inhoud, geen keys).
     */
    private function chat(array $messages, ContentItem $item, string $purpose): string
    {
        $model = config('openai.model', 'gpt-4o');

        $response = OpenAI::chat()->create([
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => (int) config('openai.max_output_tokens', 600),
            // Lagere temperatuur = minder "creatief invullen" van ontbrekende
            // details. Samen met de anti-fabricatie systeemregel houdt dit de
            // output dicht bij de feiten uit de klantinzending.
            'temperature' => (float) config('openai.temperature', 0.4),
        ]);

        Log::info('OpenAI tokenverbruik', [
            'purpose'           => $purpose,
            'content_item_id'   => $item->id,
            'model'             => $model,
            'prompt_tokens'     => $response->usage->promptTokens ?? null,
            'completion_tokens' => $response->usage->completionTokens ?? null,
            'total_tokens'      => $response->usage->totalTokens ?? null,
        ]);

        return $this->stripMarkdownEmphasis($response->choices[0]->message->content ?? '');
    }

    /**
     * Vangnet: verwijder markdown-bold/cursief (asterisks) uit AI-output.
     * Social platforms renderen geen markdown — sterretjes verschijnen daar
     * letterlijk in de post. De prompt verbiedt ze al; dit garandeert het.
     */
    private function stripMarkdownEmphasis(string $text): string
    {
        $text = str_replace('**', '', $text);
        // Enkel-asterisk cursief om een woord/zinsdeel (geen regels die met * beginnen — dat zijn opsommingen)
        $text = preg_replace('/(?<![\w*])\*([^*\n]+)\*(?![\w*])/u', '$1', $text);

        return $text;
    }

    /**
     * Bouw de berichtenreeks op met systeemprompt en (optioneel) few-shot
     * voorbeelden. Voorbeeldposts worden als user/assistant paren meegegeven
     * zodat OpenAI de schrijfstijl direct overneemt.
     */
    private function buildMessages(ContentItem $item, bool $withExamples = true): array
    {
        $client = $item->client;

        $systemPrompt = $client->tone_of_voice
            ?? 'Je bent een social media copywriter. Schrijf een engaging social media post.';

        // Harde anti-fabricatie regel, altijd meegestuurd los van de klantprompt.
        // Dit kan niet per ongeluk uit een klant-tone-of-voice verdwijnen.
        $factsGuard = 'STRIKTE REGEL: gebruik uitsluitend feiten die letterlijk in '
            . 'de aangeleverde tekst van de klant staan. Verzin NOOIT bedrijfsnamen, '
            . 'klantnamen, plaatsnamen, specificaties, maten, gewichten, aantallen, '
            . 'merken, prijzen of wat er vervoerd/geleverd/gerepareerd is. Staat iets '
            . 'niet in de klanttekst, dan schrijf je het niet. Is de input kort, dan '
            . 'is de post kort. Een korte kloppende post is altijd beter dan een langere '
            . 'met verzonnen details.';

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'system', 'content' => $factsGuard],
        ];

        if (! $withExamples) {
            return $messages;
        }

        $maxExamples = (int) config('openai.max_examples', 4);

        // Haal de actieve kanalen op om de juiste voorbeelden te filteren
        $networks = $item->channels->pluck('network')
            ->map(fn ($n) => $n instanceof \App\Enums\SocialNetwork ? $n->value : $n)
            ->unique()
            ->values();

        // Laad voorbeeldposts — filter op netwerk als er kanalen gekoppeld zijn
        $examples = $client->examples()
            ->when($networks->isNotEmpty(), fn ($q) => $q->whereIn('network', $networks))
            ->limit($maxExamples)
            ->get();

        // Als er geen netwerk-specifieke voorbeelden zijn, pak alle voorbeelden
        if ($examples->isEmpty()) {
            $examples = $client->examples()->limit($maxExamples)->get();
        }

        if ($examples->isNotEmpty()) {
            // Voeg instructie toe om de stijl van de voorbeelden over te nemen
            $messages[] = [
                'role' => 'system',
                'content' => 'Hieronder staan voorbeeldposts van deze klant. Schrijf altijd in dezelfde stijl, toon en opmaak als deze voorbeelden. Neem de typische opbouw, zinslengte en woordkeuze over.',
            ];

            // Few-shot: elk voorbeeld als user/assistant paar
            foreach ($examples as $example) {
                $messages[] = [
                    'role' => 'user',
                    'content' => "Schrijf een post in de stijl van {$client->name}.",
                ];
                $messages[] = [
                    'role' => 'assistant',
                    'content' => $example->content,
                ];
            }
        }

        return $messages;
    }
}
