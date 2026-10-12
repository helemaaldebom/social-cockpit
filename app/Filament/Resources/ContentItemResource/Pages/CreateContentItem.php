<?php

namespace App\Filament\Resources\ContentItemResource\Pages;

use App\Enums\ContentStatus;
use App\Jobs\GenerateContentTextJob;
use App\Filament\Resources\ContentItemResource;
use App\Models\Channel;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateContentItem extends CreateRecord
{
    protected static string $resource = ContentItemResource::class;

    protected function afterCreate(): void
    {
        $record = $this->getRecord();
        GenerateContentTextJob::dispatch($record);

        // "Ook posten naar": per extra klant een eigen item, in díé klant z'n
        // tone-of-voice. Zelfde brief/media/planning, eigen kanalen.
        $extra = collect($this->data['also_post_to_client_ids'] ?? [])
            ->map(fn ($v) => (int) $v)
            ->filter()
            ->unique()
            ->reject(fn ($id) => $id === (int) $record->client_id);

        foreach ($extra as $clientId) {
            $copy = $record->replicate([
                'publer_post_id', 'publer_post_ids', 'telegram_message_id', 'source_article_id',
            ]);
            $copy->client_id = $clientId;
            $copy->status = ContentStatus::Concept;
            $copy->generated_text = null;
            $copy->save();

            $copy->channels()->sync(
                Channel::where('client_id', $clientId)->where('active', true)->pluck('id')->all()
            );

            GenerateContentTextJob::dispatch($copy);
        }

        $total = $extra->count() + 1;
        Notification::make()
            ->title($total > 1
                ? "Tekst wordt gegenereerd voor {$total} klanten…"
                : 'Tekst wordt gegenereerd…')
            ->success()
            ->send();
    }
}
