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
}
