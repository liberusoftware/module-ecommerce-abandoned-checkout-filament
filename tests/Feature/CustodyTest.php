<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Resources\Abandonments\AbandonmentResource;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Resources\Abandonments\Pages\ListAbandonments;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Resources\Abandonments\Pages\ViewAbandonment;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Resources\Abandonments\RelationManagers\AttemptsRelationManager;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Resources\Campaigns\CampaignResource;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Resources\Campaigns\Pages\ViewCampaign;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Support\PanelTenant;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Support\Snapshot;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Tests\TestTenant;
use Liberu\Ecommerce\AbandonedCheckout\Models\Abandonment;
use Liberu\Ecommerce\AbandonedCheckout\Models\Attempt;
use Liberu\Ecommerce\AbandonedCheckout\Models\Campaign;
use Livewire\Livewire;

/*
 * Two merchants with deliberately identical values: the same checkout reference,
 * the same campaign name, the same channel key. Both are the ordinary case —
 * the reference is whatever each storefront minted, and nothing stops two
 * merchants minting the same string — and a proof that creates one merchant's
 * rows proves nothing about a `where` nobody wrote.
 *
 * Every relation on every screen is exercised, not only every list: tenancy has
 * leaked through relations rather than queries in four consecutive waves, and a
 * surface is where `withCount()` gets reached for.
 */

/** @return array{ours: array{abandonment: Abandonment, campaign: Campaign}, theirs: array{abandonment: Abandonment, campaign: Campaign}} */
function twoMerchants(): array
{
    bindEverything();

    $rows = [];

    foreach ([TestTenant::PRIMARY, TestTenant::OTHER] as $tenant) {
        $abandonment = record(tenantId: $tenant);
        $campaign = campaign(tenantId: $tenant);

        attempt(tenantId: $tenant, at: '2026-08-31 12:00:00');
        attempt(tenantId: $tenant, at: '2026-08-31 13:01:00');

        $rows[] = ['abandonment' => $abandonment, 'campaign' => $campaign];
    }

    Snapshot::forget();

    return ['ours' => $rows[0], 'theirs' => $rows[1]];
}

it('lists only this merchant’s abandonments and campaigns', function (): void {
    $rows = twoMerchants();

    Livewire::test(ListAbandonments::class)
        ->assertCanSeeTableRecords(Abandonment::query()->whereKey($rows['ours']['abandonment']->getKey())->get())
        ->assertCanNotSeeTableRecords(Abandonment::query()->whereKey($rows['theirs']['abandonment']->getKey())->get());

    expect(AbandonmentResource::getEloquentQuery()->pluck('tenant_id')->unique()->all())->toBe([TestTenant::PRIMARY])
        ->and(CampaignResource::getEloquentQuery()->pluck('tenant_id')->unique()->all())->toBe([TestTenant::PRIMARY]);
});

it('counts through both restated relations, and gets the right non-zero number', function (): void {
    // `withCount()` builds the relation from a fresh instance whose `tenant_id`
    // is null. Unguarded, the restatement becomes `where('tenant_id', '')` and
    // reports zero for everything, which looks exactly like isolation working.
    twoMerchants();

    $abandonment = AbandonmentResource::getEloquentQuery()->firstOrFail();
    $campaign = CampaignResource::getEloquentQuery()->firstOrFail();

    // Against this merchant's own rows rather than a literal, so the proof stays
    // about the guard rather than about how many attempts the helper makes.
    $mine = Attempt::query()->where('tenant_id', TestTenant::PRIMARY)->count();

    expect($mine)->toBe(2)
        ->and($abandonment->attempts_count)->toBe($mine)
        ->and($campaign->attempts_count)->toBe($mine);
});

it('shows only this abandonment’s attempts through its relation manager', function (): void {
    $rows = twoMerchants();

    $records = Livewire::test(AttemptsRelationManager::class, [
        'ownerRecord' => $rows['ours']['abandonment'],
        'pageClass' => ViewAbandonment::class,
    ])->instance()->getTable()->getRecords();

    /** @var Collection<int, Model> $attempts */
    $attempts = $records instanceof Collection ? $records : Collection::make($records->items());

    expect($attempts)->toHaveCount(2)
        ->and($attempts->pluck('tenant_id')->unique()->all())->toBe([TestTenant::PRIMARY]);
});

it('answers another merchant’s record exactly as it answers nobody’s', function (): void {
    // Not a 403 and not a different message: the panel is not a directory of the
    // deployment, so "belongs to somebody else" and "does not exist" are one
    // answer with one exception.
    $rows = twoMerchants();

    $abandonment = fn (int|string $key): mixed => Livewire::test(ViewAbandonment::class, ['record' => $key]);
    $campaign = fn (int|string $key): mixed => Livewire::test(ViewCampaign::class, ['record' => $key]);

    expect(fn (): mixed => $abandonment($rows['theirs']['abandonment']->getKey()))->toThrow(ModelNotFoundException::class)
        ->and(fn (): mixed => $abandonment(99999))->toThrow(ModelNotFoundException::class)
        ->and(fn (): mixed => $campaign($rows['theirs']['campaign']->getKey()))->toThrow(ModelNotFoundException::class)
        ->and(fn (): mixed => $campaign(99999))->toThrow(ModelNotFoundException::class);
});

it('keeps a record in one merchant’s hands even where both hold the identical reference', function (): void {
    $rows = twoMerchants();

    expect($rows['ours']['abandonment']->checkout_ref)->toBe($rows['theirs']['abandonment']->checkout_ref)
        ->and($rows['ours']['campaign']->name)->toBe($rows['theirs']['campaign']->name)
        ->and(AbandonmentResource::canView($rows['ours']['abandonment']))->toBeTrue()
        ->and(AbandonmentResource::canView($rows['theirs']['abandonment']))->toBeFalse()
        ->and(CampaignResource::canView($rows['ours']['campaign']))->toBeTrue()
        ->and(CampaignResource::canView($rows['theirs']['campaign']))->toBeFalse();
});

it('follows the panel’s merchant when it changes, rather than the first one it saw', function (): void {
    $rows = twoMerchants();

    TestTenant::use(TestTenant::OTHER);
    Snapshot::forget();

    Livewire::test(ListAbandonments::class)
        ->assertCanSeeTableRecords(Abandonment::query()->whereKey($rows['theirs']['abandonment']->getKey())->get())
        ->assertCanNotSeeTableRecords(Abandonment::query()->whereKey($rows['ours']['abandonment']->getKey())->get());
});

it('refuses to resolve a panel with no merchant rather than matching orphan rows', function (): void {
    // `where('tenant_id', null)` compiles to `is null`, which lists exactly the
    // orphan rows a scope exists to hide.
    PanelTenant::resolveUsing(fn (): ?string => null);

    expect(fn (): string => PanelTenant::current())->toThrow(RuntimeException::class);

    PanelTenant::resolveUsing(fn (): string => '');

    expect(fn (): string => PanelTenant::current())->toThrow(RuntimeException::class);

    PanelTenant::resolveUsing(fn (): int => 7);

    expect(PanelTenant::current())->toBe('7');
});

it('has no merchant to fall back to when the host names no resolver', function (): void {
    // A panel with no Filament tenancy and no resolver has no merchant to be.
    // There is no "show everything" to fall back to.
    PanelTenant::resolveUsing(null);

    expect(fn (): string => PanelTenant::current())->toThrow(RuntimeException::class);
});
