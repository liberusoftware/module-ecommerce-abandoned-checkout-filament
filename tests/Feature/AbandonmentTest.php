<?php

declare(strict_types=1);

use Liberu\Ecommerce\AbandonedCheckout\Actions\RecordOutcome;
use Liberu\Ecommerce\AbandonedCheckout\Enums\OutcomeKind;
use Liberu\Ecommerce\AbandonedCheckout\Enums\RefusalReason;
use Liberu\Ecommerce\AbandonedCheckout\Enums\Silence;
use Liberu\Ecommerce\AbandonedCheckout\Enums\StopReason;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Resources\Abandonments\AbandonmentResource;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Resources\Abandonments\Pages\ListAbandonments;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Resources\Abandonments\Pages\ViewAbandonment;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Resources\Abandonments\RelationManagers\AttemptsRelationManager;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Support\Render;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Support\Snapshot;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Tests\Fakes\FakeChannel;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Tests\TestTenant;
use Liberu\Ecommerce\AbandonedCheckout\Models\Abandonment;
use Liberu\Ecommerce\AbandonedCheckout\Models\Attempt;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/** The attempts on one abandonment, as the relation manager draws them. */
function attemptsTable(Abandonment $abandonment): Testable
{
    return Livewire::test(AttemptsRelationManager::class, [
        'ownerRecord' => $abandonment,
        'pageClass' => ViewAbandonment::class,
    ]);
}

it('lists this merchant’s abandonments with the state derived from one clock', function (): void {
    $open = record(checkoutRef: 'checkout-open');
    $expired = record(checkoutRef: 'checkout-gone', abandonedAt: '2026-08-20 10:00:00', expiresAt: '2026-08-25 10:00:00');

    Livewire::test(ListAbandonments::class)
        ->assertOk()
        ->assertCanSeeTableRecords(Abandonment::query()->orderBy('id')->get())
        ->assertTableColumnStateSet('state', Render::abandonmentLabel($open->stateAt(Snapshot::asOf())), $open)
        ->assertTableColumnStateSet('state', Render::abandonmentLabel($expired->stateAt(Snapshot::asOf())), $expired);
});

it('offers no filter and no sort on a state the database cannot index', function (): void {
    // The state an abandonment has reached is derived from its dated facts and
    // is not a column. A sort or a filter on it would be a control that either
    // does nothing or asks the database for something that is not there. The
    // stored half — the stop reason — is the one that carries both.
    $table = Livewire::test(ListAbandonments::class)->instance()->getTable();

    $sortable = [];

    foreach ($table->getColumns() as $column) {
        if ($column->isSortable()) {
            $sortable[] = (string) $column->getName();
        }
    }

    expect($sortable)->toBe(['abandoned_at', 'expires_at', 'stop_reason'])
        ->and(array_keys($table->getFilters()))->toBe(['stop_reason']);
});

it('puts no contact and no subject reference on the listing', function (): void {
    // Wave 11 shipped reviewer PII on a public listing. Both references are on
    // the record and neither is on the index.
    $names = array_map(
        fn (object $column): string => (string) $column->getName(),
        Livewire::test(ListAbandonments::class)->instance()->getTable()->getColumns(),
    );

    expect($names)->not->toContain('contact_ref')
        ->and($names)->not->toContain('subject_ref')
        ->and($names)->toContain('checkout_ref');
});

it('renders the snapshot and both redactable references on the record', function (): void {
    $abandonment = record();

    Livewire::test(ViewAbandonment::class, ['record' => $abandonment->getKey()])
        ->assertOk()
        ->assertSee('25.00 GBP')
        ->assertSee('A Kettle');
});

