<?php

namespace Database\Seeders;

use App\Enums\SocialNetwork;
use App\Models\Channel;
use App\Models\Client;
use App\Models\PublishSlot;
use App\Models\Source;
use App\Enums\SourceCategory;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class ClientSeeder extends Seeder
{
    public function run(): void
    {
        // ZTS / Zanen Techniek Service
        $zts = Client::firstOrCreate(
            ['slug' => 'zts'],
            [
                'name' => 'ZTS / Zanen Techniek Service',
                'tone_of_voice' => 'Je bent een social media copywriter voor ZTS, een technisch servicebedrijf. Schrijf professionele, heldere posts die de expertise en betrouwbaarheid van het bedrijf uitstralen. Gebruik een zakelijke maar toegankelijke toon.',
                'active' => true,
            ]
        );

        foreach ([SocialNetwork::Facebook, SocialNetwork::Instagram, SocialNetwork::LinkedIn] as $network) {
            Channel::firstOrCreate(
                ['client_id' => $zts->id, 'network' => $network->value],
                ['name' => "ZTS {$network->label()}", 'active' => true]
            );
        }

        // Dinsdag 07:30
        PublishSlot::firstOrCreate(
            ['client_id' => $zts->id, 'day_of_week' => 2, 'time' => '07:30:00'],
            ['timezone' => 'Europe/Amsterdam', 'interval_weeks' => 1, 'active' => true]
        );

        // Vrijdag 07:30
        PublishSlot::firstOrCreate(
            ['client_id' => $zts->id, 'day_of_week' => 5, 'time' => '07:30:00'],
            ['timezone' => 'Europe/Amsterdam', 'interval_weeks' => 1, 'active' => true]
        );

        // Landus
        $landus = Client::firstOrCreate(
            ['slug' => 'landus'],
            [
                'name' => 'Landus',
                'tone_of_voice' => 'Je bent een social media copywriter voor Landus. Schrijf professionele LinkedIn-posts die thought leadership uitstralen in de agrarische sector.',
                'active' => true,
            ]
        );

        Channel::firstOrCreate(
            ['client_id' => $landus->id, 'network' => SocialNetwork::LinkedIn->value],
            ['name' => 'Landus LinkedIn', 'active' => true]
        );

        // Donderdag 10:00, tweewekelijks — referentiedatum eerste aankomende donderdag
        $referenceDate = Carbon::now()->next('Thursday')->toDateString();
        PublishSlot::firstOrCreate(
            ['client_id' => $landus->id, 'day_of_week' => 4, 'time' => '10:00:00'],
            [
                'timezone' => 'Europe/Amsterdam',
                'interval_weeks' => 2,
                'reference_date' => $referenceDate,
                'active' => true,
            ]
        );

        // buro_deBom
        $burodebom = Client::firstOrCreate(
            ['slug' => 'burodebom'],
            [
                'name' => 'buro_deBom',
                'tone_of_voice' => 'Je bent een social media copywriter voor buro_deBom, een creatief digitaal bureau. Schrijf inspirerende, trendbewuste posts over AI, webdevelopment, online marketing en conversieoptimalisatie. Gebruik een enthousiaste maar deskundige toon.',
                'active' => true,
            ]
        );

        foreach ([SocialNetwork::Facebook, SocialNetwork::Instagram, SocialNetwork::LinkedIn] as $network) {
            Channel::firstOrCreate(
                ['client_id' => $burodebom->id, 'network' => $network->value],
                ['name' => "buro_deBom {$network->label()}", 'active' => true]
            );
        }

        // Publish slot nader te bepalen — aanmaken als inactive
        PublishSlot::firstOrCreate(
            ['client_id' => $burodebom->id, 'day_of_week' => 1, 'time' => '09:00:00'],
            ['timezone' => 'Europe/Amsterdam', 'interval_weeks' => 1, 'active' => false]
        );

        // RSS-bronnen voor buro_deBom
        $feeds = [
            ['url' => 'https://feeds.feedburner.com/oreilly/radar', 'category' => SourceCategory::AI],
            ['url' => 'https://www.smashingmagazine.com/feed/', 'category' => SourceCategory::Webdev],
            ['url' => 'https://www.searchenginejournal.com/feed/', 'category' => SourceCategory::SEO],
            ['url' => 'https://www.marketingprofs.com/rss/articles.asp', 'category' => SourceCategory::Marketing],
            ['url' => 'https://conversionxl.com/blog/feed/', 'category' => SourceCategory::Conversie],
        ];

        foreach ($feeds as $feed) {
            Source::firstOrCreate(
                ['client_id' => $burodebom->id, 'url' => $feed['url']],
                ['category' => $feed['category']->value, 'active' => true]
            );
        }

        // Bas Romeijn persoonlijk
        $bas = Client::firstOrCreate(
            ['slug' => 'bas-romeijn'],
            [
                'name' => 'Bas Romeijn',
                'tone_of_voice' => 'Je schrijft LinkedIn-posts in de ik-vorm voor Bas Romeijn, eigenaar van buro_deBom. Gebruik een persoonlijke, authentieke toon. Deel inzichten, ervaringen en learnings over digitale strategie en ondernemerschap.',
                'active' => true,
            ]
        );

        Channel::firstOrCreate(
            ['client_id' => $bas->id, 'network' => SocialNetwork::LinkedIn->value],
            ['name' => 'Bas Romeijn LinkedIn', 'active' => true]
        );

        // Publish slot nader te bepalen — aanmaken als inactive
        PublishSlot::firstOrCreate(
            ['client_id' => $bas->id, 'day_of_week' => 3, 'time' => '08:00:00'],
            ['timezone' => 'Europe/Amsterdam', 'interval_weeks' => 1, 'active' => false]
        );
    }
}
