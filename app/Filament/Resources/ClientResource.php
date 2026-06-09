<?php

namespace App\Filament\Resources;

use App\Enums\SocialNetwork;
use App\Filament\Resources\ClientResource\Pages;
use App\Filament\Resources\ClientResource\RelationManagers;
use App\Models\Client;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class ClientResource extends Resource
{
    protected static ?string $model = Client::class;
    protected static ?string $navigationIcon = 'heroicon-o-building-office';
    protected static ?string $navigationLabel = 'Klanten';
    protected static ?string $modelLabel = 'Klant';
    protected static ?string $pluralModelLabel = 'Klanten';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('Naam')
                ->required()
                ->live(onBlur: true)
                ->afterStateUpdated(fn ($state, Forms\Set $set) => $set('slug', Str::slug($state))),

            Forms\Components\TextInput::make('slug')
                ->label('Slug')
                ->required()
                ->unique(ignoreRecord: true),

            Forms\Components\Textarea::make('tone_of_voice')
                ->label('Tone of Voice (OpenAI systeemprompt)')
                ->rows(4)
                ->columnSpanFull(),

            Forms\Components\Toggle::make('active')
                ->label('Actief')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Naam')->searchable(),
                Tables\Columns\TextColumn::make('slug')->label('Slug'),
                Tables\Columns\IconColumn::make('active')->label('Actief')->boolean(),
                Tables\Columns\TextColumn::make('channels_count')
                    ->label('Kanalen')
                    ->counts('channels'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('active')->label('Actief'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ChannelsRelationManager::class,
            RelationManagers\PublishSlotsRelationManager::class,
            RelationManagers\ExamplesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListClients::route('/'),
            'create' => Pages\CreateClient::route('/create'),
            'edit' => Pages\EditClient::route('/{record}/edit'),
        ];
    }
}
