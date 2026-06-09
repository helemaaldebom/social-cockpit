<?php

namespace App\Filament\Resources\ContentItemResource\Pages;

use App\Jobs\GenerateContentTextJob;
use App\Filament\Resources\ContentItemResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateContentItem extends CreateRecord
{
    protected static string $resource = ContentItemResource::class;

    protected function afterCreate(): void
    {
        GenerateContentTextJob::dispatch($this->getRecord());
        Notification::make()->title('Tekst wordt gegenereerd…')->success()->send();
    }
}
