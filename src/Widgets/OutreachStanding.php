<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\AbandonedCheckout\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Liberu\Ecommerce\AbandonedCheckout\Enums\RefusalReason;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Support\PanelTenant;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Support\Render;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Support\Snapshot;

/**
 * Why nothing has gone out, on the screen a merchant looks at first.
 *
 * Every end of this pipeline is unattached on a fresh install: nothing carries a
 * message and nothing builds the link it would contain. An empty screen would
 * read as "nothing to do". This one reads as "here is the joint that is not
 * attached", because a refusal with a reason is the truthful answer and it is
 * not an error.
 *
 * A widget is the surface Filament's own tenancy does not reach: `canView()`
 * defaults to true and no panel scope applies to what a widget queries. The
 * host's `TopProductsWidget` raised an unknown-column error for every panel user
 * whose team owned a store, and `grep -rn 'Widgets\\' tests/` across the whole
 * host tree returned nothing, so CI never saw it. Every read here states the
 * tenant, and the suite constructs this widget.
 */
class OutreachStanding extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Whether anything can go out, and what has';

    public static function canView(): bool
    {
        return PanelTenant::resolvable();
    }

    /**
     * What this widget puts on the screen, as data.
     *
     * Public and asserted directly, because the fault this widget is written
     * against is an empty table where a refusal belongs, and an assertion that
     * greps rendered markup for an absent nought proves very little.
     *
     * @return list<array{label: string, value: string, because: string}>
     */
    public function lines(): array
    {
        $tenant = PanelTenant::current();
        $bound = Snapshot::linkBound();

        $lines = [[
            'label' => 'A way back to the basket',
            'value' => $bound === true ? 'Bound' : Render::NONE,
            'because' => Render::link($bound),
        ]];

        $channels = Snapshot::channels($tenant);

        if ($channels === []) {
            $lines[] = [
                'label' => 'Something to carry it',
                'value' => Render::NONE,
                'because' => 'no campaign is defined, so no channel key is named and there is nothing for the host to bind a transport to',
            ];
        }

        $broken = Snapshot::unresolvable($tenant);

        foreach ($channels as $key => $carries) {
            $lines[] = [
                'label' => 'Carried by '.$key,
                'value' => $carries ? 'Bound' : Render::NONE,
                'because' => Render::channel(
                    Snapshot::reporting($tenant)[$key] ?? null,
                    in_array($key, $broken, true),
                ),
            ];
        }

        $lines[] = self::went($tenant);

        $unsettled = Snapshot::unsettled($tenant);

        if ($unsettled > 0) {
            // Neither sent nor refused. The domain reads their silence as though
            // they had gone, and its own enum says it does not know that.
            $lines[] = [
                'label' => 'Claims never settled',
                'value' => (string) $unsettled,
                'because' => 'each of these was claimed before a send and never settled, so whether the message went is not known and their silence says nothing either way — the domain reads it as though it had gone',
            ];
        }

        foreach (Snapshot::refusals($tenant) as $reason => $count) {
            $lines[] = [
                'label' => 'Refused: '.$reason,
                'value' => (string) $count,
                'because' => Render::refusal(RefusalReason::from($reason)),
            ];
        }

        return $lines;
    }

    /** @return array<Stat> */
    protected function getStats(): array
    {
        $stats = [];

        foreach ($this->lines() as $line) {
            $stats[] = Stat::make($line['label'], $line['value'])
                ->description($line['because'])
                ->color($line['value'] === Render::NONE ? 'warning' : 'success');
        }

        return $stats;
    }

    /** @return array{label: string, value: string, because: string} */
    private static function went(string $tenant): array
    {
        $attempts = Snapshot::attemptCount($tenant);

        return [
            'label' => 'Messages that went',
            'value' => (string) Snapshot::sent($tenant),
            'because' => $attempts === 0
                ? 'nothing has been attempted at all, which is a different state from every attempt being refused'
                : $attempts.' attempts have been made, and each one that did not go is counted below with its reason',
        ];
    }
}
