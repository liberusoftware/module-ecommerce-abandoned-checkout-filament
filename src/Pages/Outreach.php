<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\AbandonedCheckout\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Schema;
use Liberu\Ecommerce\AbandonedCheckout\Enums\RefusalReason;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Support\PanelTenant;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Support\Render;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Support\Snapshot;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Widgets\DueNow;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Widgets\OutreachStanding;
use UnitEnum;

/**
 * The screen that answers the question the host could not.
 *
 * In the host, no job wrote an abandonment, nothing read the campaign bodies,
 * the recovery URL pointed at a route no file declared, and the page that route
 * would have reached did not exist. Four unattached joints, and one appearance:
 * nothing happens. Here each joint is a seam that refuses with a reason, and
 * this page names which of them is not attached.
 */
final class Outreach extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-lifebuoy';

    protected static ?string $navigationLabel = 'Outreach';

    protected static UnitEnum|string|null $navigationGroup = 'Abandoned checkout';

    protected static ?int $navigationSort = 10;

    protected static ?string $title = 'Has anything actually gone out?';

    public static function canAccess(): bool
    {
        return PanelTenant::resolvable();
    }

    /** @return array<class-string> */
    public function getHeaderWidgets(): array
    {
        return [
            OutreachStanding::class,
            DueNow::class,
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('What is bound')
                ->description('This package carries no mail and no SMS, and it cannot know a host\'s routes. Both ends are seams, both are unbound as the module ships, and an unbound seam refuses with a reason rather than failing silently.')
                ->schema([
                    Text::make(fn (): string => $this->linkLine()),
                    UnorderedList::make(fn (): array => $this->channelLines()),
                ]),

            Section::make('What has been recorded, and what has been tried')
                ->description('This module owns no sweeper. Whoever owns the checkout decides one has been abandoned and calls RecordAbandonment; the host had three tables for this idea and nothing anywhere that wrote a row.')
                ->schema([
                    Text::make(fn (): string => $this->recordLine()),
                    UnorderedList::make(fn (): array => $this->refusalLines()),
                ]),

            Section::make('What came back')
                ->description('Not yet, never will, and no way to know are three different answers to "did reaching out work", and a null is none of them.')
                ->schema([
                    Text::make(fn (): string => $this->heardLine()),
                ]),
        ]);
    }

    private function linkLine(): string
    {
        return 'A way back to the basket — '.Render::link(Snapshot::linkBound()).'.';
    }

    /** @return list<string> */
    private function channelLines(): array
    {
        $channels = Snapshot::channels(PanelTenant::current());

        if ($channels === []) {
            return ['No campaign is defined, so no channel key is named and there is nothing for the host to bind a transport to.'];
        }

        $lines = [];
        $broken = Snapshot::unresolvable(PanelTenant::current());

        foreach ($channels as $key => $bound) {
            $lines[] = $key.' — '.Render::channel(
                Snapshot::reporting(PanelTenant::current())[$key] ?? null,
                in_array($key, $broken, true),
            ).'.';
        }

        return $lines;
    }

    private function recordLine(): string
    {
        $tenant = PanelTenant::current();
        $recorded = Snapshot::abandonmentCount($tenant);

        if ($recorded === 0) {
            return 'Nothing has been recorded as abandoned. Bind whatever owns the checkout to RecordAbandonment; until something calls it there is nothing here to reach out about, which is a different state from having nothing to say.';
        }

        $attempts = Snapshot::attemptCount($tenant);

        if ($attempts === 0) {
            return $recorded.' abandonments are recorded and no attempt has been made on any of them. Nothing has failed; nothing has been asked.';
        }

        return $recorded.' abandonments are recorded, '.$attempts.' attempts have been made, and '
            .Snapshot::sent($tenant).' messages went out.';
    }

    /** @return list<string> */
    private function refusalLines(): array
    {
        $tenant = PanelTenant::current();
        $lines = [];

        foreach (Snapshot::refusals($tenant) as $reason => $count) {
            $lines[] = $count.' × '.$reason.' — '.Render::refusal(RefusalReason::from($reason)).'.';
        }

        if ($lines === []) {
            $lines[] = 'No attempt has been refused.';
        }

        $unsettled = Snapshot::unsettled($tenant);

        if ($unsettled > 0) {
            // Neither sent nor refused, and read by the domain as though it had
            // gone. Named on its own rather than counted as either.
            $lines[] = $unsettled.' attempts were claimed and never settled, so they are neither sent nor refused and whether they went is not known.';
        }

        return $lines;
    }

    private function heardLine(): string
    {
        $reporting = Snapshot::reporting(PanelTenant::current());

        if ($reporting === []) {
            return 'No channel is bound, so nothing has been carried and there is nothing to have heard about.';
        }

        $silent = [];

        foreach ($reporting as $key => $reports) {
            if (! $reports) {
                $silent[] = $key;
            }
        }

        return $silent === []
            ? 'Every bound channel reports back, so silence after a message means not yet — until the abandonment expires, after which it means never.'
            : implode(', ', $silent).' is bound but reports nothing back, so silence after anything it carried means there is no way to know. That is the honest answer and it is not the same as no.';
    }
}
