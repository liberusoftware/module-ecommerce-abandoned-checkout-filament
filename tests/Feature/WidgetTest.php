<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Liberu\Ecommerce\AbandonedCheckout\Enums\RefusalReason;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Support\PanelTenant;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Support\Render;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Support\Snapshot;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Tests\Fakes\FakeChannel;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Tests\TestTenant;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Widgets\DueNow;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Widgets\OutreachStanding;
use Liberu\Ecommerce\AbandonedCheckout\Models\Abandonment;
use Livewire\Livewire;

/*
 * The suite the host did not have.
 *
 * `grep -rn 'Widgets\\' tests/` returned nothing across the whole host tree, so
 * `TopProductsWidget` — which selected from a derived table with no `store_id`
 * in it and raised an unknown-column error for every panel user whose team owned
 * a store — was unreachable from CI. A widget is also the one surface Filament's
 * own tenancy does not reach: `canView()` defaults to true and no panel scope
 * applies to what a widget queries.
 *
 * Every test here constructs a widget.
 */

it('mounts both widgets and renders them', function (): void {
    record();
    campaign();

    Livewire::test(OutreachStanding::class)->assertOk();
    Livewire::test(DueNow::class)->assertOk();
});

it('names both unbound seams on a fresh install rather than showing an empty screen', function (): void {
    // The whole point. Nothing carries a message and nothing builds the link it
    // would contain, and the honest reading of that is not "nothing to do".
    expect(Livewire::test(OutreachStanding::class)->instance()->lines())->toBe([
        [
            'label' => 'A way back to the basket',
            'value' => Render::NONE,
            'because' => Render::refusal(RefusalReason::NoLinkerBound),
        ],
        [
            'label' => 'Something to carry it',
            'value' => Render::NONE,
            'because' => 'no campaign is defined, so no channel key is named and there is nothing for the host to bind a transport to',
        ],
        [
            'label' => 'Messages that went',
            'value' => '0',
            'because' => 'nothing has been attempted at all, which is a different state from every attempt being refused',
        ],
    ]);
});

it('names the channel key a campaign asks for and says nothing is bound to it', function (): void {
    campaign(channelKey: 'email');

    $lines = Livewire::test(OutreachStanding::class)->instance()->lines();

    expect($lines[1])->toBe([
        'label' => 'Carried by email',
        'value' => Render::NONE,
        'because' => Render::refusal(RefusalReason::NoChannelBound),
    ]);
});

it('says a bound channel that reports nothing back leaves silence unreadable', function (): void {
    campaign();
    bindChannel(new FakeChannel(reports: false));

    expect(Livewire::test(OutreachStanding::class)->instance()->lines()[1])->toBe([
        'label' => 'Carried by email',
        'value' => 'Bound',
        'because' => 'something is bound to this key, but it reports nothing back, so nothing heard afterwards means there is no way to know rather than no',
    ]);

    bindChannel();
    Snapshot::forget();

    expect(Livewire::test(OutreachStanding::class)->instance()->lines()[1]['because'])
        ->toBe('something is bound to this key and it reports back, so silence after a message is readable');
});

it('counts each refusal by its reason, so nothing having gone has a cause on the screen', function (): void {
    record();
    campaign();

    // Nothing bound at all: the linker is asked for first, so this is the
    // refusal a fresh install actually produces.
    attempt();

    $lines = Livewire::test(OutreachStanding::class)->instance()->lines();

    expect($lines[2])->toBe([
        'label' => 'Messages that went',
        'value' => '0',
        'because' => '1 attempts have been made, and each one that did not go is counted below with its reason',
    ])->and($lines[3])->toBe([
        'label' => 'Refused: no_linker_bound',
        'value' => '1',
        'because' => Render::refusal(RefusalReason::NoLinkerBound),
    ]);
});

it('moves the refusal on to the channel once the linker is bound and nothing else is', function (): void {
    record();
    campaign();
    bindLinker();

    attempt();

    expect(Livewire::test(OutreachStanding::class)->instance()->lines()[3])->toBe([
        'label' => 'Refused: no_channel_bound',
        'value' => '1',
        'because' => Render::refusal(RefusalReason::NoChannelBound),
    ]);
});

it('counts a message that went, once both ends are attached', function (): void {
    record();
    campaign();
    bindEverything();

    expect(attempt()->wentOut())->toBeTrue();

    $lines = Livewire::test(OutreachStanding::class)->instance()->lines();

    expect($lines[2]['value'])->toBe('1')
        ->and($lines)->toHaveCount(3);
});

