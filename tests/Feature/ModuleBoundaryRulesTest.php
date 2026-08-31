<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Liberu\Ecommerce\AbandonedCheckout\Filament\AbandonedCheckoutPlugin;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Pages\Outreach;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Resources\Abandonments\AbandonmentResource;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Resources\Campaigns\CampaignResource;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Widgets\DueNow;
use Liberu\Ecommerce\AbandonedCheckout\Filament\Widgets\OutreachStanding;

/*
 * The rules that are this package's rather than the fleet's. The shared boundary
 * suite in `package-testbench` asserts the manifest, the required files, the
 * absence of `App\` and the panel plugins.
 */

function packageRoot(): string
{
    return dirname(__DIR__, 2);
}

/** @return array<int, string> every PHP file under src/, absolute. */
function sourceFiles(): array
{
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(packageRoot().'/src')) as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

/**
 * Source with comments stripped. Every rule below is about what this package
 * does, and a docblock naming the host fault it refuses to repeat is the text
 * most likely to trip a naive grep.
 */
function sourceCode(string $path): string
{
    $code = '';

    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
}

/** @return array<string, mixed> */
function packageJson(string $file): array
{
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((string) file_get_contents(packageRoot().'/'.$file), true, 512, JSON_THROW_ON_ERROR);

    return $decoded;
}

it('does no arithmetic on a money amount, in any form', function (): void {
    // The domain carries the exponent beside the amount, so `Render::money()`
    // places the digits by string and needs no arithmetic at all. Dividing by a
    // hundred is right for GBP and wrong for JPY and KWD, and the host's own
    // widget hard-coded `->money('USD')` on every order it drew.
    foreach (sourceFiles() as $file) {
        $source = sourceCode($file);

        foreach (['->money(', 'number_format', 'intdiv', 'floatval', '(float)', 'round(', 'ceil(', 'floor(', 'bcdiv'] as $forbidden) {
            expect($source)->not->toContain($forbidden);
        }

        // No division at all: a quotient is a float, and a float is what the
        // domain refuses.
        expect($source)->not->toMatch('#[^/*\s]\s*/\s*\$#');
    }
});

it('registers everything through the plugin and nothing from the service provider', function (): void {
    // A provider that registered widgets would put one merchant's baskets on
    // whatever panel happened to boot.
    $provider = (string) file_get_contents(packageRoot().'/src/AbandonedCheckoutFilamentServiceProvider.php');

    expect($provider)->not->toContain('Resource::class')
        ->and($provider)->not->toContain('->resources(')
        ->and($provider)->not->toContain('->pages(')
        ->and($provider)->not->toContain('->widgets(');

    $plugin = (string) file_get_contents(packageRoot().'/src/AbandonedCheckoutPlugin.php');

    foreach (['AbandonmentResource::class', 'CampaignResource::class', 'Outreach::class',
        'OutreachStanding::class', 'DueNow::class'] as $registered) {
        expect($plugin)->toContain($registered);
    }
});

it('declares every class the manifest names for the panel', function (): void {
    $manifest = packageJson('module.json');

    expect($manifest['presentation']['filament']['app'])->toBe([AbandonedCheckoutPlugin::class]);

    foreach ($manifest['presentation']['filament'] as $plugins) {
        foreach ($plugins as $plugin) {
            expect(class_exists($plugin))->toBeTrue();
        }
    }

    foreach ([AbandonmentResource::class, CampaignResource::class, Outreach::class,
        OutreachStanding::class, DueNow::class] as $class) {
        expect(class_exists($class))->toBeTrue();
    }
});

it('answers canView on every widget, which Filament leaves open by default', function (): void {
    // A widget is the surface the panel's own tenancy does not reach.
    foreach ([packageRoot().'/src/Widgets/OutreachStanding.php', packageRoot().'/src/Widgets/DueNow.php'] as $widget) {
        expect(sourceCode($widget))
            ->toContain('public static function canView(): bool')
            ->toContain('PanelTenant::resolvable()')
            // And every read it makes says which merchant it is for.
            ->toContain('PanelTenant::current()');
    }
});

