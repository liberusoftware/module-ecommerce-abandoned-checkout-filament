<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Liberu\Ecommerce\AbandonedCheckout\Enums\Comparison;
use Liberu\Ecommerce\AbandonedCheckout\Enums\ConditionField;
use Liberu\Ecommerce\AbandonedCheckout\Enums\RefusalReason;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Resources\Campaigns\CampaignResource;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Resources\Campaigns\Pages\ListCampaigns;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Resources\Campaigns\Pages\ViewCampaign;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Support\Render;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Support\Snapshot;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Tests\TestTenant;
use Liberu\Ecommerce\AbandonedCheckout\Models\Campaign;
use Livewire\Livewire;

it('lists this merchant’s campaigns', function (): void {
    campaign(name: 'first-nudge');
    campaign(name: 'second-nudge');

    Livewire::test(ListCampaigns::class)
        ->assertOk()
        ->assertCanSeeTableRecords(Campaign::query()->orderBy('name')->get());
});

it('defines a campaign through the published action and says whether anything carries it', function (): void {
    Livewire::test(ListCampaigns::class)->callAction('define', [
        'name' => 'first-nudge',
        'channel_key' => 'email',
        'cap' => '2',
        'quiet_minutes' => '30',
        'delay_minutes' => '45',
        'conditions' => [],
    ]);

    $campaign = Campaign::query()->firstOrFail();
    $notification = lastNotification();

    expect($campaign->name)->toBe('first-nudge')
        ->and($campaign->cap)->toBe(2)
        ->and($campaign->quiet_minutes)->toBe(30)
        ->and($campaign->delay_minutes)->toBe(45)
        ->and($notification['title'])->toBe('Defined')
        ->and($notification['body'])->toContain('Nothing is bound to this campaign');
});

it('takes every condition field and every operator from a domain enum', function (): void {
    // The host matched a raw field name and returned true on the default arm, so
    // a typo passed every condition it was written into. A field this module
    // does not publish cannot be entered here at all.
    expect(array_keys(ListCampaigns::fieldOptions()))
        ->toBe(array_map(fn (ConditionField $field): string => $field->value, ConditionField::cases()))
        ->and(array_keys(ListCampaigns::operatorOptions()))
        ->toBe(array_map(fn (Comparison $operator): string => $operator->value, Comparison::cases()));
});

it('carries an amount condition through with the currency it is only comparable against', function (): void {
    Livewire::test(ListCampaigns::class)->callAction('define', [
        'name' => 'big-baskets',
        'channel_key' => 'email',
        'cap' => '3',
        'quiet_minutes' => '60',
        'delay_minutes' => '60',
        'conditions' => [
            ['field' => ConditionField::CartValueMinor->value, 'operator' => Comparison::Gte->value, 'value' => '5000', 'currency' => 'gbp'],
            ['field' => ConditionField::ItemCount->value, 'operator' => Comparison::Gt->value, 'value' => '1'],
        ],
    ]);

    expect(CampaignResource::conditionLines(Campaign::query()->firstOrFail()))->toBe([
        'Basket total in minor units is at least 5000 GBP (minor units)',
        'Number of items is more than 1',
    ]);
});

it('renders the refusal an attempt would get, rather than raising, when a stored condition is malformed', function (): void {
    $campaign = campaign();

    // Written straight into the column, which is the one way a field the module
    // does not publish can arrive at all.
    $campaign->forceFill(['conditions' => [['field' => 'cart_valeu', 'operator' => '=', 'value' => 1]]])->save();

    expect(CampaignResource::conditionLines($campaign))->toBe([Render::refusal(RefusalReason::ConditionIsMalformed)]);
});

it('says on the record whether anything is bound to the key it names', function (): void {
    $campaign = campaign(channelKey: 'email');

    expect(CampaignResource::binding($campaign))->toContain('Nothing is bound to this campaign');

    bindChannel();

    expect(CampaignResource::binding($campaign))->toContain('Something is bound to this key and it reports back');
});

it('says how much is due under this merchant’s campaigns, or that nothing is', function (): void {
    $campaign = campaign();

    expect(CampaignResource::due($campaign))->toContain('Nothing is due');

    record();
    Snapshot::forget();

    expect(CampaignResource::due($campaign))->toContain('1 abandonments are due');
});

it('calls a binding the container cannot build a misconfiguration, not an unbound seam', function (): void {
    // `Seams::channel()` raises on a class name that does not resolve rather
    // than refusing, so without a guard a typo in the host's config is a 500 on
    // every screen here — and calling it "unbound" would send an operator to
    // bind something that is already there.
    $campaign = campaign(channelKey: 'email');

    Config::set('abandoned_checkout.channels', ['email' => 'Nobody\\Implements\\This']);
    Snapshot::forget();

    expect(Snapshot::unresolvable(TestTenant::PRIMARY))->toBe(['email'])
        ->and(Snapshot::channels(TestTenant::PRIMARY))->toBe(['email' => false])
        ->and(Snapshot::reporting(TestTenant::PRIMARY))->toBe([])
        ->and(CampaignResource::binding($campaign))->toContain('the container cannot build it');
});

it('renders one campaign with its rule and its conditions', function (): void {
    $campaign = campaign(cap: 2, quietMinutes: 30, delayMinutes: 45);

    Livewire::test(ViewCampaign::class, ['record' => $campaign->getKey()])
        ->assertOk()
        ->assertSee('so nobody was asked');
});
