<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Liberu\Ecommerce\AbandonedCheckout\Actions\AttemptRecovery;
use Liberu\Ecommerce\AbandonedCheckout\Actions\DefineCampaign;
use Liberu\Ecommerce\AbandonedCheckout\Actions\RecordAbandonment;
use Liberu\Ecommerce\AbandonedCheckout\Data\AttemptReport;
use Liberu\Ecommerce\AbandonedCheckout\Data\Condition;
use Liberu\Ecommerce\AbandonedCheckout\Data\Line;
use Liberu\Ecommerce\AbandonedCheckout\Data\Money;
use Liberu\Ecommerce\AbandonedCheckout\Enums\AttemptState;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Support\PanelTenant;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Support\Snapshot;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Tests\Fakes\FakeChannel;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Tests\Fakes\FakeLinker;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Tests\TestCase;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Tests\TestTenant;
use Liberu\Ecommerce\AbandonedCheckout\Models\Abandonment;
use Liberu\Ecommerce\AbandonedCheckout\Models\Attempt;
use Liberu\Ecommerce\AbandonedCheckout\Models\Campaign;

uses(TestCase::class, RefreshDatabase::class)
    ->beforeEach(function (): void {
        TestTenant::reset();
        PanelTenant::resolveUsing(fn (): string => TestTenant::current());

        // Load-bearing. Both seams are unbound as the domain ships them, and
        // that unbound state is the behaviour most of this suite claims
        // something about: a test inheriting a binding from the one before it
        // would prove the opposite of what it says.
        Config::set('abandoned_checkout.channels', []);
        Config::set('abandoned_checkout.link', null);

        // The snapshot is one read per request, and a test is not a request.
        Snapshot::forget();

        Carbon::setTestNow(Carbon::parse('2026-08-31 12:00:00'));
    })
    ->in(__DIR__.'/Feature');

// The rendering rules need no database and no panel.
uses(TestCase::class)->in(__DIR__.'/Unit');

function at(string $expression = '2026-08-31 12:00:00'): CarbonImmutable
{
    return CarbonImmutable::parse($expression);
}

function money(int $minor = 5000, string $currency = 'GBP', int $exponent = 2): Money
{
    return new Money($minor, $currency, $exponent);
}

function line(int $quantity = 2, int $unitMinor = 2500, string $currency = 'GBP'): Line
{
    return new Line('product-1', 'A Kettle', 'KET-1', $quantity, new Money($unitMinor, $currency, 2));
}

/** @param  list<Line>|null  $lines */
function record(
    string $tenantId = TestTenant::PRIMARY,
    string $checkoutRef = 'checkout-1',
    ?string $subjectRef = 'person-1',
    ?string $contactRef = 'mailbox-1',
    ?Money $total = null,
    ?array $lines = null,
    string $abandonedAt = '2026-08-31 10:00:00',
    string $expiresAt = '2026-09-07 10:00:00',
): Abandonment {
    $abandonment = (new RecordAbandonment())(
        $tenantId,
        $checkoutRef,
        $subjectRef,
        $contactRef,
        $total ?? money(),
        $lines ?? [line()],
        at($abandonedAt),
        at($expiresAt),
    );

    Snapshot::forget();

    return $abandonment;
}

/** @param  list<Condition>  $conditions */
function campaign(
    string $tenantId = TestTenant::PRIMARY,
    string $name = 'first-nudge',
    string $channelKey = 'email',
    int $cap = 3,
    int $quietMinutes = 60,
    int $delayMinutes = 60,
    array $conditions = [],
): Campaign {
    $campaign = (new DefineCampaign())($tenantId, $name, $channelKey, $cap, $quietMinutes, $delayMinutes, $conditions);

    Snapshot::forget();

    return $campaign;
}

function attempt(
    string $tenantId = TestTenant::PRIMARY,
    string $checkoutRef = 'checkout-1',
    ?int $campaignId = null,
    string $at = '2026-08-31 12:00:00',
): AttemptReport {
    $campaignId ??= Campaign::query()->where('tenant_id', $tenantId)->orderBy('id')->firstOrFail()->id;

    $report = (new AttemptRecovery())($tenantId, $checkoutRef, $campaignId, at($at));

    Snapshot::forget();

    return $report;
}

/**
 * A claim written before the send and never settled, which is what an
 * `AttemptRecovery` that died between the two leaves behind.
 */
function pendingAttempt(Abandonment $abandonment, Campaign $campaign): Attempt
{
    $attempt = Attempt::query()->create([
        'tenant_id' => $abandonment->tenant_id,
        'abandonment_id' => $abandonment->id,
        'campaign_id' => $campaign->id,
        'channel_key' => $campaign->channel_key,
        'sequence' => 1,
        'state' => AttemptState::Pending,
        'attempted_at' => at(),
    ]);

    Snapshot::forget();

    return $attempt;
}

function bindChannel(?FakeChannel $channel = null, string $key = 'email'): FakeChannel
{
    $channel ??= new FakeChannel();
    Config::set('abandoned_checkout.channels', array_merge((array) Config::get('abandoned_checkout.channels'), [$key => $channel]));
    Snapshot::forget();

    return $channel;
}

function bindLinker(?FakeLinker $link = null): FakeLinker
{
    $link ??= new FakeLinker();
    Config::set('abandoned_checkout.link', $link);
    Snapshot::forget();

    return $link;
}

/** Both seams bound, which is the state a host reaches only after wiring two things the module cannot wire for it. */
function bindEverything(): void
{
    bindLinker();
    bindChannel();
}

/**
 * The notifications the last request sent, as title, body and colour.
 *
 * Filament's own `assertNotified()` compares the whole serialised notification,
 * so it fails on an icon this suite has no opinion about. Reading them consumes
 * them, because mounting the component drains the session.
 *
 * @return array{title: ?string, body: ?string, color: mixed}
 */
function lastNotification(): array
{
    $component = new Notifications();
    $component->mount();

    $sent = $component->notifications
        ->map(fn (Notification $notification): array => [
            'title' => $notification->getTitle(),
            'body' => $notification->getBody(),
            'color' => $notification->getColor(),
        ])
        ->values()
        ->all();

    return $sent === [] ? ['title' => null, 'body' => null, 'color' => null] : $sent[count($sent) - 1];
}