it('guards no action with visible, so the domain’s own refusal stays reachable', function (): void {
    // Filament re-evaluates `visible()` against a freshly hydrated record at
    // mount and again at `callMountedAction`, so a guard restating "this is
    // already stopped" would make the domain's branch unreachable — and an
    // unreachable branch is unreachable against a 100% bar. The guards are
    // dropped and the branches stay. The one `visible()` left is a form field
    // that appears with the condition field it belongs to, which is shape and
    // not a rule.
    $guarded = [];

    foreach (sourceFiles() as $file) {
        $source = sourceCode($file);

        if (str_contains($source, '->visible(') || str_contains($source, '->hidden(')) {
            $guarded[] = basename($file);
        }
    }

    expect($guarded)->toBe(['ListCampaigns.php'])
        ->and(sourceCode(packageRoot().'/src/Resources/Abandonments/Pages/ViewAbandonment.php'))
        ->not->toContain('->visible(');
});

it('ships no Filament create, edit or delete control of any kind', function (): void {
    // An abandonment cascades to its attempts and their outcomes, and an outcome
    // raises from its own `deleting` hook, so a delete offered here would fatal
    // rather than refuse — and an ability that fatals was still one offered.
    $forbidden = [
        'CreateAction', 'EditAction', 'DeleteAction', 'DeleteBulkAction', 'ForceDeleteAction',
        'RestoreAction', 'ReplicateAction', 'AssociateAction', 'DissociateAction',
        'AttachAction', 'DetachAction', 'CreateRecord', 'EditRecord', 'ManageRecords',
    ];

    foreach (sourceFiles() as $file) {
        $source = sourceCode($file);

        foreach ($forbidden as $control) {
            expect($source)->not->toContain($control);
        }
    }
});

it('writes nothing through Eloquent, because every write here is a published domain action', function (): void {
    foreach (sourceFiles() as $file) {
        $source = sourceCode($file);

        expect($source)->not->toContain('->save()')
            ->and($source)->not->toContain('->update(')
            ->and($source)->not->toContain('->delete()')
            ->and($source)->not->toContain('->forceFill(')
            ->and($source)->not->toContain('->increment(')
            ->and($source)->not->toContain('->decrement(')
            ->and($source)->not->toContain('::create(');
    }
});

it('never states a state, a channel key or a table name of its own', function (): void {
    // A `where('state', 'sent')` here would be this panel deciding what a sent
    // attempt is. The enums decide, and every comparison in this package is
    // against a case rather than against a string it chose. A channel key is a
    // host's to name, so naming one here would be this package enumerating the
    // transports a host may have.
    foreach (sourceFiles() as $file) {
        $source = sourceCode($file);

        foreach ([
            "'open'", "'recovered'", "'stopped'", "'expired'", "'pending'", "'sent'",
            "'refused'", "'delivered'", "'engaged'", "'converted'", "'email'", "'sms'",
            'abandoned_checkout_',
        ] as $forbidden) {
            expect($source)->not->toContain($forbidden);
        }

        expect($source)->not->toMatch("/->where\\('(state|refusal_reason|stop_reason|kind|channel_key)', '/");
    }
});

it('takes every option on every select and filter from a domain enum', function (): void {
    foreach ([
        'src/Resources/Abandonments/AbandonmentResource.php',
        'src/Resources/Campaigns/Pages/ListCampaigns.php',
    ] as $source) {
        expect(sourceCode(packageRoot().'/'.$source))->toContain('::cases()');
    }
});

it('never puts a contact or a subject reference on a listing', function (): void {
    // Wave 11 shipped reviewer PII on a public listing. Both references are on
    // the record and neither is on the index.
    foreach (sourceFiles() as $file) {
        expect(sourceCode($file))->not->toContain("TextColumn::make('subject_ref')")
            ->and(sourceCode($file))->not->toContain("TextColumn::make('contact_ref')");
    }

    $resource = sourceCode(packageRoot().'/src/Resources/Abandonments/AbandonmentResource.php');

    expect($resource)->toContain("TextEntry::make('subject_ref')")
        ->and($resource)->toContain("TextEntry::make('contact_ref')");
});

it('offers neither of the two controls that span every merchant', function (): void {
    // Erasure and export both say in their names that they cross tenants. A
    // screen scoped to one merchant must not carry a control that acts on all.
    foreach (sourceFiles() as $file) {
        expect(sourceCode($file))->not->toContain('AcrossTenants');
    }
});

it('offers no control that records an outcome or mints an abandonment', function (): void {
    // An outcome is an observation and a panel button is not one: a merchant
    // clicking "delivered" on a message nobody can see the fate of would be this
    // surface inventing the fact the module exists to keep honest. Recording an
    // abandonment belongs to whatever owns the checkout. Both are named in
    // prose on the screens that tell an operator what is missing, so the rule is
    // about reaching them rather than about the word.
    foreach (sourceFiles() as $file) {
        expect(sourceCode($file))->not->toContain('Actions\\RecordOutcome')
            ->and(sourceCode($file))->not->toContain('Actions\\RecordAbandonment');
    }
});

