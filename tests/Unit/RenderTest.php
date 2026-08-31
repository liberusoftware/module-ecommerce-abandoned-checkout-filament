<?php

declare(strict_types=1);

use Liberu\Ecommerce\AbandonedCheckout\Data\Line;
use Liberu\Ecommerce\AbandonedCheckout\Data\Money;
use Liberu\Ecommerce\AbandonedCheckout\Enums\AbandonmentState;
use Liberu\Ecommerce\AbandonedCheckout\Enums\AttemptState;
use Liberu\Ecommerce\AbandonedCheckout\Enums\Comparison;
use Liberu\Ecommerce\AbandonedCheckout\Enums\ConditionField;
use Liberu\Ecommerce\AbandonedCheckout\Enums\OutcomeKind;
use Liberu\Ecommerce\AbandonedCheckout\Enums\RefusalReason;
use Liberu\Ecommerce\AbandonedCheckout\Enums\Silence;
use Liberu\Ecommerce\AbandonedCheckout\Enums\StopReason;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Support\Render;

/*
 * Every `match` in Render is over a domain enum with no default arm. These walk
 * `cases()` rather than naming the cases, so a case the domain adds is found
 * here by the suite rather than on a screen by an operator.
 */

it('has a sentence for every refusal the domain can record', function (): void {
    foreach (RefusalReason::cases() as $reason) {
        $sentence = Render::refusal($reason);

        expect($sentence)->not->toBe('')
            // The point of the module, said in every one of them: an attempt
            // that did not go is never a nought and never a blank.
            ->and($sentence)->not->toContain('0');
    }

    expect(count(RefusalReason::cases()))->toBe(13);
});

it('separates the three kinds of nothing, which is the reason the module exists', function (): void {
    $sentences = array_map(Render::silence(...), Silence::cases());

    expect(array_unique($sentences))->toHaveCount(3)
        ->and(Render::silence(Silence::NotYet))->toContain('yet')
        ->and(Render::silence(Silence::NeverWill))->toContain('never')
        ->and(Render::silence(Silence::CannotKnow))->toContain('no way to know');
});

it('labels and colours every attempt and abandonment state', function (): void {
    foreach (AttemptState::cases() as $state) {
        expect(Render::attemptLabel($state))->not->toBe('')->and(Render::attemptColour($state))->not->toBe('');
    }

    foreach (AbandonmentState::cases() as $state) {
        expect(Render::abandonmentLabel($state))->not->toBe('')->and(Render::abandonmentColour($state))->not->toBe('');
    }
});

it('gives pending, sent and refused three different colours', function (): void {
    // Two of the three are the host's silence. A shared colour would hide one
    // behind the other on every screen this package draws.
    $colours = array_map(Render::attemptColour(...), AttemptState::cases());

    expect(array_unique($colours))->toHaveCount(count(AttemptState::cases()));
});

it('labels every outcome, stop reason, condition field and comparison', function (): void {
    foreach (OutcomeKind::cases() as $kind) {
        expect(Render::outcomeLabel($kind))->not->toBe('');
    }

    foreach (StopReason::cases() as $reason) {
        expect(Render::stopLabel($reason))->not->toBe('');
    }

    foreach (ConditionField::cases() as $field) {
        expect(Render::fieldLabel($field))->not->toBe('');
    }

    foreach (Comparison::cases() as $operator) {
        expect(Render::comparisonLabel($operator))->not->toBe('');
    }
});

it('places an amount by the exponent the domain stores beside it', function (): void {
    // 2500 GBP minor is 25.00 and 2500 JPY minor is 2500, and nothing but the
    // exponent says which. The host's own widget hard-coded `->money('USD')` and
    // drew every order in dollars whatever currency it was taken in.
    expect(Render::money(new Money(2500, 'GBP', 2)))->toBe('25.00 GBP')
        ->and(Render::money(new Money(2500, 'JPY', 0)))->toBe('2500 JPY')
        ->and(Render::money(new Money(1234, 'KWD', 3)))->toBe('1.234 KWD')
        ->and(Render::money(new Money(12345, 'CLF', 4)))->toBe('1.2345 CLF');
});

it('pads an amount smaller than one major unit rather than losing the digits', function (): void {
    expect(Render::money(new Money(5, 'GBP', 2)))->toBe('0.05 GBP')
        ->and(Render::money(new Money(0, 'GBP', 2)))->toBe('0.00 GBP')
        ->and(Render::money(new Money(0, 'JPY', 0)))->toBe('0 JPY');
});

it('keeps the sign outside the digits of a negative amount', function (): void {
    expect(Render::money(new Money(-2500, 'GBP', 2)))->toBe('-25.00 GBP')
        ->and(Render::money(new Money(-5, 'JPY', 0)))->toBe('-5 JPY');
});

it('renders the stored lines, and falls back to the product reference where a name was not kept', function (): void {
    $lines = [
        new Line('product-1', 'A Kettle', 'KET-1', 2, new Money(2500, 'GBP', 2)),
        new Line('product-2', '', '', 1, new Money(999, 'GBP', 2)),
    ];

    expect(Render::lines($lines))->toBe([
        '2 × A Kettle (KET-1) at 25.00 GBP each',
        '1 × product-2 (product-2) at 9.99 GBP each',
    ])->and(Render::lines([]))->toBe([]);
});

it('says which of three things is at the end of a linker binding', function (): void {
    // Bound, nothing bound, and configured-but-unbuildable are three facts. The
    // third is a misconfiguration, and calling it "unbound" would send an
    // operator to bind something that is already there.
    expect(Render::link(true))->toContain('a linker is bound')
        ->and(Render::link(false))->toBe(Render::refusal(RefusalReason::NoLinkerBound))
        ->and(Render::link(null))->toContain('the container cannot build it');
});

it('says which of four things is at the end of a channel key', function (): void {
    expect(Render::channel(true, false))->toContain('it reports back')
        ->and(Render::channel(false, false))->toContain('reports nothing back')
        ->and(Render::channel(null, false))->toBe(Render::refusal(RefusalReason::NoChannelBound))
        ->and(Render::channel(null, true))->toContain('the container cannot build it')
        // A misconfiguration is a misconfiguration whatever was bound before it.
        ->and(Render::channel(true, true))->toContain('the container cannot build it');
});

it('names both possibilities for a claim that was never settled', function (): void {
    // `ListAttempts::silence()` branches on Refused alone, so it reads a Pending
    // attempt as though the message had gone — which is the one thing
    // `AttemptState::Pending` exists to say the module does not know.
    $sentence = Render::unsettled(Silence::NotYet);

    expect($sentence)->toContain('whether this went at all is not known')
        ->and($sentence)->toContain(Render::silence(Silence::NotYet));
});

it('says a stored value cannot be read back rather than inventing one', function (): void {
    expect(Render::unreadable('amount'))->toContain('cannot be read back')
        ->and(Render::unreadable('amount'))->toContain('amount');
});

it('renders a redacted reference as a fact rather than as a blank', function (): void {
    expect(Render::reference(null))->toContain('redacted')
        ->and(Render::reference(''))->toContain('redacted')
        ->and(Render::reference('person-1'))->toBe('person-1');
});
