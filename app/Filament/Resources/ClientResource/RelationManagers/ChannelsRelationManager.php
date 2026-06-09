<?php

namespace App\Filament\Resources\ClientResource\RelationManagers;

use App\Enums\SocialNetwork;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ChannelsRelationManager extends RelationManager
{
    protected static string $relationship = 'channels';
    protected static ?string $title = 'Kanalen';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('network')
                ->label('Netwerk')
                ->options(collect(SocialNetwork::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()]))
                ->required(),

            Forms\Components\TextInput::make('name')
                ->label('Naam')
                ->required(),

            Forms\Components\TextInput::make('publer_account_id')
                ->label('Publer Account ID'),

            Forms\Components\Toggle::make('active')
                ->label('Actief')
                ->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('network')
                    ->label('Netwerk')
                    ->formatStateUsing(fn ($state) => $state instanceof SocialNetwork ? $state->label() : $state),
                Tables\Columns\TextColumn::make('name')->label('Naam'),
                Tables\Columns\TextColumn::make('publer_account_id')->label('Publer ID'),
                Tables\Columns\IconColumn::make('active')->label('Actief')->boolean(),
            ])
            ->headerActions([Tables\Actions\CreateAction::make()])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }
}
