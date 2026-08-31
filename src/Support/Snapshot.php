<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\AbandonedCheckout\Filament\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Liberu\Ecommerce\AbandonedCheckout\Contracts\RecoveryChannel;
use Liberu\Ecommerce\AbandonedCheckout\Data\AttemptDescription;
use Liberu\Ecommerce\AbandonedCheckout\Enums\AttemptState;
use Liberu\Ecommerce\AbandonedCheckout\Models\Abandonment;
use Liberu\Ecommerce\AbandonedCheckout\Models\Attempt;
use Liberu\Ecommerce\AbandonedCheckout\Models\Campaign;
use Liberu\Ecommerce\AbandonedCheckout\Queries\ListAttempts;
use Liberu\Ecommerce\AbandonedCheckout\Queries\ListCampaigns;
use Liberu\Ecommerce\AbandonedCheckout\Queries\ListDueAbandonments;
use Liberu\Ecommerce\AbandonedCheckout\Support\Seams;
use Throwable;

/**
 * One read of the domain per request, shared by everything on the page.
 *
 * No action in the domain calls `now()`; every one takes the instant it is
 * reasoning about. This is where that instant comes from, once, so a page
 * rendered across the moment an abandonment expires cannot show one widget
 * calling it open and another calling it expired.
 *
 * Every entry point states the tenant, because a widget is the surface no panel
 * scope reaches.
 *
 * ponytail: a process-static memo, which is one request under FPM. Reset it in a
 * boot hook if this ever runs under a persistent worker.
 */
final class Snapshot
{
    private static ?CarbonImmutable $asOf = null;

    /** @var array<string, list<Campaign>> */
    private static array $campaigns = [];

    /** @var array<string, Collection<int, Attempt>> */
    private static array $attempts = [];

    /** @var array<string, array<int, AttemptDescription>> */
    private static array $descriptions = [];

    /** @var array<string, list<int>> */
    private static array $due = [];

    /** @var array<string, array<string, ?RecoveryChannel>> */
    private static array $transports = [];

    /** @var array<string, list<string>> */
    private static array $broken = [];

    /** The one clock this package reads, and the only place it is read. */
    public static function asOf(): CarbonImmutable
    {
        return self::$asOf ??= CarbonImmutable::now();
    }

    /** @return list<Campaign> */
    public static function campaigns(string $tenantId): array
    {
        return self::$campaigns[$tenantId] ??= (new ListCampaigns())($tenantId);
    }

    /**
     * Which channel keys this merchant's campaigns name, and whether anything
     * answers each of them. A key with nothing against it is the module's
     * honest description of a fresh install rather than an error.
     *
     * @return array<string, bool>
     */
    public static function channels(string $tenantId): array
    {
        return array_map(
            static fn (?RecoveryChannel $channel): bool => $channel !== null,
            self::transports($tenantId),
        );
    }

    /**
     * Keys whose configured binding the container could not build at all.
     *
     * `Seams::channel()` raises on a class name that does not resolve rather
     * than refusing — `ecommerce-abandoned-checkout#2` — so without this every
     * screen here would answer a typo in the host's config with a 500. A
     * misconfiguration is a third fact, not the unbound one.
     *
     * @return list<string>
     */
    public static function unresolvable(string $tenantId): array
    {
        self::transports($tenantId);

        return self::$broken[$tenantId];
    }

    /**
     * Of the channels that are bound, which of them ever report back.
     *
     * A bound transport that reports nothing makes silence unreadable, and the
     * domain says so rather than reading it as "not yet".
     *
     * @return array<string, bool>
     */
    public static function reporting(string $tenantId): array
    {
        $reports = [];

        foreach (self::transports($tenantId) as $key => $channel) {
            if ($channel !== null) {
                $reports[$key] = $channel->reportsOutcomes();
            }
        }

        return $reports;
    }

