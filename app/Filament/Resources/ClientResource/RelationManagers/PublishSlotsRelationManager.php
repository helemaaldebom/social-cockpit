<?php

namespace App\Filament\Resources\ClientResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class PublishSlotsRelationManager extends RelationManager
{
    protected static string $relationship = 'publishSlots';
    protected static ?string $title = 'Publish Slots';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('day_of_week')
                ->label('Dag van de week')
                ->options([
                    1 => 'Maandag', 2 => 'Dinsdag', 3 => 'Woensdag',
                    4 => 'Donderdag', 5 => 'Vrijdag', 6 => 'Zaterdag', 7 => 'Zondag',
                ])
                ->required(),

            Forms\Components\TimePicker::make('time')
                ->label('Tijdstip')
                ->required(),

            Forms\Components\TextInput::make('timezone')
                ->label('Tijdzone')
                ->default('Europe/Amsterdam')
                ->required(),

            Forms\Components\TextInput::make('interval_weeks')
                ->label('Interval (weken)')
                ->numeric()
                ->default(1)
                ->minValue(1),

            Forms\Components\DatePicker::make('reference_date')
                ->label('Referentiedatum (voor tweewekelijkse cyclus)'),

            Forms\Components\Toggle::make('active')
                ->label('Actief')
                ->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('day_of_week')
                    ->label('Dag')
                    ->formatStateUsing(fn ($state) => match((int)$state) {
                        1 => 'Maandag', 2 => 'Dinsdag', 3 => 'Woensdag',
                        4 => 'Donderdag', 5 => 'Vrijdag', 6 => 'Zaterdag', 7 => 'Zondag',
                        default => $state,
                    }),
                Tables\Columns\TextColumn::make('time')->label('Tijd'),
                Tables\Columns\TextColumn::make('interval_weeks')->label('Interval'),
                Tables\Columns\IconColumn::make('active')->label('Actief')->boolean(),
            ])
            ->headerActions([Tables\Actions\CreateAction::make()])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }
}
