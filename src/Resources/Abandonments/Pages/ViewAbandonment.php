<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\AbandonedCheckout\Filament\Resources\Abandonments\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\App;
use Liberu\Ecommerce\AbandonedCheckout\Actions\AttemptRecovery;
use Liberu\Ecommerce\AbandonedCheckout\Actions\StopOutreach;
use Liberu\Ecommerce\AbandonedCheckout\Data\AttemptReport;
use Liberu\Ecommerce\AbandonedCheckout\Enums\RefusalReason;
use Liberu\Ecommerce\AbandonedCheckout\Enums\StopReason;
use Liberu\Ecommerce\AbandonedCheckout\Exceptions\OutreachAlreadyStopped;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Resources\Abandonments\AbandonmentResource;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Support\Apply;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Support\PanelTenant;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Support\Render;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Support\Snapshot;
use Liberu\Ecommerce\AbandonedCheckout\Models\Abandonment;
use Liberu\Ecommerce\AbandonedCheckout\Models\Campaign;

/**
 * One abandonment, and the two things a merchant can do about it.
 *
 * Neither action carries a `visible()` guard. Filament re-evaluates one against
 * a freshly hydrated record at mount and again at `callMountedAction`, so a
 * guard restating "this is already stopped" or "this has expired" would make the
 * domain's own refusal unreachable — and a rule the panel restates is a rule
 * written twice, which is the host fault this module was extracted over. The
 * guards are dropped; the branches stay.
 */
final class ViewAbandonment extends ViewRecord
{
    protected static string $resource = AbandonmentResource::class;

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('attempt')
                ->label('Try to reach them')
                ->icon('heroicon-o-paper-airplane')
                ->modalHeading('Make one attempt under a campaign')
                ->modalDescription('Every way this can fail to send is a row with a reason on it. Nothing bound to the channel key, nothing bound to build the link, the cap already spent, the quiet period still running, a condition unmet — each is a refusal you can read, and none of them is a message that quietly did not go.')
                ->modalSubmitActionLabel('Attempt it')
                ->schema([
                    Select::make('campaign')
                        ->label('Campaign')
                        ->required()
                        ->options(fn (): array => self::campaignOptions())
                        ->helperText('The campaign holds the cap, the quiet period and the delay. The host hard-coded all three, twice, in two languages.'),
                ])
                ->action(function (Abandonment $record, array $data): void {
                    /** @var array{campaign: string} $data */
                    // The tenant, the checkout reference and the instant this
                    // page read once. No action in the domain calls now().
                    $report = App::make(AttemptRecovery::class)(
                        PanelTenant::current(),
                        $record->checkout_ref,
                        (int) $data['campaign'],
                        Snapshot::asOf(),
                    );

                    Snapshot::forget();

                    Apply::say(
                        $report->wentOut() ? 'It went' : 'It did not go, and here is why',
                        self::sentence($report),
                        $report->wentOut() ? 'success' : 'warning',
                    );
                }),

            Action::make('stop')
                ->label('Stop reaching out')
                ->icon('heroicon-o-hand-raised')
                ->color('warning')
                ->modalHeading('Stop every campaign reaching this abandonment')
                ->modalDescription('A dated fact, opened once. Stopping again for the same reason is the same fact; stopping for a different one would overwrite why, so the domain refuses instead.')
                ->modalSubmitActionLabel('Stop it')
                ->schema([
                    Select::make('reason')
                        ->label('Because')
                        ->required()
                        ->options(fn (): array => self::stopOptions()),
                ])
                ->action(function (Abandonment $record, array $data): void {
                    /** @var array{reason: string} $data */
                    try {
                        $stopped = App::make(StopOutreach::class)(
                            PanelTenant::current(),
                            $record->checkout_ref,
                            StopReason::from($data['reason']),
                            Snapshot::asOf(),
                        );
                    } catch (OutreachAlreadyStopped $already) {
                        Snapshot::forget();

                        Apply::say('Nothing changed', $already->getMessage(), 'danger');

                        return;
                    }

                    Snapshot::forget();

                    Apply::say(
                        'Stopped',
                        'Outreach on '.$stopped->checkout_ref.' is stopped: '
                            .Render::stopLabel(StopReason::from($data['reason'])).'. No campaign reaches it again.',
                        'success',
                    );
                }),
        ];
    }

    /**
     * The campaigns this merchant has, named with the channel each would use and
     * whether anything is bound to it.
     *
     * @return array<int, string>
     */
    public static function campaignOptions(): array
    {
        $options = [];
        $channels = Snapshot::channels(PanelTenant::current());

        foreach (Snapshot::campaigns(PanelTenant::current()) as $campaign) {
            $options[$campaign->id] = $campaign->name.' — '.self::channelLabel($campaign, $channels);
        }

        return $options;
    }

    /**
     * Erasure is not a merchant's button: it belongs to the cross-tenant path
     * this panel does not carry.
     *
     * @return array<string, string>
     */
    public static function stopOptions(): array
    {
        return [
            StopReason::Purchased->value => Render::stopLabel(StopReason::Purchased),
            StopReason::OptedOut->value => Render::stopLabel(StopReason::OptedOut),
        ];
    }

    /** @param  array<string, bool>  $channels */
    private static function channelLabel(Campaign $campaign, array $channels): string
    {
        return ($channels[$campaign->channel_key] ?? false)
            ? $campaign->channel_key.', bound'
            : $campaign->channel_key.', nothing bound';
    }

    /** What the attempt did, never a bare "done". */
    private static function sentence(AttemptReport $report): string
    {
        if ($report->refusal instanceof RefusalReason) {
            return 'Attempt '.$report->attemptId.' on '.$report->channelKey.' was refused, because '
                .Render::refusal($report->refusal).'.';
        }

        return 'Attempt '.$report->attemptId.' went out on '.$report->channelKey
            .', and the channel answered with the reference '.((string) $report->providerRef)
            .'. What became of it is a separate observation, which only a channel that reports back can make.';
    }
}