    /**
     * Whether anything can turn an abandonment into a URL. Unbound, every
     * attempt refuses; null where something is configured that the container
     * cannot build, which is a third fact and not an error page.
     */
    public static function linkBound(): ?bool
    {
        try {
            return Seams::link() !== null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * What became of each attempt on one abandonment, keyed by attempt.
     *
     * `ListAttempts` is the only published answer to "was anything heard", and
     * it separates the three silences a null cannot.
     *
     * @return array<int, AttemptDescription>
     */
    public static function descriptions(string $tenantId, int $abandonmentId): array
    {
        $key = $tenantId.'/'.$abandonmentId;

        if (array_key_exists($key, self::$descriptions)) {
            return self::$descriptions[$key];
        }

        $keyed = [];

        foreach ((new ListAttempts())($tenantId, $abandonmentId, self::asOf()) as $description) {
            $keyed[$description->attemptId] = $description;
        }

        return self::$descriptions[$key] = $keyed;
    }

    /**
     * One attempt's description, or null where the query describes none.
     *
     * A keyed accessor rather than an offset, so a caller cannot reach for one
     * that is not there and so the "nothing was observed" arm and the "nothing
     * described it" arm are the same arm.
     */
    public static function description(string $tenantId, int $abandonmentId, int $attemptId): ?AttemptDescription
    {
        return self::descriptions($tenantId, $abandonmentId)[$attemptId] ?? null;
    }

    /**
     * The abandonments the domain says are due right now, across every campaign
     * this merchant has. `Eligibility` decides; this only asks.
     *
     * @return list<int>
     */
    public static function dueIds(string $tenantId): array
    {
        if (array_key_exists($tenantId, self::$due)) {
            return self::$due[$tenantId];
        }

        $ids = [];

        foreach (self::campaigns($tenantId) as $campaign) {
            foreach ((new ListDueAbandonments())($tenantId, $campaign->id, self::asOf()) as $abandonment) {
                $ids[$abandonment->id] = true;
            }
        }

        $ids = array_keys($ids);
        sort($ids);

        return self::$due[$tenantId] = $ids;
    }

    /**
     * Why nothing has gone, counted by reason.
     *
     * The domain publishes no aggregate over attempts, so this counts rows —
     * `ecommerce-abandoned-checkout#1` — and does it in one place, with the
     * tenant stated, rather than once per widget.
     *
     * @return array<string, int>
     */
    public static function refusals(string $tenantId): array
    {
        $counts = [];

        foreach (self::rows($tenantId) as $attempt) {
            $reason = $attempt->refusal_reason;

            if ($reason !== null) {
                $counts[$reason->value] = ($counts[$reason->value] ?? 0) + 1;
            }
        }

        ksort($counts);

        return $counts;
    }

    /** How many messages actually went out. Not the same number as attempts made. */
    public static function sent(string $tenantId): int
    {
        $sent = 0;

        foreach (self::rows($tenantId) as $attempt) {
            $sent += $attempt->state === AttemptState::Sent ? 1 : 0;
        }

        return $sent;
    }

    /**
     * Claims written before a send and never settled.
     *
     * Neither sent nor refused, and the domain reads their silence as though
     * they had gone. Counted separately so the standing does not imply either.
     */
    public static function unsettled(string $tenantId): int
    {
        $pending = 0;

        foreach (self::rows($tenantId) as $attempt) {
            $pending += $attempt->state === AttemptState::Pending ? 1 : 0;
        }

        return $pending;
    }

    public static function attemptCount(string $tenantId): int
    {
        return self::rows($tenantId)->count();
    }

    public static function abandonmentCount(string $tenantId): int
    {
        return Abandonment::query()->where('tenant_id', $tenantId)->count();
    }

    public static function forget(): void
    {
        self::$asOf = null;
        self::$campaigns = [];
        self::$attempts = [];
        self::$descriptions = [];
        self::$due = [];
        self::$transports = [];
        self::$broken = [];
    }

    /**
     * Whatever is at the end of each channel key this merchant names, resolved
     * once and guarded, so a host's bad config value is a fact on the screen
     * rather than an error page.
     *
     * @return array<string, ?RecoveryChannel>
     */
    private static function transports(string $tenantId): array
    {
        if (array_key_exists($tenantId, self::$transports)) {
            return self::$transports[$tenantId];
        }

        $transports = [];
        $broken = [];

        foreach (self::campaigns($tenantId) as $campaign) {
            try {
                $transports[$campaign->channel_key] = Seams::channel($campaign->channel_key);
            } catch (Throwable) {
                $transports[$campaign->channel_key] = null;
                $broken[] = $campaign->channel_key;
            }
        }

        ksort($transports);
        sort($broken);

        self::$broken[$tenantId] = array_values(array_unique($broken));

        return self::$transports[$tenantId] = $transports;
    }

    /**
     * One read of this merchant's attempts, from which every standing figure on
     * the page is derived.
     *
     * @return Collection<int, Attempt>
     */
    private static function rows(string $tenantId): Collection
    {
        return self::$attempts[$tenantId] ??= Attempt::query()->where('tenant_id', $tenantId)->get();
    }
}
