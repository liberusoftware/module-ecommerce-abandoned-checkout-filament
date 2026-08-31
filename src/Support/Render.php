<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\AbandonedCheckout\Filament\Support;

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

/**
 * The sentences this panel is most likely to get wrong, written once.
 *
 * Nothing here invents a figure. An attempt that did not go has a refusal with a
 * reason on it, and the reason is what renders — never an empty row, which is
 * the appearance the whole module exists to tell apart from "nothing to do".
 *
 * Every `match` is over a domain enum with no default arm, so a case the domain
 * adds is a hole here rather than a blank on a screen, and `RenderTest` walks
 * `cases()`.
 */
final class Render
{
    public const NONE = '—';

    /** Which joint said no, in a sentence an operator can act on. */
    public static function refusal(RefusalReason $reason): string
    {
        return match ($reason) {
            RefusalReason::NoChannelBound => 'nothing is bound to this campaign\'s channel key, so nobody was asked to carry the message — which is not a failure to send, it is not having anywhere to send through',
            RefusalReason::ChannelUnavailable => 'the bound channel was asked and raised, so whether the message went is unknown rather than no',
            RefusalReason::NoLinkerBound => 'nothing is bound to turn this abandonment into a URL, and a message with no way back to the basket is not worth sending; a package cannot know a host\'s routes',
            RefusalReason::LinkerUnavailable => 'the bound linker was asked for a URL and raised, so the message was not composed at all',
            RefusalReason::NoContact => 'this abandonment names no contact reference, which is the ordinary case for a shopper who left before typing one',
            RefusalReason::CapReached => 'this campaign has already made every attempt its cap allows on this channel; a refusal takes no slot, so binding a channel later does not find the cap spent',
            RefusalReason::QuietPeriod => 'the campaign\'s quiet period since the last attempt on this channel has not elapsed',
            RefusalReason::DelayNotElapsed => 'the campaign\'s delay since the checkout was abandoned has not elapsed',
            RefusalReason::OutreachStopped => 'outreach on this abandonment has been stopped, so no campaign reaches it again',
            RefusalReason::AbandonmentExpired => 'this abandonment has passed the moment it expires, and a recovery of something no longer recoverable is not one',
            RefusalReason::ConditionNotMet => 'this abandonment does not satisfy every condition the campaign names',
            RefusalReason::ConditionIsMalformed => 'a condition on this campaign names a field or an operator the module does not publish, and an unrecognised field refuses rather than passing',
            RefusalReason::ConcurrentAttempt => 'another worker claimed the same slot under the cap first, and the index arbitrated rather than letting both send',
        };
    }

    /** What is at the end of the linker binding. Null is configured and unbuildable, not unbound. */
    public static function link(?bool $bound): string
    {
        return match ($bound) {
            true => 'a linker is bound, so a message would carry a URL the host serves',
            false => self::refusal(RefusalReason::NoLinkerBound),
            null => 'something is configured to build the URL and the container cannot build it, so this is a misconfiguration rather than an unbound seam',
        };
    }

    /**
     * What is at the end of one channel key.
     *
     * Three facts, and "nothing is bound" is only one of them: a class name the
     * container cannot build is a misconfiguration, and a transport that reports
     * nothing back leaves silence unreadable. `$reports` is null where nothing
     * answered at all.
     */
    public static function channel(?bool $reports, bool $unresolvable): string
    {
        if ($unresolvable) {
            return 'something is configured for this key and the container cannot build it, so this is a misconfiguration rather than an unbound seam, and no attempt on it will go';
        }

        if ($reports === null) {
            return self::refusal(RefusalReason::NoChannelBound);
        }

        return $reports
            ? 'something is bound to this key and it reports back, so silence after a message is readable'
            : 'something is bound to this key, but it reports nothing back, so nothing heard afterwards means there is no way to know rather than no';
    }

    /** Three answers a null cannot separate, which is the reason the module exists. */
    public static function silence(Silence $silence): string
    {
        return match ($silence) {
            Silence::NotYet => 'nothing heard yet — the channel reports back and this abandonment has not expired',
            Silence::NeverWill => 'nothing will be heard — either the attempt never went, or the abandonment has expired',
            Silence::CannotKnow => 'there is no way to know — the channel that carried this reports nothing back, so silence here is unreadable rather than negative',
        };
    }

    /**
     * A claim written before the send and never settled.
     *
     * The domain reads its silence as though the message had gone —
     * `ListAttempts::silence()` branches on `Refused` alone — and
     * `AttemptState::Pending`'s own docblock says the module does not know
     * whether it went. Both possibilities are named here rather than one being
     * picked, which is the same answer wave 22 reached about a definition a run
     * held neither a number nor a refusal for.
     */
    public static function unsettled(Silence $silence): string
    {
        return 'whether this went at all is not known: the claim was written before the send and never settled. '
            .'Read as though it had gone, '.self::silence($silence);
    }

