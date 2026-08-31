<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\AbandonedCheckout\Filament\Resources\Abandonments;

use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Liberu\Ecommerce\AbandonedCheckout\Enums\StopReason;
use Liberu\Ecommerce\AbandonedCheckout\Exceptions\AbandonedCheckoutException;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Concerns\DeniesUnpublishedResourceAbilities;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Resources\Abandonments\Pages\ListAbandonments;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Resources\Abandonments\Pages\ViewAbandonment;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Resources\Abandonments\RelationManagers\AttemptsRelationManager;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Support\PanelTenant;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Support\Render;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Support\Snapshot;
use Liberu\Ecommerce\AbandonedCheckout\Models\Abandonment;
use Liberu\Ecommerce\AbandonedCheckout\Policies\CustodyPolicy;
use UnitEnum;

/**
 * A purchase that was begun and not finished, as it was at the moment it was
 * left — these lines, at these prices, in this currency.
 *
 * No contact or subject reference appears on the listing. The host's table put a
 * `NOT NULL` email on every row of the same idea and had no merchant column at
 * all; this one has the merchant and keeps the person off the index.
 */
final class AbandonmentResource extends Resource
{
    use DeniesUnpublishedResourceAbilities;

    protected static ?string $model = Abandonment::class;

    protected static bool $isScopedToTenant = false;

    protected static ?string $modelLabel = 'abandonment';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationLabel = 'Abandonments';

    protected static UnitEnum|string|null $navigationGroup = 'Abandoned checkout';

    protected static ?int $navigationSort = 20;

    public static function canViewAny(): bool
    {
        return PanelTenant::resolvable();
    }

    /** Standing is the domain's answer, asked by the row's key rather than by a reference two merchants can both hold. */
    public static function canView(Model $record): bool
    {
        return $record instanceof Abandonment
            && PanelTenant::resolvable()
            && CustodyPolicy::ownsAbandonment(PanelTenant::current(), $record->id);
    }

    /** @return Builder<Abandonment> */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Abandonment> $query */
        $query = parent::getEloquentQuery();

