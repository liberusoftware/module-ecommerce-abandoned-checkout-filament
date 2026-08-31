<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\AbandonedCheckout\Filament\Resources\Campaigns;

use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Liberu\Ecommerce\AbandonedCheckout\Data\Condition;
use Liberu\Ecommerce\AbandonedCheckout\Enums\RefusalReason;
use Liberu\Ecommerce\AbandonedCheckout\Exceptions\ConditionIsMalformed;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Concerns\DeniesUnpublishedResourceAbilities;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Resources\Campaigns\Pages\ListCampaigns;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Resources\Campaigns\Pages\ViewCampaign;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Support\PanelTenant;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Support\Render;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Support\Snapshot;
use Liberu\Ecommerce\AbandonedCheckout\Models\Campaign;
use Liberu\Ecommerce\AbandonedCheckout\Policies\CustodyPolicy;
use UnitEnum;

/**
 * When to reach out, through which bound transport, how often at most, and under
 * what conditions.
 *
 * The cap and the quiet period are on the row because the host wrote the same
 * rule twice — once in PHP and once in SQL — with both numbers inline in both
 * and nothing keeping them in agreement.
 */
final class CampaignResource extends Resource
{
    use DeniesUnpublishedResourceAbilities;

    protected static ?string $model = Campaign::class;

    protected static bool $isScopedToTenant = false;

    protected static ?string $modelLabel = 'campaign';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationLabel = 'Campaigns';

    protected static UnitEnum|string|null $navigationGroup = 'Abandoned checkout';

    protected static ?int $navigationSort = 30;

    public static function canViewAny(): bool
    {
        return PanelTenant::resolvable();
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof Campaign
            && PanelTenant::resolvable()
            && CustodyPolicy::ownsCampaign(PanelTenant::current(), $record->id);
    }

    /** @return Builder<Campaign> */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Campaign> $query */
        $query = parent::getEloquentQuery();

        return $query->where('tenant_id', PanelTenant::current())->withCount('attempts');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Campaign')->searchable()->sortable(),
                TextColumn::make('channel_key')
                    ->label('Carried by')
                    ->badge()
                    ->color(fn (Campaign $record): string => self::bound($record) ? 'success' : 'danger')
                    ->tooltip('A key, not an enum: a package that enumerated a host\'s channels could never gain one. Red means nothing is bound to it, so every attempt on this campaign refuses with a reason.')
                    ->searchable(),
                TextColumn::make('cap')
                    ->label('Cap')
                    ->tooltip('Counted by counting attempt rows on this channel, never by incrementing a column. Two workers cannot both write the same count.'),
                TextColumn::make('quiet_minutes')->label('Quiet')->suffix(' min'),
                TextColumn::make('delay_minutes')->label('Delay')->suffix(' min'),
                TextColumn::make('conditions')
                    ->label('Conditions')
                    ->state(fn (Campaign $record): array => self::conditionLines($record))
                    ->listWithLineBreaks()
                    ->bulleted()
                    ->placeholder('Every abandonment matches')
                    ->wrap(),
                TextColumn::make('attempts_count')->label('Attempts'),
            ])
            ->filters([])
            ->defaultSort('name')
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateIcon('heroicon-o-megaphone')
            ->emptyStateHeading('This merchant has defined no campaign')
            ->emptyStateDescription('So nothing decides whether to reach out about anything. The host had a campaigns table with an email subject, an email body and an SMS body on it, and nothing anywhere that read a single one of them.');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('When it reaches out')
                ->description('One implementation of "may this attempt be made" lives in the domain, and every query that pre-filters derives its predicate from the same place.')
                ->columns(3)
                ->schema([
                    TextEntry::make('name')->label('Campaign'),
                    TextEntry::make('cap')
                        ->label('At most')
                        ->suffix(' attempts')
                        ->helperText('Per channel, counted from the attempt rows. A refusal takes no slot, so binding a channel later does not find the cap spent.'),
                    TextEntry::make('quiet_minutes')
                        ->label('Quiet period')
                        ->suffix(' minutes')
                        ->helperText('Since the last attempt that went out on this channel.'),
                    TextEntry::make('delay_minutes')
                        ->label('Delay')
                        ->suffix(' minutes')
                        ->helperText('Since the checkout was abandoned.'),
                    TextEntry::make('channel_key')
                        ->label('Carried by')
                        ->helperText(fn (Campaign $record): string => self::binding($record))
                        ->columnSpan(2),
                ]),

            Section::make('What it reaches out about')
                ->description('A condition names a field the module publishes. The host matched a raw string with a default arm that returned true, so a typo passed every condition it was written into.')
                ->schema([
                    TextEntry::make('conditions')
                        ->hiddenLabel()
                        ->state(fn (Campaign $record): array => self::conditionLines($record))
                        ->listWithLineBreaks()
                        ->bulleted()
                        ->placeholder('No conditions, so every abandonment of this merchant\'s matches.')
                        ->columnSpanFull(),
                ]),

            Section::make('What is due under it right now')
                ->schema([
                    TextEntry::make('due')
                        ->hiddenLabel()
                        ->state(fn (Campaign $record): string => self::due($record))
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => ListCampaigns::route('/'),
            'view' => ViewCampaign::route('/{record}'),
        ];
    }

    /**
     * The conditions as sentences, or the refusal a malformed one causes.
     *
     * `conditionList()` raises where a stored condition names a field or an
     * operator the module does not publish. Rendering the refusal is the same
     * answer an attempt would get, rather than a screen that fatals.
     *
     * @return list<string>
     */
    public static function conditionLines(Campaign $record): array
    {
        try {
            $conditions = $record->conditionList();
        } catch (ConditionIsMalformed) {
            return [Render::refusal(RefusalReason::ConditionIsMalformed)];
        }

        // An amount threshold stays in minor units with its code beside it.
        // `Data\Condition` carries the currency and not the exponent — unlike
        // `Data\Money`, which carries both — so placing a decimal point here
        // would be this package inventing an exponent it was not given.
        return array_map(
            static fn (Condition $condition): string => Render::fieldLabel($condition->field).' '
                .Render::comparisonLabel($condition->operator).' '.$condition->value
                .($condition->currency === null ? '' : ' '.$condition->currency.' (minor units)'),
            $conditions,
        );
    }

    /** Whether anything carries this campaign's messages, said on the record. */
    public static function binding(Campaign $record): string
    {
        return ucfirst(Render::channel(
            Snapshot::reporting($record->tenant_id)[$record->channel_key] ?? null,
            in_array($record->channel_key, Snapshot::unresolvable($record->tenant_id), true),
        )).'.';
    }

    public static function due(Campaign $record): string
    {
        $due = count(Snapshot::dueIds($record->tenant_id));

        return $due === 0
            ? 'Nothing is due under any of this merchant\'s campaigns at the instant this page read the clock. Whether that is because nothing is eligible or because nothing has been recorded is the Abandonments screen\'s answer.'
            : $due.' abandonments are due under this merchant\'s campaigns at the instant this page read the clock. The domain decides what due means; this screen only asks.';
    }

    private static function bound(Campaign $record): bool
    {
        return Snapshot::channels($record->tenant_id)[$record->channel_key] ?? false;
    }
}
