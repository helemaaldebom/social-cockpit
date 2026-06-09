<?php

namespace App\Filament\Resources;

use App\Enums\SourceCategory;
use App\Filament\Resources\SourceResource\Pages;
use App\Models\Source;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SourceResource extends Resource
{
    protected static ?string $model = Source::class;
    protected static ?string $navigationIcon = 'heroicon-o-rss';
    protected static ?string $navigationLabel = 'RSS-bronnen';
    protected static ?string $modelLabel = 'Bron';
    protected static ?string $pluralModelLabel = 'Bronnen';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('client_id')
                ->label('Klant')
                ->relationship('client', 'name')
                ->required(),

            Forms\Components\TextInput::make('url')
                ->label('Feed URL')
                ->url()
                ->required(),

            Forms\Components\Select::make('category')
                ->label('Categorie')
                ->options(collect(SourceCategory::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()]))
                ->required(),

            Forms\Components\Toggle::make('active')
                ->label('Actief')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('client.name')->label('Klant')->sortable(),
                Tables\Columns\TextColumn::make('url')->label('URL')->limit(50),
                Tables\Columns\TextColumn::make('category')
                    ->label('Categorie')
                    ->formatStateUsing(fn ($state) => $state instanceof SourceCategory ? $state->label() : $state),
                Tables\Columns\IconColumn::make('active')->label('Actief')->boolean(),
                Tables\Columns\TextColumn::make('last_fetched_at')
                    ->label('Laatste fetch')
                    ->dateTime('d-m-Y H:i')
                    ->timezone('Europe/Amsterdam'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSources::route('/'),
            'create' => Pages\CreateSource::route('/create'),
            'edit' => Pages\EditSource::route('/{record}/edit'),
        ];
    }
}