        return $query->where('tenant_id', PanelTenant::current())->withCount('attempts');
    }

    /** @return array<class-string> */
    public static function getRelations(): array
    {
        return [AttemptsRelationManager::class];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('checkout_ref')
                    ->label('Checkout')
                    ->searchable()
                    ->tooltip('An opaque reference to whatever owns the checkout. This module never resolves it and joins to no table it does not own.'),
                TextColumn::make('amount_minor')
                    ->label('Basket')
                    ->state(fn (Abandonment $record): string => self::amount($record))
                    ->tooltip('Placed by the exponent the domain stores beside the amount. 2500 GBP minor is 25.00 and 2500 JPY minor is 2500.'),
                TextColumn::make('items')
                    ->label('Items')
                    ->state(fn (Abandonment $record): string => self::items($record)),
                // Derived from the dated facts, so there is nothing to sort or
                // filter on: no `state` column exists and the database cannot
                // index a method. `stop_reason` below is the stored half.
                TextColumn::make('state')
                    ->label('State')
                    ->badge()
                    ->state(fn (Abandonment $record): string => Render::abandonmentLabel($record->stateAt(Snapshot::asOf())))
                    ->color(fn (Abandonment $record): string => Render::abandonmentColour($record->stateAt(Snapshot::asOf())))
                    ->tooltip('Derived from when this expires and whether outreach was stopped, and measured against one clock this page read once. It is not a column, so it cannot be sorted or filtered.'),
                TextColumn::make('attempts_count')
                    ->label('Attempts')
                    ->tooltip('Every attempt, including the ones that refused. A refusal is a row with a reason on it rather than a silence.'),
                TextColumn::make('abandoned_at')->label('Abandoned')->dateTime()->sortable(),
                TextColumn::make('expires_at')->label('Expires')->dateTime()->sortable(),
                TextColumn::make('stop_reason')
                    ->label('Stopped because')
                    ->badge()
                    ->state(fn (Abandonment $record): ?string => $record->stop_reason === null ? null : Render::stopLabel($record->stop_reason))
                    ->placeholder(Render::NONE)
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('stop_reason')
                    ->label('Stopped because')
                    ->options(fn (): array => self::stopOptions()),
            ])
            ->defaultSort('abandoned_at', 'desc')
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateIcon('heroicon-o-shopping-cart')
            ->emptyStateHeading('Nothing has been recorded as abandoned')
            ->emptyStateDescription('Which is not the same as nobody abandoning anything. This module owns no sweeper: whoever owns the checkout decides one has been abandoned and calls RecordAbandonment. The host had three tables for this and nothing anywhere that wrote a row.');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('What was left')
                ->description('The snapshot as it was stored. These lines are never resolved again: the catalogue they describe will be repriced, and a message that reprices itself is not a recovery of what was abandoned.')
                ->columns(3)
                ->schema([
                    TextEntry::make('checkout_ref')->label('Checkout'),
                    TextEntry::make('amount_minor')
                        ->label('Basket total')
                        ->state(fn (Abandonment $record): string => self::amount($record))
                        ->helperText('Minor units and an ISO 4217 code, placed by the exponent stored beside them.'),
                    TextEntry::make('items')
                        ->label('Items')
                        ->state(fn (Abandonment $record): string => self::items($record)),
                    TextEntry::make('lines')
                        ->hiddenLabel()
                        ->state(fn (Abandonment $record): array => self::lines($record))
                        ->listWithLineBreaks()
                        ->bulleted()
                        ->columnSpanFull(),
                ]),

            Section::make('Who it was for')
                ->description('Both references are opaque and both are redacted by the erasure path, which keeps the arithmetic: a merchant\'s recovery rate must not change because a shopper exercised a right.')
                ->columns(2)
                ->schema([
                    TextEntry::make('subject_ref')
                        ->label('Shopper')
                        ->state(fn (Abandonment $record): string => Render::reference($record->subject_ref)),
                    TextEntry::make('contact_ref')
                        ->label('Contact')
                        ->state(fn (Abandonment $record): string => Render::reference($record->contact_ref))
                        ->helperText('An abandonment with no contact reference is the ordinary case for a shopper who left before typing one, and every attempt on it refuses with a reason.'),
                ]),

            Section::make('Where it stands')
                ->columns(3)
                ->schema([
                    TextEntry::make('state')
                        ->label('State')
                        ->badge()
                        ->state(fn (Abandonment $record): string => Render::abandonmentLabel($record->stateAt(Snapshot::asOf())))
                        ->color(fn (Abandonment $record): string => Render::abandonmentColour($record->stateAt(Snapshot::asOf())))
                        ->helperText('Derived, never stored. A second record of a fact the columns already hold is a disagreement waiting to be expressible.'),
                    TextEntry::make('abandoned_at')->label('Abandoned')->dateTime(),
                    TextEntry::make('expires_at')->label('Expires')->dateTime(),
                    TextEntry::make('stop_reason')
                        ->label('Outreach stopped')
                        ->state(fn (Abandonment $record): string => $record->stop_reason === null
                            ? 'Not stopped. Campaigns still reach this abandonment until it expires.'
                            : Render::stopLabel($record->stop_reason))
                        ->columnSpanFull(),
                    TextEntry::make('readiness')
                        ->label('Whether anything can go out')
                        ->state(fn (Abandonment $record): string => self::readiness($record))
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => ListAbandonments::route('/'),
            'view' => ViewAbandonment::route('/{record}'),
        ];
    }

    /**
     * Said on the record, so an operator sees why nothing has gone before an
     * attempt refuses.
     *
     * The amount and the lines are rebuilt lazily by the domain, so both are
     * read through a guard: a malformed row would otherwise raise while a column
     * renders rather than when the query ran.
     */
    public static function amount(Abandonment $record): string
    {
        try {
            return Render::money($record->total());
        } catch (AbandonedCheckoutException) {
            return Render::unreadable('amount');
        }
    }

    public static function items(Abandonment $record): string
    {
        try {
            return (string) $record->itemCount();
        } catch (AbandonedCheckoutException) {
            return Render::NONE;
        }
    }

    /** @return list<string> */
    public static function lines(Abandonment $record): array
    {
        try {
            return Render::lines($record->lineList());
        } catch (AbandonedCheckoutException) {
            return [Render::unreadable('snapshot of what was in the basket')];
        }
    }

    public static function readiness(Abandonment $record): string
    {
        $cannot = [];
        $broken = Snapshot::unresolvable($record->tenant_id);

        foreach (Snapshot::channels($record->tenant_id) as $key => $bound) {
            if (! $bound) {
                $cannot[] = $key.' — '.Render::channel(null, in_array($key, $broken, true));
            }
        }

        $link = Render::link(Snapshot::linkBound());

        return $cannot === []
            ? 'A way back to the basket: '.$link.'. Every channel this merchant\'s campaigns name has something bound to it.'
            : 'A way back to the basket: '.$link.'. Nothing usable is at the end of '.implode('; ', $cannot).'.';
    }

    /** @return array<string, string> */
    public static function stopOptions(): array
    {
        $options = [];

        foreach (StopReason::cases() as $reason) {
            $options[$reason->value] = Render::stopLabel($reason);
        }

        return $options;
    }
}
