<?php

namespace App\Filament\Resources;

use App\Enums\ContentStatus;
use App\Filament\Resources\ContentItemResource\Pages;
use App\Jobs\GenerateContentTextJob;
use App\Jobs\SchedulePostToPublerJob;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\PublishSlot;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ContentItemResource extends Resource
{
    protected static ?string $model = ContentItem::class;
    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';
    protected static ?string $navigationLabel = 'Content Kalender';
    protected static ?string $modelLabel = 'Content item';
    protected static ?string $pluralModelLabel = 'Content items';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('client_id')
                ->label('Klant')
                ->relationship('client', 'name', fn ($query) => $query->where('active', true))
                ->required()
                ->live()
                ->afterStateUpdated(function (Get $get, Set $set, ?string $state) {
                    if (! $state) return;

                    $slots = PublishSlot::where('client_id', $state)
                        ->where('active', true)
                        ->get();

                    if ($slots->isEmpty()) return;

                    // Loop door maximaal 20 toekomstige occurrences om de eerste vrije te vinden
                    $after = \Carbon\Carbon::now();
                    $found = null;

                    for ($i = 0; $i < 20; $i++) {
                        // Vind de eerstvolgende occurrence na $after over alle slots
                        $candidate = $slots
                            ->map(fn ($slot) => $slot->nextOccurrence($after))
                            ->filter()
                            ->sort()
                            ->first();

                        if (! $candidate) break;

                        $utc = $candidate->copy()->utc();
                        $taken = \App\Models\ContentItem::where('client_id', $state)
                            ->whereIn('status', ['ingepland', 'geplaatst'])
                            ->whereBetween('scheduled_for', [
                                $utc->copy()->subMinutes(30),
                                $utc->copy()->addMinutes(30),
                            ])
                            ->exists();

                        if (! $taken) {
                            $found = $candidate;
                            break;
                        }

                        // Datum is bezet — zoek verder na deze occurrence
                        $after = $candidate->copy()->addMinutes(1);
                    }

                    if ($found) {
                        $set('scheduled_for', $found->setTimezone('Europe/Amsterdam')->format('Y-m-d H:i:s'));
                    }
                }),

            Forms\Components\TextInput::make('title')
                ->label('Titel')
                ->required(),

            Forms\Components\Textarea::make('brief')
                ->label('Brief')
                ->rows(4)
                ->required()
                ->columnSpanFull(),

            Forms\Components\Textarea::make('original_text')
                ->label('Originele tekst van klant')
                ->rows(4)
                ->columnSpanFull()
                ->helperText('De ongewijzigde tekst zoals door de klant ingediend. Wordt niet door AI overschreven.'),

            Forms\Components\Textarea::make('generated_text')
                ->label('Gegenereerde tekst')
                ->rows(6)
                ->columnSpanFull(),

            Forms\Components\FileUpload::make('media_paths')
                ->label('Media (foto / video, meerdere mogelijk)')
                ->disk('public')
                ->directory('media')
                ->multiple()
                ->reorderable()
                ->downloadable()
                ->maxSize(102400)
                ->acceptedFileTypes(['image/*', 'video/*', 'application/pdf'])
                ->columnSpanFull(),

            Forms\Components\Select::make('channels')
                ->label('Kanalen')
                ->multiple()
                ->relationship('channels', 'name')
                ->options(function (Get $get) {
                    $clientId = $get('client_id');
                    if (! $clientId) return [];
                    return Channel::where('client_id', $clientId)
                        ->where('active', true)
                        ->pluck('name', 'id');
                })
                ->columnSpanFull(),

            Forms\Components\DateTimePicker::make('scheduled_for')
                ->label('Gepland op')
                ->timezone('Europe/Amsterdam')
                ->seconds(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('client.name')
                    ->label('Klant')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('title')
                    ->label('Titel')
                    ->searchable()
                    ->limit(40),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->formatStateUsing(fn ($state) => $state instanceof ContentStatus ? $state->label() : $state)
                    ->colors([
                        'secondary' => ContentStatus::Concept->value,
                        'primary' => ContentStatus::Gegenereerd->value,
                        'warning' => ContentStatus::InReview->value,
                        'success' => fn ($state) => in_array($state instanceof ContentStatus ? $state->value : $state, [
                            ContentStatus::Goedgekeurd->value,
                            ContentStatus::Ingepland->value,
                            ContentStatus::Geplaatst->value,
                        ]),
                        'danger' => ContentStatus::Mislukt->value,
                    ]),

                Tables\Columns\TextColumn::make('scheduled_for')
                    ->label('Gepland op')
                    ->dateTime('d-m-Y H:i')
                    ->timezone('Europe/Amsterdam')
                    ->sortable(),

                Tables\Columns\TextColumn::make('channels.name')
                    ->label('Kanalen')
                    ->badge(),

                Tables\Columns\TextColumn::make('generated_text')
                    ->label('Tekst')
                    ->limit(80)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('client')
                    ->relationship('client', 'name')
                    ->label('Klant'),

                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(ContentStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])),
            ])
            ->actions([
                Tables\Actions\Action::make('generate')
                    ->label('Genereer tekst')
                    ->icon('heroicon-o-sparkles')
                    ->visible(fn (ContentItem $record) => $record->status === ContentStatus::Concept)
                    ->action(function (ContentItem $record) {
                        GenerateContentTextJob::dispatch($record);
                        Notification::make()->title('Generatie gestart')->success()->send();
                    }),

                // Goedkeuren-actie verwijderd: nieuwe items gaan via de
                // auto-flow (GenerateContentTextJob -> SchedulePostToPublerJob)
                // direct naar Ingepland, en Telegram is het reviewmoment.
                // De "Direct goedkeuren"-actie voor handgeschreven posts in
                // Filament zelf blijft beschikbaar voor klanten die handmatig
                // content maken (bv. buro_deBom).
                Tables\Actions\Action::make('approve_direct')
                    ->label('Direct goedkeuren')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (ContentItem $record) => $record->status === ContentStatus::Concept
                        && filled($record->generated_text))
                    ->requiresConfirmation()
                    ->modalHeading('Handgeschreven post direct goedkeuren')
                    ->modalDescription('Hiermee sla je de AI-generatie en de review over. De post komt meteen op status Goedgekeurd en kan ingepland worden.')
                    ->modalSubmitActionLabel('Ja, direct goedkeuren')
                    ->action(function (ContentItem $record) {
                        $record->changeStatus(ContentStatus::Goedgekeurd, 'Handgeschreven post direct goedgekeurd via Filament.');
                        Notification::make()->title('Goedgekeurd')->success()->send();
                    }),

                Tables\Actions\Action::make('schedule')
                    ->label('Inplannen bij Publer')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('primary')
                    ->visible(fn (ContentItem $record) => $record->status === ContentStatus::Goedgekeurd)
                    ->requiresConfirmation()
                    ->modalHeading('Post inplannen bij Publer')
                    ->modalDescription(fn (ContentItem $record) => "Post \"{$record->title}\" inplannen op " . ($record->scheduled_for?->timezone('Europe/Amsterdam')->format('d-m-Y H:i') ?? '(geen datum ingesteld)') . '?')
                    ->modalSubmitActionLabel('Ja, inplannen')
                    ->action(function (ContentItem $record) {
                        $accountIds = $record->channels->pluck('publer_account_id')->filter()->values()->toArray();

                        if (empty($accountIds)) {
                            Notification::make()->title('Geen Publer-accounts gekoppeld aan de kanalen')->danger()->send();
                            return;
                        }

                        if (! $record->scheduled_for) {
                            Notification::make()->title('Stel eerst een datum/tijd in bij dit item')->danger()->send();
                            return;
                        }

                        SchedulePostToPublerJob::dispatch($record, $accountIds, $record->scheduled_for->toIso8601String());
                        Notification::make()->title('Wordt ingepland bij Publer…')->success()->send();
                    }),

                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('scheduled_for', 'asc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListContentItems::route('/'),
            'create' => Pages\CreateContentItem::route('/create'),
            'edit' => Pages\EditContentItem::route('/{record}/edit'),
        ];
    }
}
