<?php

namespace App\Filament\Resources\ContentItemResource\Pages;

use App\Enums\ContentStatus;
use App\Filament\Resources\ContentItemResource;
use App\Models\ContentItem;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListContentItems extends ListRecords
{
    protected static string $resource = ContentItemResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }

    public function getTabs(): array
    {
        $reviewCount = ContentItem::where('status', ContentStatus::InReview->value)->count();

        return [
            'alles' => Tab::make('Alles'),
            'review' => Tab::make('Te beoordelen')
                ->badge($reviewCount ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', ContentStatus::InReview->value)),
            'ingepland' => Tab::make('Ingepland')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', ContentStatus::Ingepland->value)),
            'mislukt' => Tab::make('Mislukt')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', ContentStatus::Mislukt->value)),
        ];
    }
}
