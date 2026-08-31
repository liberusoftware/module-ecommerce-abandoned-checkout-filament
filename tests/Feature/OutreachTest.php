<?php

declare(strict_types=1);

use Liberu\Ecommerce\AbandonedCheckout\Filament\Pages\Outreach;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Tests\Fakes\FakeChannel;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Widgets\DueNow;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Widgets\OutreachStanding;
use Livewire\Livewire;

/*
 * The screen that answers the question the host could not.
 *
 * In the host, no job wrote an abandonment, nothing read the campaign bodies,
 * the recovery URL pointed at a route no file declared, and the page that route
 * would have reached did not exist. Four unattached joints and one appearance:
 * nothing happens. Here each is named separately.
 */

function outreach(): string
{
    return (string) Livewire::test(Outreach::class)->assertOk()->html();
}

it('carries both widgets, which is what the host’s empty recovery screen did not', function (): void {
    expect((new Outreach())->getHeaderWidgets())->toBe([OutreachStanding::class, DueNow::class]);
});

it('says nothing is bound, nothing is recorded and nothing is named, on a fresh install', function (): void {
    $html = outreach();

    expect($html)->toContain('nothing is bound to turn this abandonment into a URL')
        ->and($html)->toContain('No campaign is defined')
        ->and($html)->toContain('Nothing has been recorded as abandoned')
        ->and($html)->toContain('No attempt has been refused')
        ->and($html)->toContain('No channel is bound');
});

it('says of each channel key whether anything carries it', function (): void {
    campaign(name: 'first-nudge', channelKey: 'email');
    campaign(name: 'second-nudge', channelKey: 'sms');
    bindChannel(key: 'email');

    $html = outreach();

    expect($html)->toContain('something is bound to this key and it reports back')
        ->and($html)->toContain('so nobody was asked');
});

it('tells nothing recorded from nothing attempted from something having gone', function (): void {
    // Three different faults with one appearance in the host, which had no path
    // by which a basket became a row, none by which a row became a message, and
    // no link a message could carry.
    record();

    expect(outreach())->toContain('1 abandonments are recorded and no attempt has been made');

    campaign();
    attempt();

    expect(outreach())->toContain('1 attempts have been made, and 0 messages went out');
});

it('names every refusal by reason, with a sentence saying which joint said no', function (): void {
    record();
    campaign();
    attempt();

    expect(outreach())->toContain('1 × no_linker_bound');
});

it('names a claim that was never settled as neither sent nor refused', function (): void {
    // The domain reads a Pending attempt's silence as though the message had
    // gone. The standing names it separately rather than counting it as either.
    $abandonment = record();
    $campaign = campaign();
    pendingAttempt($abandonment, $campaign);

    expect(outreach())->toContain('were claimed and never settled');
});

it('says a bound channel that reports nothing back makes silence unreadable', function (): void {
    campaign();
    bindChannel(new FakeChannel(reports: false));

    expect(outreach())->toContain('email is bound but reports nothing back');

    bindChannel();

    expect(outreach())->toContain('Every bound channel reports back');
});

it('says both seams are attached once a host has bound them', function (): void {
    campaign();
    bindEverything();

    expect(outreach())->toContain('a linker is bound');
});