it('reaches for no framework-foundation helper', function (): void {
    // `config()`, `app()`, `auth()`, `now()` and `view()` live in
    // `laravel/framework`, not in `illuminate/support`. They pass CI only
    // because the testbench drags the framework in.
    foreach (sourceFiles() as $file) {
        $source = sourceCode($file);

        // The lookbehind excludes `:` so `CarbonImmutable::now()` — which is
        // `nesbot/carbon` and reachable — is not mistaken for the helper.
        expect($source)->not->toMatch('/(?<![\w>$:])config\(/')
            ->and($source)->not->toMatch('/(?<![\w>$:])app\(/')
            ->and($source)->not->toMatch('/(?<![\w>$:])auth\(/')
            ->and($source)->not->toMatch('/(?<![\w>$:])now\(/')
            ->and($source)->not->toMatch('/(?<![\w>$:])view\(/')
            ->and($source)->not->toMatch('/(?<![\w>$:])session\(/')
            ->and($source)->not->toMatch('/(?<![\w>$:])response\(/');
    }
});

it('reads the clock and counts rows in one place, because the domain publishes no query for either', function (): void {
    // The domain answers `ListCampaigns`, `ListAttempts` and
    // `ListDueAbandonments` and nothing that aggregates attempts or counts
    // abandonments. Every count this panel needs and the one clock it reads live
    // in `Support\Snapshot`, so the gap is one file wide and `docs/panel.md`
    // records it.
    $clocks = [];
    $counts = [];

    foreach (sourceFiles() as $file) {
        $source = sourceCode($file);
        $name = basename($file);

        if (str_contains($source, 'CarbonImmutable::now()') || str_contains($source, 'Carbon::now()')) {
            $clocks[] = $name;
        }

        $where = substr_count($source, '->where(');

        if ($where > 0) {
            $counts[$name] = $where;
        }
    }

    ksort($counts);

    expect($clocks)->toBe(['Snapshot.php'])
        ->and($counts)->toBe([
            'AbandonmentResource.php' => 1,
            'CampaignResource.php' => 1,
            'DueNow.php' => 1,
            'Snapshot.php' => 2,
        ]);
});

it('states its Filament floor as the true one', function (): void {
    // Every tag from v5.4.0 declares `illuminate/contracts: ^11.28|^12.0|^13.0`.
    expect(packageJson('composer.json')['require']['filament/filament'])->toBe('^5.4');
});

it('lists the sibling packages the manifest declares and no others', function (): void {
    $composer = packageJson('composer.json');
    $manifest = packageJson('module.json');

    $siblings = array_filter(
        $composer['require'],
        fn (string $constraint, string $package): bool => str_starts_with($package, 'liberusoftware/'),
        ARRAY_FILTER_USE_BOTH,
    );

    expect($siblings)->toBe($manifest['requires']['packages'])
        ->and($siblings)->toBe(['liberusoftware/ecommerce-abandoned-checkout' => '^0.1']);
});

it('points at the domain repository, because it is not on Packagist yet', function (): void {
    expect(packageJson('composer.json')['repositories'])->toBe([[
        'type' => 'vcs',
        'url' => 'https://github.com/liberusoftware/module-ecommerce-abandoned-checkout',
    ]]);
});

it('agrees with itself about its own version', function (): void {
    expect(packageJson('composer.json')['version'])->toBe(packageJson('module.json')['version']);
});

it('carries no session identifier in any file', function (): void {
    $files = array_merge(sourceFiles(), [
        packageRoot().'/README.md',
        packageRoot().'/CHANGELOG.md',
        packageRoot().'/module.json',
        packageRoot().'/docs/panel.md',
        packageRoot().'/docs/adoption.md',
        packageRoot().'/docs/runbook.md',
    ]);

    foreach ($files as $file) {
        $source = (string) file_get_contents($file);

        // Split so this assertion is not itself the thing it forbids: a
        // repository-wide grep for the literals has to come back empty.
        expect($source)->not->toContain('claude'.'.ai')
            ->and($source)->not->toContain('Claude-'.'Session');
    }
});

it('contributes nothing when a panel boots it, so nothing can arrive on a panel that did not register it', function (): void {
    $plugin = AbandonedCheckoutPlugin::make();
    $plugin->boot(Filament::getPanel('app'));

    expect($plugin->getId())->toBe('ecommerce-abandoned-checkout');
});
