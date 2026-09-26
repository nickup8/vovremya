<?php

namespace App\Support;

/**
 * Константы дефолтного (fallback) тарифа "Старт".
 *
 * Используются когда у workspace нет подписки или tariffPlan.
 * null означает "безлимит" (PHP_INT_MAX на уровне логики).
 */
final class PlanDefaults
{
    /** Максимум записей в месяц для тарифа Старт */
    public const START_MAX_APPOINTMENTS = 30;

    /** Максимум мастеров для тарифа Старт */
    public const START_MAX_MASTERS = 1;

    /** Фичи тарифа Старт */
    public const START_FEATURES = ['calendar', 'basic_client_management'];

    /**
     * Семантика безлимита.
     * В БД null означает "без ограничений".
     * На уровне PHP используется PHP_INT_MAX.
     */
    public const UNLIMITED = null;

    /**
     * Текущая продуктовая линейка — единственный source of truth
     * для billing page и checkout allowlist.
     *
     * studio/salon deprecated: checkout для них закрыт,
     * historical subscriptions/cycles/plans остаются в БД.
     */
    public const CHECKOUT_ALLOWED_CODES = ['pro'];

    /** Все коды, отображаемые на customer billing page */
    public const BILLING_PAGE_CODES = ['start', 'pro'];
}
