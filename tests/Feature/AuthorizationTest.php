<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Concerns\DeniesUnpublishedRelationAbilities;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Concerns\DeniesUnpublishedResourceAbilities;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Pages\Outreach;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Resources\Abandonments\AbandonmentResource;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Resources\Abandonments\RelationManagers\AttemptsRelationManager;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Resources\Campaigns\CampaignResource;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Support\PanelTenant;
use Liberu\Ecommerce\AbandonedCheckout\Models\Abandonment;

/*
 * The ability matrix, asserted by name.
 *
 * A missing policy is permissive, and a policy that exists but lacks the method
 * asked about is also permissive, because Filament falls through to `allow()`.
 * The only way to know an ability is closed is to ask it and be told no.
 *
 * A delete here would not merely be wrong: an abandonment cascades to its
 * attempts and their outcomes, and an outcome raises from its own `deleting`
 * hook. An ability that fatals instead of refusing is still an ability offered.
 */

$resources = [[AbandonmentResource::class], [CampaignResource::class]];

$managers = [[AttemptsRelationManager::class]];

it('publishes no create, no edit and no delete on a record the domain will not change', function (string $resource): void {
    $record = new Abandonment();

    expect($resource::canCreate())->toBeFalse()
        ->and($resource::canEdit($record))->toBeFalse()
        ->and($resource::canDelete($record))->toBeFalse()
        ->and($resource::canDeleteAny())->toBeFalse()
        ->and($resource::canForceDelete($record))->toBeFalse()
        ->and($resource::canForceDeleteAny())->toBeFalse()
        ->and($resource::canReorder())->toBeFalse()
        ->and($resource::canReplicate($record))->toBeFalse()
        // Neither table soft-deletes, so a restore could restore nothing.
        ->and($resource::canRestore($record))->toBeFalse()
        ->and($resource::canRestoreAny())->toBeFalse();
})->with($resources);

it('registers no create page and no edit page on any resource', function (): void {
    expect(array_keys(AbandonmentResource::getPages()))->toBe(['index', 'view'])
        ->and(array_keys(CampaignResource::getPages()))->toBe(['index', 'view']);
});

it('refuses to view anything at all when the panel has no merchant', function (): void {
    $abandonment = record();

    PanelTenant::resolveUsing(fn (): ?string => null);

    expect(AbandonmentResource::canViewAny())->toBeFalse()
        ->and(AbandonmentResource::canView($abandonment))->toBeFalse()
        ->and(CampaignResource::canViewAny())->toBeFalse()
        ->and(CampaignResource::canView(campaign()))->toBeFalse()
        ->and(Outreach::canAccess())->toBeFalse();
});

it('answers a model that is not its own with no', function (): void {
    // `canView` takes Filament's `Model`. A resource that assumed its own model
    // would fatal rather than refuse.
    expect(AbandonmentResource::canView(new User()))->toBeFalse()
        ->and(CampaignResource::canView(new User()))->toBeFalse();
});

it('closes every relation-manager ability by name, including associate and dissociate', function (string $manager): void {
    /** @var object $instance */
    $instance = new $manager();
    $record = new Abandonment();

    // `canAssociate` and `canDissociate` are live on a `hasMany` and default
    // open. Dissociating an attempt from its abandonment moves a claim out from
    // under the campaign's cap, with no edit form and no audit row.
    expect($instance->canAssociate())->toBeFalse()
        ->and($instance->canDissociate($record))->toBeFalse()
        ->and($instance->canDissociateAny())->toBeFalse()
        ->and($instance->canAttach())->toBeFalse()
        ->and($instance->canDetach($record))->toBeFalse()
        ->and($instance->canDetachAny())->toBeFalse()
        ->and($instance->canCreate())->toBeFalse()
        ->and($instance->canEdit($record))->toBeFalse()
        ->and($instance->canDelete($record))->toBeFalse()
        ->and($instance->canDeleteAny())->toBeFalse()
        ->and($instance->canForceDelete($record))->toBeFalse()
        ->and($instance->canForceDeleteAny())->toBeFalse()
        ->and($instance->canReorder())->toBeFalse()
        ->and($instance->canReplicate($record))->toBeFalse()
        ->and($instance->canRestore($record))->toBeFalse()
        ->and($instance->canRestoreAny())->toBeFalse()
        ->and($instance->canView($record))->toBeFalse()
        // The one ability published, stated rather than inherited.
        ->and($instance->canViewAny())->toBeTrue();
})->with($managers);

it('names no method after a Filament ability outside the two concerns that close them', function (string $class): void {
    // A subclass method wins over a trait's, so a method named for an ability
    // would silently reopen it.
    $abilities = array_diff(
        array_merge(
            get_class_methods(DeniesUnpublishedResourceAbilities::class),
            get_class_methods(DeniesUnpublishedRelationAbilities::class),
        ),
        // The two each resource states and answers for itself.
        ['canView', 'canViewAny'],
    );

    // A trait's methods report the using class as their declaring class, so the
    // file is what separates "this class wrote it" from "the trait did".
    $reflection = new ReflectionClass($class);
    $file = $reflection->getFileName();

    $declared = array_map(
        fn (ReflectionMethod $method): string => $method->getName(),
        array_filter(
            $reflection->getMethods(),
            fn (ReflectionMethod $method): bool => $method->getFileName() === $file,
        ),
    );

    expect(array_intersect($declared, $abilities))->toBe([]);
})->with(array_merge($resources, $managers));

it('applies a closing concern to every resource and relation manager the plugin registers', function (): void {
    // A new screen cannot arrive open: the concern is what makes an ability
    // nobody thought about closed rather than allowed.
    foreach ([AbandonmentResource::class, CampaignResource::class] as $resource) {
        expect(in_array(DeniesUnpublishedResourceAbilities::class, class_uses($resource), true))->toBeTrue();
    }

    expect(in_array(DeniesUnpublishedRelationAbilities::class, class_uses(AttemptsRelationManager::class), true))->toBeTrue();
});
