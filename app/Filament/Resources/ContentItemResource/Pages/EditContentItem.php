<?php

namespace App\Filament\Resources\ContentItemResource\Pages;

use App\Enums\ContentStatus;
use App\Filament\Resources\ContentItemResource;
use App\Jobs\GenerateContentTextJob;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditContentItem extends EditRecord
{
    protected static string $resource = ContentItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('regenerate')
                ->label('Opnieuw genereren')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function () {
                    GenerateContentTextJob::dispatch($this->getRecord());
                    Notification::make()->title('Tekst wordt opnieuw gegenereerd…')->success()->send();
                }),
            Actions\DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        $record = $this->getRecord();

        if (in_array($record->status, [ContentStatus::Concept, ContentStatus::Gegenereerd])) {
            GenerateContentTextJob::dispatch($record);
            Notification::make()->title('Tekst wordt gegenereerd…')->success()->send();
        }
    }
}