it('names a claim that was never settled as neither sent nor refused', function (): void {
    // `ListAttempts::silence()` branches on Refused alone, so it reads a Pending
    // attempt as though the message had gone. Counting it as sent would say the
    // same thing with a number.
    $abandonment = record();
    $campaign = campaign();
    bindEverything();

    pendingAttempt($abandonment, $campaign);

    $lines = Livewire::test(OutreachStanding::class)->instance()->lines();

    expect($lines[2]['value'])->toBe('0')
        ->and($lines[3])->toBe([
            'label' => 'Claims never settled',
            'value' => '1',
            'because' => 'each of these was claimed before a send and never settled, so whether the message went is not known — and the attempt list reads their silence as though it had',
        ]);
});

it('calls a linker the container cannot build a misconfiguration, not an unbound seam', function (): void {
    // `Seams::link()` raises on a class name that does not resolve rather than
    // refusing, so without a guard a typo in the host's config is a 500 on every
    // screen here rather than a fact on one.
    Config::set('abandoned_checkout.link', 'Nobody\\Implements\\This');
    Snapshot::forget();

    expect(Snapshot::linkBound())->toBeNull()
        ->and(Livewire::test(OutreachStanding::class)->instance()->lines()[0])->toBe([
            'label' => 'A way back to the basket',
            'value' => Render::NONE,
            'because' => Render::link(null),
        ]);
});

it('reads one merchant’s standing when both merchants ran the identical campaign', function (): void {
    // Two merchants both calling a campaign `first-nudge` is the ordinary case,
    // and a widget query missing its tenant reads both.
    foreach ([TestTenant::PRIMARY, TestTenant::OTHER] as $tenant) {
        record(tenantId: $tenant);
        campaign(tenantId: $tenant);
    }

    attempt(tenantId: TestTenant::OTHER);

    expect(Livewire::test(OutreachStanding::class)->instance()->lines())->toHaveCount(3);

    TestTenant::use(TestTenant::OTHER);
    Snapshot::forget();

    expect(Livewire::test(OutreachStanding::class)->instance()->lines()[3]['value'])->toBe('1');
});

it('lists only this merchant’s due abandonments, and gets the right non-zero number', function (): void {
    foreach ([TestTenant::PRIMARY, TestTenant::OTHER] as $tenant) {
        record(tenantId: $tenant);
        campaign(tenantId: $tenant);
    }

    $widget = Livewire::test(DueNow::class);
    $mine = Abandonment::query()->where('tenant_id', TestTenant::PRIMARY)->pluck('id')->all();

    expect($widget->instance()->dueIds())->toBe($mine)
        ->and($mine)->toHaveCount(1);

    $widget->assertCanSeeTableRecords(Abandonment::query()->whereIn('id', $mine)->get())
        ->assertCanNotSeeTableRecords(
            Abandonment::query()->where('tenant_id', TestTenant::OTHER)->get()
        );
});

it('shows nothing due where the domain says nothing is eligible', function (): void {
    // Recorded, and the campaign's delay has not elapsed. Not the same fact as
    // nothing being recorded, and the empty state says so.
    record(abandonedAt: '2026-08-31 11:59:00');
    campaign(delayMinutes: 60);

    expect(Livewire::test(DueNow::class)->instance()->dueIds())->toBe([]);
});

it('is due under whichever campaign says so, counted once across all of them', function (): void {
    record();
    campaign(name: 'first-nudge');
    campaign(name: 'second-nudge');

    expect(Livewire::test(DueNow::class)->instance()->dueIds())->toHaveCount(1);
});

it('refuses to render at all when the panel has no merchant', function (): void {
    // `canView()` is true on every Filament widget by default, and a widget
    // carries no panel scope: this is the override that closes it.
    PanelTenant::resolveUsing(fn (): ?string => null);

    expect(OutreachStanding::canView())->toBeFalse()
        ->and(DueNow::canView())->toBeFalse();

    PanelTenant::resolveUsing(fn (): string => TestTenant::current());

    expect(OutreachStanding::canView())->toBeTrue()
        ->and(DueNow::canView())->toBeTrue();
});

it('reads the domain once for the whole page rather than once per widget', function (): void {
    record();
    campaign();
    attempt();

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $first = Snapshot::refusals(TestTenant::PRIMARY);
    $after = $queries;
    $second = Snapshot::refusals(TestTenant::PRIMARY);

    expect($after)->toBeGreaterThan(0)
        ->and($queries)->toBe($after)
        ->and($second)->toBe($first);
});

it('reads one clock for the whole request, so two widgets cannot disagree about a delay', function (): void {
    // No action in the domain calls now(): every one takes the instant it is
    // reasoning about. This is where that instant comes from, once.
    $first = Snapshot::asOf();

    Carbon::setTestNow(Carbon::now()->addHours(3));

    expect(Snapshot::asOf())->toEqual($first);

    Snapshot::forget();

    expect(Snapshot::asOf())->not->toEqual($first);
});