it('says which seam is unbound before an attempt is even made', function (): void {
    $abandonment = record();
    campaign(channelKey: 'email');

    expect(AbandonmentResource::readiness($abandonment))
        ->toContain('nothing is bound to turn this abandonment into a URL')
        ->and(AbandonmentResource::readiness($abandonment))->toContain('Nothing usable is at the end of email');

    bindEverything();

    expect(AbandonmentResource::readiness($abandonment->fresh() ?? $abandonment))
        ->toContain('a linker is bound')
        ->and(AbandonmentResource::readiness($abandonment))->toContain('Every channel');
});

it('records a refusal with its reason when the panel asks and nothing is bound', function (): void {
    // The decision the whole surface hangs on. Nothing is bound, so nothing was
    // sent — and what the operator reads is why, not an empty table.
    $abandonment = record();
    $campaign = campaign();

    Livewire::test(ViewAbandonment::class, ['record' => $abandonment->getKey()])
        ->callAction('attempt', ['campaign' => (string) $campaign->id]);

    $notification = lastNotification();

    expect($notification['title'])->toBe('It did not go, and here is why')
        ->and($notification['body'])->toContain(Render::refusal(RefusalReason::NoLinkerBound))
        ->and($notification['color'])->toBe('warning')
        ->and(Attempt::query()->where('tenant_id', TestTenant::PRIMARY)->count())->toBe(1);
});

it('says the message went, and names what the channel answered with', function (): void {
    $abandonment = record();
    $campaign = campaign();
    bindEverything();

    Livewire::test(ViewAbandonment::class, ['record' => $abandonment->getKey()])
        ->callAction('attempt', ['campaign' => (string) $campaign->id]);

    $notification = lastNotification();

    expect($notification['title'])->toBe('It went')
        ->and($notification['body'])->toContain('provider-message-1');
});

it('names the campaign and whether anything carries it, in the one place a campaign is chosen', function (): void {
    $campaign = campaign(name: 'first-nudge', channelKey: 'email');

    expect(ViewAbandonment::campaignOptions())->toBe([$campaign->id => 'first-nudge — email, nothing bound']);

    bindChannel();

    expect(ViewAbandonment::campaignOptions())->toBe([$campaign->id => 'first-nudge — email, bound']);
});

it('stops outreach, and refuses a second stop that would overwrite why', function (): void {
    // No `visible()` guard on the action, so the domain's own already-stopped
    // branch is reachable through the panel. A guard would make it unreachable
    // and would be the panel restating a rule the domain owns.
    $abandonment = record();

    Livewire::test(ViewAbandonment::class, ['record' => $abandonment->getKey()])
        ->callAction('stop', ['reason' => StopReason::Purchased->value]);

    expect(lastNotification()['title'])->toBe('Stopped')
        ->and($abandonment->fresh()?->stop_reason)->toBe(StopReason::Purchased);

    Livewire::test(ViewAbandonment::class, ['record' => $abandonment->getKey()])
        ->callAction('stop', ['reason' => StopReason::OptedOut->value]);

    $notification = lastNotification();

    expect($notification['title'])->toBe('Nothing changed')
        ->and($notification['color'])->toBe('danger')
        ->and($notification['body'])->toContain('already stopped')
        ->and($abandonment->fresh()?->stop_reason)->toBe(StopReason::Purchased);
});

it('accepts the same stop twice, because that is the same fact', function (): void {
    $abandonment = record();

    foreach ([1, 2] as $ignored) {
        Livewire::test(ViewAbandonment::class, ['record' => $abandonment->getKey()])
            ->callAction('stop', ['reason' => StopReason::OptedOut->value]);
    }

    expect(lastNotification()['title'])->toBe('Stopped')
        ->and($abandonment->fresh()?->stop_reason)->toBe(StopReason::OptedOut);
});

it('offers a merchant neither erasure nor the reason that belongs to it', function (): void {
    expect(ViewAbandonment::stopOptions())->toBe([
        StopReason::Purchased->value => Render::stopLabel(StopReason::Purchased),
        StopReason::OptedOut->value => Render::stopLabel(StopReason::OptedOut),
    ]);
});

