<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\AbandonedCheckout\Filament\Resources\Abandonments\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Liberu\Ecommerce\AbandonedCheckout\Enums\AttemptState;
use Liberu\Ecommerce\AbandonedCheckout\Enums\OutcomeKind;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Concerns\DeniesUnpublishedRelationAbilities;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Support\Render;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Support\Snapshot;
use Liberu\Ecommerce\AbandonedCheckout\Models\Attempt;

/**
 * Every attempt made on this abandonment, the refused ones included.
 *
 * This is the screen the module exists for. An empty table would read as
 * "nothing to do"; a table of refusals with their reasons reads as "here is the
 * joint that is not attached". And where nothing came back, the row says which
 * of the three kinds of nothing it is — not yet, never will, or no way to know —
 * because a merchant asking whether reaching out worked cannot act on a null
 * that means all three.
 */
final class AttemptsRelationManager extends RelationManager
{
    use DeniesUnpublishedRelationAbilities;

    protected static string $relationship = 'attempts';

    protected static ?string $title = 'Attempts, and what came of them';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('state')
                    ->label('Attempt')
                    ->badge()
                    ->formatStateUsing(fn (AttemptState $state): string => Render::attemptLabel($state))
                    ->color(fn (AttemptState $state): string => Render::attemptColour($state))
                    ->sortable(),
                TextColumn::make('channel_key')
                    ->label('Through')
                    ->tooltip('Copied from the campaign at the time. Editing a campaign\'s channel must not rewrite where a past message went.'),
                TextColumn::make('attempted_at')->label('Attempted')->dateTime()->sortable(),
                TextColumn::make('refusal_reason')
                    ->label('Refused because')
                    ->state(fn (Attempt $record): ?string => $record->refusal_reason === null
                        ? null
                        : Render::refusal($record->refusal_reason))
                    ->placeholder(Render::NONE)
                    ->wrap(),
                TextColumn::make('observed')
                    ->label('Observed')
                    ->state(fn (Attempt $record): array => self::observed($record))
                    ->listWithLineBreaks()
                    ->bulleted()
                    ->placeholder(Render::NONE),
                TextColumn::make('heard')
                    ->label('Nothing heard')
                    ->state(fn (Attempt $record): ?string => self::heard($record))
                    ->placeholder(Render::NONE)
                    ->wrap()
                    ->tooltip('Not yet, never will, and no way to know are three different answers, and a null is none of them.'),
                TextColumn::make('purchase_ref')
                    ->label('They bought')
                    ->state(fn (Attempt $record): ?string => self::purchase($record))
                    ->placeholder(Render::NONE)
                    ->tooltip('An opaque reference this module never resolves, joins or constrains. The host kept two of these on two tables with nothing holding them in agreement.'),
                TextColumn::make('provider_ref')
                    ->label('Channel reference')
                    ->placeholder(Render::NONE)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([])
            ->defaultSort('attempted_at')
            ->recordUrl(null)
            ->recordAction(null)
            ->recordActions([])
            ->headerActions([])
            ->toolbarActions([])
            ->emptyStateHeading('No attempt has been made on this abandonment')
            ->emptyStateDescription('Which is a different fact from an attempt that did not go: this module records a refusal as a row with a reason on it, so an empty table here means nobody has asked, not that asking failed silently.');
    }

    /**
     * What was observed about one attempt, as labels.
     *
     * @return list<string>
     */
    private static function observed(Attempt $record): array
    {
        return array_map(
            static fn (OutcomeKind $kind): string => Render::outcomeLabel($kind),
            // `??` already answers for an attempt the query describes none of,
            // which is the same arm as one it observed nothing about.
            Snapshot::description($record->tenant_id, $record->abandonment_id, $record->id)->observed ?? [],
        );
    }

    /**
     * Which kind of nothing, where nothing was observed.
     *
     * A claim that was never settled answers "no way to know", whatever the
     * domain returned: `ListAttempts::silence()` branches on `Refused` alone, so
     * it reads a `Pending` attempt as though the message had gone, which is the
     * one thing `AttemptState::Pending` exists to say the module does not know.
     * All three surfaces compensate identically.
     */
    private static function heard(Attempt $record): ?string
    {
        $silence = Snapshot::description($record->tenant_id, $record->abandonment_id, $record->id)?->silence;

        if ($silence === null) {
            return null;
        }

        return $record->state === AttemptState::Pending
            ? Render::unsettled()
            : Render::silence($silence);
    }

    private static function purchase(Attempt $record): ?string
    {
        return Snapshot::description($record->tenant_id, $record->abandonment_id, $record->id)?->purchaseRef;
    }
}
