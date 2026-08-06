# Test Debt — Pre-existing Failures (Not ADR-1)

Эти падения **не связаны с ADR-1** и существовали до изменения `visibleInWidget` scope.
Зарегистрированы как backlog, чинить отдельно.

---

## Категория A: ADR-5 / миграция M6 — отвязка appointments от legacy services (~36 тестов)

**ADR-5 / миграция M6:** отвязка appointments.service_id от legacy services (FK RESTRICT→SET NULL, фактура в снапшотах service_name/price/duration). Приоритет P0 по ТЗ, до Разделов 3/4. **НЕ связано с ADR-1.**

Два подтипа ошибок, оба от незавершённой миграции Service → MasterService:

### A1: TypeError — `Service` vs `MasterService` (24 теста)

**Ошибка:** `BookingService::createAppointment(): Argument #2 ($service) must be of type App\Models\MasterService, App\Models\Service given` (аналогично для `getAvailableSlots`)

**Причина:** Тесты передают legacy `App\Models\Service` в `createAppointment()` / `getAvailableSlots()`, но сигнатура метода ожидает `App\Models\MasterService`.

| Файл | Метод(ы) | Тип |
|------|----------|-----|
| `tests/Unit/AppointmentSnapshotTest.php` | `backfill populates price and duration`, `snapshot is written on creation`, `snapshot does not change when service price changes`, `solo master creates appointment with snapshot` | TypeError |
| `tests/Feature/AppointmentPriceSnapshotTest.php` | `snapshot written on creation`, `price immutable after service change`, `solo master appointment creation regression` | TypeError |
| `tests/Feature/AppointmentServiceNameSnapshotTest.php` | `service name snapshot written on creation`, `service name immutable after service rename`, `solo master regression` | TypeError |
| `tests/Feature/BookingRescheduleTest.php` | `reschedule same master keeps master id`, `reschedule changes master id`, `reschedule with foreign workspace throws 403` | TypeError |
| `tests/Feature/BookingTimezoneTest.php` | `it stores appointment time in utc`, `it stores rescheduled time in utc` | TypeError |
| `tests/Feature/CalendarSnapshotDisplayTest.php` | `calendar shows snapshot not live service name`, `calendar shows snapshot price not live`, `calendar fallback when snapshot null` | TypeError |
| `tests/Feature/SlotTimezoneTest.php` | `first slot is 09 for moscow master`, `first slot is 09 for utc master`, `past slots are filtered by master timezone`, `blocked time affects slots`, `break affects slots` | TypeError |
| `tests/Feature/SecurityFixesTest.php` | `booking rate limit blocks after 5 requests` (Service::factory + POST → 404 вместо 429) | TypeError |

### A2: QueryException — `catalog_id` NOT NULL violation (9 тестов)

**Ошибка:** `SQLSTATE[23502]: Not null violation: значение NULL в столбце "catalog_id" отношения "master_service" нарушает ограничение NOT NULL`

**Причина:** Тесты создают `MasterService` с `catalog_id: null`, но миграция `2026_07_31_000003` задала `catalog_id` как NOT NULL FK. Тесты писались до этой миграции.

| Файл | Метод(ы) | Тип |
|------|----------|-----|
| `tests/Feature/BookingFlowStatusTest.php` | `prepayment custom creates pending payment status`, `free verification creates booked status`, `default booking flow type creates booked status`, `explicit status overrides auto detection`, `paid as initial status throws`, `cancelled as initial status throws` | QueryException |
| `tests/Feature/BookingSlotTest.php` | `multi day blocked time blocks day after start`, `multi day blocked time frees after end`, `get available slots excludes multi day blocked times` | QueryException |
| `tests/Feature/MasterServiceTableTest.php` | `can create link with override`, `nullable override defaults`, `unique master service` | QueryException |

### A3: Каскадные падения (не отдельные баги)

Следующие тесты проходят изолированно, но падают в полном прогоне из-за каскада от A1/A2 (предыдущий тест портит БД, `RefreshDatabase` не откатывает):

| Файл | Примечание |
|------|------------|
| `tests/Feature/Auth/MagicLoginTest.php` | Все тесты проходят изолированно. Каскад от BookingFlowStatusTest. |
| `tests/Feature/SecurityAndLogicBreakTest.php` | `calendar controller does not generate...` — каскад. |

### Гипотеза

Миграция `Service → MasterService` (ADR-5/M6) была начата: модель `MasterService` создана, `BookingService` обновлён (сигнатуры на `MasterService`), но тесты не обновлены. Нужно:
1. Заменить `Service::factory()` на `MasterService::create()` в тестах A1
2. Заменить `catalog_id: null` на реальный `ServiceCatalog` в тестах A2
3. Либо сделать `catalog_id` nullable в миграции (если бизнес-логика допускает)

---

## Категория C: RouteNotFoundException (2 теста)

**Ошибка:** `RouteNotFoundException` — отсутствующие маршруты

| Файл | Метод | Тип |
|------|-------|-----|
| `tests/Feature/Auth/AuthenticationTest.php` | `users can not authenticate with invalid password`, `users are rate limited` | RouteNotFoundException |

### Гипотеза

Маршруты аутентификации (login) были переименованы или удалены, но тесты ссылаются на старые имена роутов. Нужно обновить тесты под текущие имена маршрутов.
