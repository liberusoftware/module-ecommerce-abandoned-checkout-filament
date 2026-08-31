<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\AbandonedCheckout\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Every ability the domain does not publish, forced closed by name. A missing
 * policy method is permissive — Filament falls through to `allow()` — so an
 * ability nobody thought about is open unless it is answered.
 *
 * `canEdit` is closed because an abandonment is a statement about a moment that
 * has passed. Editing its lines or its total would rewrite what was abandoned,
 * and the domain leaves every column the recovery machinery owns out of
 * `$fillable` for the same reason. A campaign is changed by defining it again,
 * which is a domain action and not a form.
 *
 * `canDelete` is closed because a delete here would take an abandonment's
 * attempts and their outcomes with it on the cascade, and an outcome raises from
 * its own `deleting` hook. An ability that fatals is still an ability offered.
 *
 * `canViewAny` and `canView` are not in this list. They are stated on each
 * resource and answered there.
 */
trait DeniesUnpublishedResourceAbilities
{
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }

    public static function canReorder(): bool
    {
        return false;
    }

    public static function canReplicate(Model $record): bool
    {
        return false;
    }

    public static function canRestore(Model $record): bool
    {
        return false;
    }

    public static function canRestoreAny(): bool
    {
        return false;
    }
}
