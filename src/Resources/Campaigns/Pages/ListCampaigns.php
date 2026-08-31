<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\AbandonedCheckout\Filament\Resources\Campaigns\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\App;
use Liberu\Ecommerce\AbandonedCheckout\Actions\DefineCampaign;
use Liberu\Ecommerce\AbandonedCheckout\Data\Condition;
use Liberu\Ecommerce\AbandonedCheckout\Enums\Comparison;
use Liberu\Ecommerce\AbandonedCheckout\Enums\ConditionField;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Resources\Campaigns\CampaignResource;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Support\Apply;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Support\PanelTenant;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Support\Render;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Support\Snapshot;

/**
 * Defining a campaign is a domain action, not a create form. Naming one that
 * already exists redefines it, which is why there is no edit anywhere.
 *
 * Every condition field and every operator is a Select over a domain enum, so a
 * field the module does not publish cannot be typed here at all — the host
 * matched a raw string and returned true on the default arm, which meant a typo
 * passed every condition it was written into.
 */
final class ListCampaigns extends ListRecords
{
    protected static string $resource = CampaignResource::class;

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('define')
                ->label('Define a campaign')
                ->icon('heroicon-o-plus')
                ->modalHeading('Say when to reach out, and how often at most')
                ->modalDescription('This module carries no mail and no SMS. The channel key names a transport the host binds; with nothing bound, every attempt under this campaign is recorded as refused with a reason rather than quietly not made.')
                ->modalSubmitActionLabel('Define it')
                ->schema([
                    TextInput::make('name')
                        ->label('Name')
                        ->required()
                        ->maxLength(128)
                        ->helperText('Naming one that already exists redefines it. There is no edit form, here or anywhere in this package.'),
                    TextInput::make('channel_key')
                        ->label('Channel key')
                        ->required()
                        ->maxLength(32)
                        ->helperText('An opaque key the host binds to a transport. Email and SMS are different keys and both are capped, which the host\'s single email counter was not.'),
                    TextInput::make('cap')
                        ->label('At most this many attempts')
                        ->required()
                        ->numeric()
                        ->default('3')
                        ->minValue(1)
                        ->maxValue(50),
                    TextInput::make('quiet_minutes')
                        ->label('Quiet period in minutes')
                        ->required()
                        ->numeric()
                        ->default('60')
                        ->minValue(0),
                    TextInput::make('delay_minutes')
                        ->label('Delay in minutes')
                        ->required()
                        ->numeric()
                        ->default('60')
                        ->minValue(0),
                    Repeater::make('conditions')
                        ->label('Only where')
                        ->default([])
                        ->addActionLabel('Add a condition')
                        ->helperText('Every abandonment matches when there are none. An amount is compared in minor units against a named currency, and an abandonment in another currency does not match rather than being compared across currencies.')
                        ->schema([
                            Select::make('field')
                                ->label('Field')
                                ->required()
                                ->live()
                                ->options(fn (): array => self::fieldOptions()),
                            Select::make('operator')
                                ->label('Is')
                                ->required()
                                ->options(fn (): array => self::operatorOptions()),
                            TextInput::make('value')
                                ->label('Value')
                                ->required()
                                ->numeric(),
                            TextInput::make('currency')
                                ->label('Currency')
                                ->maxLength(3)
                                ->minLength(3)
                                ->required(fn (Get $get): bool => $get('field') === ConditionField::CartValueMinor->value)
                                ->visible(fn (Get $get): bool => $get('field') === ConditionField::CartValueMinor->value)
                                ->helperText('An ISO 4217 code. Only an amount carries one.'),
                        ]),
                ])
                ->action(function (array $data): void {
                    /** @var array{name: string, channel_key: string, cap: string, quiet_minutes: string, delay_minutes: string, conditions: list<array{field: string, operator: string, value: string, currency?: ?string}>} $data */
                    $campaign = App::make(DefineCampaign::class)(
                        PanelTenant::current(),
                        $data['name'],
                        $data['channel_key'],
                        (int) $data['cap'],
                        (int) $data['quiet_minutes'],
                        (int) $data['delay_minutes'],
                        self::conditions($data['conditions']),
                    );

                    Snapshot::forget();

                    Apply::say(
                        'Defined',
                        $campaign->name.' will reach out through '.$campaign->channel_key.', at most '
                            .$campaign->cap.' times. '.CampaignResource::binding($campaign),
                        'success',
                    );
                }),
        ];
    }

    /** @return array<string, string> */
    public static function fieldOptions(): array
    {
        $options = [];

        foreach (ConditionField::cases() as $field) {
            $options[$field->value] = Render::fieldLabel($field);
        }

        return $options;
    }

    /** @return array<string, string> */
    public static function operatorOptions(): array
    {
        $options = [];

        foreach (Comparison::cases() as $operator) {
            $options[$operator->value] = Render::comparisonLabel($operator);
        }

        return $options;
    }

    /**
     * @param  list<array{field: string, operator: string, value: string, currency?: ?string}>  $rows
     * @return list<Condition>
     */
    private static function conditions(array $rows): array
    {
        $conditions = [];

        foreach ($rows as $row) {
            $field = ConditionField::from($row['field']);

            $conditions[] = new Condition(
                $field,
                Comparison::from($row['operator']),
                (int) $row['value'],
                $field->needsCurrency() ? strtoupper((string) ($row['currency'] ?? '')) : null,
            );
        }

        return $conditions;
    }
}
