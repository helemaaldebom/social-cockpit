<?php

namespace App\Filament\Resources;

use App\Enums\ContentStatus;
use App\Filament\Resources\ContentItemResource\Pages;
use App\Jobs\GenerateContentTextJob;
use App\Jobs\SchedulePostToPublerJob;
use App\Models\Channel;
use App\Models\Client;
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

            // Dezelfde inzending naar meerdere klanten posten. Per extra klant
            // wordt een eigen content-item aangemaakt dat in díé klant z'n
            // tone-of-voice wordt gegenereerd (zelfde brief/media als bron).
            Forms\Components\Select::make('also_post_to_client_ids')
                ->label('Ook posten naar (andere klanten)')
                ->helperText('Maakt per aangevinkte klant een eigen post, gegenereerd in die klant z\'n tone-of-voice. Zelfde brief en media. Wordt ingepland op de gekozen datum/tijd hieronder.')
                ->multiple()
                ->options(fn (Get $get) => Client::query()
                    ->where('active', true)
                    ->when($get('client_id'), fn ($q, $id) => $q->whereKeyNot($id))
                    ->orderBy('name')
                    ->pluck('name', 'id'))
                ->dehydrated(false)
                ->visibleOn('create')
                ->columnSpanFull(),

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
                ->maxSize(204800)
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
                ->seconds(false)
                // Bij aanmaken is de publicatietijd leidend (ook voor klanten
                // zonder actief slot), dus verplicht op de create-pagina.
                ->required(fn (string $operation) => $operation === 'create'),
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

                // Goedkeuren = het ENIGE pad naar publicatie. Niets wordt
                // ingepland of geplaatst zonder deze expliciete handeling.
                Tables\Actions\Action::make('approve')
                    ->label('Goedkeuren')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (ContentItem $record) => $record->status === ContentStatus::InReview)
                    ->requiresConfirmation()
                    ->modalHeading(fn (ContentItem $record) => 'Goedkeuren: ' . $record->title)
                    ->modalDescription(fn (ContentItem $record) => new \Illuminate\Support\HtmlString(
                        '<div style="text-align:left">'
                        . '<p><strong>Tekst die geplaatst wordt:</strong></p>'
                        . '<p style="white-space:pre-wrap">' . e((string) $record->generated_text) . '</p>'
                        . (filled($record->original_text)
                            ? '<hr style="margin:.75rem 0"><p><strong>Ingezonden tekst:</strong></p><p style="white-space:pre-wrap">' . e($record->original_text) . '</p>'
                            : '')
                        . (filled($record->source_note)
                            ? '<hr style="margin:.75rem 0"><p><strong>Notitie van inzender (wordt niet geplaatst):</strong></p><p style="white-space:pre-wrap">' . e($record->source_note) . '</p>'
                            : '')
                        . '</div>'
                    ))
                    ->modalSubmitActionLabel('Goedkeuren en inplannen')
                    ->action(function (ContentItem $record) {
                        self::approveRecord($record);
                    }),

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
            ->bulkActions([
                Tables\Actions\BulkAction::make('approve_selected')
                    ->label('Selectie goedkeuren')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Alle geselecteerde posts met status "In review" worden goedgekeurd en ingepland. Posts met een andere status worden overgeslagen.')
                    ->deselectRecordsAfterCompletion()
                    ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                        $records->filter(fn (ContentItem $r) => $r->status === ContentStatus::InReview)
                            ->each(fn (ContentItem $r) => self::approveRecord($r, notify: false));
                        Notification::make()->title('Selectie goedgekeurd')->success()->send();
                    }),
            ])
            ->defaultSort('scheduled_for', 'asc');
    }

    /**
     * Keurt een item goed en plant het in (gekozen tijd of eerstvolgend vrij
     * slot). Lukt inplannen nu niet, dan blijft het op Goedgekeurd staan en
     * pakt de uurlijkse scheduler het op.
     */
    public static function approveRecord(ContentItem $record, bool $notify = true): void
    {
        $record->changeStatus(ContentStatus::Goedgekeurd, 'Goedgekeurd via Filament.');

        $slot = \App\Jobs\ProcessAutoSchedulerJob::scheduleApproved($record->fresh(['client', 'channels']));

        if ($notify) {
            Notification::make()
                ->title($slot ? 'Goedgekeurd — wordt ingepland op ' . $slot->format('d-m-Y H:i') : 'Goedgekeurd — wacht op een vrij slot')
                ->success()
                ->send();
        }
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