it('shows the refusal beside the attempt that did not go', function (): void {
    $abandonment = record();
    campaign();
    attempt();

    $attempt = Attempt::query()->firstOrFail();

    attemptsTable($abandonment)
        ->assertOk()
        ->assertCanSeeTableRecords([$attempt])
        ->assertTableColumnStateSet('refusal_reason', Render::refusal(RefusalReason::NoLinkerBound), $attempt)
        ->assertTableColumnStateSet('heard', Render::silence(Silence::NeverWill), $attempt);
});

it('says there is no way to know where the bound channel reports nothing back', function (): void {
    $abandonment = record();
    campaign();
    bindLinker();
    bindChannel(new FakeChannel(reports: false));
    attempt();

    attemptsTable($abandonment)->assertTableColumnStateSet(
        'heard',
        Render::silence(Silence::CannotKnow),
        Attempt::query()->firstOrFail(),
    );
});

it('says not yet where the channel reports back and the abandonment has not expired', function (): void {
    $abandonment = record();
    campaign();
    bindEverything();
    attempt();

    attemptsTable($abandonment)->assertTableColumnStateSet(
        'heard',
        Render::silence(Silence::NotYet),
        Attempt::query()->firstOrFail(),
    );
});

it('shows what was observed and what was bought, and stops calling it silence', function (): void {
    $abandonment = record();
    campaign();
    bindEverything();
    $report = attempt();

    (new RecordOutcome())(TestTenant::PRIMARY, $report->attemptId, OutcomeKind::Converted, at(), 'order-9');
    Snapshot::forget();

    $attempt = Attempt::query()->firstOrFail();

    attemptsTable($abandonment)
        ->assertTableColumnStateSet('observed', ['Converted'], $attempt)
        ->assertTableColumnStateSet('purchase_ref', 'order-9', $attempt)
        ->assertTableColumnStateSet('heard', null, $attempt);
});

it('names both possibilities for a claim that was written and never settled', function (): void {
    // `ListAttempts::silence()` branches on Refused alone, so it reports NotYet
    // for a Pending attempt — which is the one thing `AttemptState::Pending`
    // exists to say the module does not know.
    $abandonment = record();
    $campaign = campaign();
    bindEverything();

    $attempt = pendingAttempt($abandonment, $campaign);

    attemptsTable($abandonment)->assertTableColumnStateSet(
        'heard',
        Render::unsettled(Silence::NotYet),
        $attempt,
    );
});

it('says a malformed amount cannot be read back rather than showing a figure it invented', function (): void {
    // `total()` and `lineList()` rebuild value objects lazily, so a row written
    // with a currency or a line the domain will not accept raises while a column
    // renders rather than when the query ran. A column that throws mid-render is
    // a broken page — `ecommerce-abandoned-checkout#4`.
    $abandonment = record();
    $abandonment->forceFill(['currency' => 'gb'])->save();

    expect(AbandonmentResource::amount($abandonment))->toContain('cannot be read back')
        ->and(AbandonmentResource::items($abandonment))->toBe('2');

    Livewire::test(ListAbandonments::class)->assertOk();
});

it('says the same of a snapshot whose lines are not the shape the domain wrote', function (): void {
    $abandonment = record();
    $abandonment->forceFill(['lines' => [['product_ref' => 'product-1']]])->save();

    expect(AbandonmentResource::items($abandonment))->toBe(Render::NONE)
        ->and(AbandonmentResource::lines($abandonment)[0])->toContain('cannot be read back');

    Livewire::test(ViewAbandonment::class, ['record' => $abandonment->getKey()])->assertOk();
});

it('shows an empty attempt list as nobody having asked, not as asking having failed', function (): void {
    $abandonment = record();

    attemptsTable($abandonment)
        ->assertOk()
        ->assertCanNotSeeTableRecords(Attempt::query()->get());
});