    /**
     * A stored value that cannot be read back as it was written.
     *
     * The lines and the amount are rebuilt into value objects lazily, so a row
     * whose JSON or currency code is malformed raises while a column renders
     * rather than when the query runs. A table column that throws mid-render is
     * a broken page, and a figure invented in its place is worse than both.
     */
    public static function unreadable(string $what): string
    {
        return 'The '.$what.' cannot be read back as it was stored, so nothing is shown here rather than a figure this screen invented.';
    }

    public static function attemptLabel(AttemptState $state): string
    {
        return match ($state) {
            AttemptState::Pending => 'Pending',
            AttemptState::Sent => 'Sent',
            AttemptState::Refused => 'Refused',
        };
    }

    /** Pending, sent and refused must not share a colour: two of them are the host's silence. */
    public static function attemptColour(AttemptState $state): string
    {
        return match ($state) {
            AttemptState::Pending => 'warning',
            AttemptState::Sent => 'success',
            AttemptState::Refused => 'danger',
        };
    }

    public static function abandonmentLabel(AbandonmentState $state): string
    {
        return match ($state) {
            AbandonmentState::Open => 'Open',
            AbandonmentState::Recovered => 'Recovered',
            AbandonmentState::Stopped => 'Stopped',
            AbandonmentState::Expired => 'Expired',
        };
    }

    public static function abandonmentColour(AbandonmentState $state): string
    {
        return match ($state) {
            AbandonmentState::Open => 'info',
            AbandonmentState::Recovered => 'success',
            AbandonmentState::Stopped => 'gray',
            AbandonmentState::Expired => 'warning',
        };
    }

    public static function outcomeLabel(OutcomeKind $kind): string
    {
        return match ($kind) {
            OutcomeKind::Delivered => 'Delivered',
            OutcomeKind::Failed => 'Failed',
            OutcomeKind::Engaged => 'Engaged',
            OutcomeKind::Converted => 'Converted',
        };
    }

    public static function stopLabel(StopReason $reason): string
    {
        return match ($reason) {
            StopReason::Purchased => 'They bought it in the end',
            StopReason::OptedOut => 'They asked not to be contacted',
            StopReason::Erased => 'Their record was erased',
        };
    }

    public static function fieldLabel(ConditionField $field): string
    {
        return match ($field) {
            ConditionField::CartValueMinor => 'Basket total in minor units',
            ConditionField::ItemCount => 'Number of items',
            ConditionField::LineCount => 'Number of distinct lines',
            ConditionField::MinutesSinceAbandoned => 'Minutes since it was abandoned',
        };
    }

    public static function comparisonLabel(Comparison $operator): string
    {
        return match ($operator) {
            Comparison::Eq => 'is',
            Comparison::Ne => 'is not',
            Comparison::Gt => 'is more than',
            Comparison::Gte => 'is at least',
            Comparison::Lt => 'is less than',
            Comparison::Lte => 'is at most',
        };
    }

    /**
     * An amount, placed by the exponent the domain stores beside it.
     *
     * 2500 GBP minor is 25.00 and 2500 JPY minor is 2500, and nothing but the
     * exponent says which. It is stated here and not guessed: the host's own
     * orders widget hard-coded `->money('USD')` and drew every order in dollars
     * whatever currency it was taken in, and a surface that divides by a hundred
     * makes the same mistake with more confidence. The digits are placed by
     * string, so no float and no division is involved in a money figure.
     */
    public static function money(Money $money): string
    {
        $sign = $money->minor < 0 ? '-' : '';
        $digits = (string) abs($money->minor);

        if ($money->exponent === 0) {
            return $sign.$digits.' '.$money->currency;
        }

        $digits = str_pad($digits, $money->exponent + 1, '0', STR_PAD_LEFT);

        return $sign.substr($digits, 0, -$money->exponent).'.'.substr($digits, -$money->exponent).' '.$money->currency;
    }

    /**
     * The snapshot as it was stored, never re-resolved against a catalogue that
     * has since been repriced.
     *
     * @param  list<Line>  $lines
     * @return list<string>
     */
    public static function lines(array $lines): array
    {
        return array_map(
            static fn (Line $line): string => $line->quantity.' × '.($line->name === '' ? $line->productRef : $line->name)
                .' ('.($line->sku === '' ? $line->productRef : $line->sku).') at '.self::money($line->unitPrice).' each',
            $lines,
        );
    }

    /** A reference the erasure path has redacted, which is a fact rather than a blank. */
    public static function reference(?string $reference): string
    {
        return ($reference ?? '') === '' ? 'Nobody named — the reference has been redacted' : (string) $reference;
    }
}
