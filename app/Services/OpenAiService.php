<?php

namespace App\Services;

use App\Models\ContentItem;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;

class OpenAiService
{
    public function generateText(ContentItem $item): string
    {
        $messages = $this->buildMessages($item);
        $messages[] = ['role' => 'user', 'content' => $item->brief];

        $response = OpenAI::chat()->create([
            'model' => 'gpt-4o',
            'messages' => $messages,
            'max_tokens' => 600,
        ]);

        return $response->choices[0]->message->content ?? '';
    }

    public function refineText(ContentItem $item, string $instruction): string
    {
        $messages = $this->buildMessages($item);
        $messages[] = [
            'role' => 'user',
            'content' => "Huidige tekst:\n{$item->generated_text}\n\nPas deze tekst aan op basis van de volgende instructie:\n{$instruction}",
        ];

        $response = OpenAI::chat()->create([
            'model' => 'gpt-4o',
            'messages' => $messages,
            'max_tokens' => 600,
        ]);

        return $response->choices[0]->message->content ?? '';
    }

    /**
     * Bouw de berichtenreeks op met systeemprompt en few-shot voorbeelden.
     * Voorbeeldposts worden als user/assistant paren meegegeven zodat OpenAI
     * de schrijfstijl direct overneemt.
     */
    private function buildMessages(ContentItem $item): array
    {
        $client = $item->client;

        $systemPrompt = $client->tone_of_voice
            ?? 'Je bent een social media copywriter. Schrijf een engaging social media post.';

        // Haal de actieve kanalen op om de juiste voorbeelden te filteren
        $networks = $item->channels->pluck('network')
            ->map(fn ($n) => $n instanceof \App\Enums\SocialNetwork ? $n->value : $n)
            ->unique()
            ->values();

        // Laad voorbeeldposts — filter op netwerk als er kanalen gekoppeld zijn
        $examples = $client->examples()
            ->when($networks->isNotEmpty(), fn ($q) => $q->whereIn('network', $networks))
            ->limit(15)
            ->get();

        // Als er geen netwerk-specifieke voorbeelden zijn, pak alle voorbeelden
        if ($examples->isEmpty()) {
            $examples = $client->examples()->limit(15)->get();
        }

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        if ($examples->isNotEmpty()) {
            // Voeg instructie toe om de stijl van de voorbeelden over te nemen
            $messages[] = [
                'role' => 'system',
                'content' => 'Hieronder staan voorbeeldposts van deze klant. Schrijf altijd in dezelfde stijl, toon en opmaak als deze voorbeelden. Neem de typische opbouw, zinslengte en woordkeuze over.',
            ];

            // Few-shot: elk voorbeeld als user/assistant paar
            foreach ($examples as $example) {
                $label = $example->label ? "[{$example->label}]" : '[Voorbeeldpost]';
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
