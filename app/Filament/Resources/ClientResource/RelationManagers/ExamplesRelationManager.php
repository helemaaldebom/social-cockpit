<?php

namespace App\Filament\Resources\ClientResource\RelationManagers;

use App\Enums\SocialNetwork;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ExamplesRelationManager extends RelationManager
{
    protected static string $relationship = 'examples';
    protected static ?string $title = 'Voorbeeldposts (kennisbestand)';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('network')
                ->label('Netwerk')
                ->options(collect(SocialNetwork::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()]))
                ->default('linkedin')
                ->required(),

            Forms\Components\TextInput::make('label')
                ->label('Label (optioneel)')
                ->placeholder('bv. Klantcase, Productlancering, Thought leadership')
                ->maxLength(100),

            Forms\Components\Textarea::make('content')
                ->label('Voorbeeldpost tekst')
                ->rows(8)
                ->required()
                ->columnSpanFull()
                ->helperText('Plak hier een bestaande post die representatief is voor de schrijfstijl van deze klant.'),

            Forms\Components\TextInput::make('sort_order')
                ->label('Volgorde')
                ->numeric()
                ->default(0)
                ->helperText('Lagere waarde = eerder getoond aan OpenAI'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->reorderable('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('network')
                    ->label('Netwerk')
                    ->badge()
                    ->formatStateUsing(fn ($state) => strtoupper($state))
                    ->color(fn ($state) => match($state) {
                        'linkedin' => 'info',
                        'facebook' => 'primary',
                        'instagram' => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('label')
                    ->label('Label')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('content')
                    ->label('Tekst')
                    ->limit(80)
                    ->wrap(),

                Tables\Columns\TextColumn::make('sort_order')
                    ->label('#')
                    ->sortable(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Voorbeeldpost toevoegen'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Nog geen voorbeeldposts')
            ->emptyStateDescription('Voeg 3–15 representatieve posts toe. Hoe meer voorbeelden, hoe beter OpenAI de schrijfstijl overneemt.')
            ->emptyStateIcon('heroicon-o-document-text');
    }
}
