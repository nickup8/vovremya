# Test Debt — Pre-existing Failures (Not ADR-1)

Эти падения **не связаны с ADR-1** и существовали до изменения `visibleInWidget` scope.
Зарегистрированы как backlog, чинить отдельно.

---

## Категория A: TypeError — `Service` vs `MasterService` (~12 тестов)

**Ошибка:** `BookingService::createAppointment(): Argument #2 ($service) must be of type App\Models\MasterService, App\Models\Service given`

**Причина:** Тесты передают legacy `App\Models\Service` в `createAppointment()` / `getAvailableSlots()`, но сигнатура метода ожидает `App\Models\MasterService`. Это незавершённая миграция Service → MasterService, не связанная с ADR-1.

### Затронутые файлы и методы

| Файл | Метод(ы) | Тип ошибки |
|------|----------|------------|
| `tests/Feature/AppointmentPriceSnapshotTest.php` | `test_snapshot_written_on_creation`, `test_price_immutable_after_service_change`, `test_solo_master_appointment_creation_regression` | TypeError |
| `tests/Unit/AppointmentSnapshotTest.php` | `test_backfill_populates_price_and_duration`, `test_snapshot_is_written_on_creation`, `test_snapshot_does_not_change_when_service_price_changes`, `test_solo_master_creates_appointment_with_snapshot` | TypeError |
| `tests/Feature/AppointmentServiceNameSnapshotTest.php` | `test_service_name_snapshot_written_on_creation`, `test_service_name_immutable_after_service_rename`, `test_solo_master_regression` | TypeError |
| `tests/Feature/BookingRescheduleTest.php` | `test_reschedule_same_master_keeps_master_id`, `test_reschedule_changes_master_id`, `test_reschedule_with_foreign_workspace_throws_403` | TypeError |
| `tests/Feature/SecurityFixesTest.php` | `test_booking_rate_limit_blocks_after_5_requests` ( использует `Service::factory()` + POST `/book/` → 404 вместо 429) | TypeError / 404 |

### Гипотеза

Миграция `Service → MasterService` была начата (модель `MasterService` создана, `BookingService` обновлён), но тесты не были обновлены. Нужно:
1. Заменить `Service::factory()` на `MasterService::create()` в затронутых тестах
2. Либо добавить обратную совместимость в `BookingService`

---

## Категория C: RouteNotFoundException (~3 теста)

**Ошибка:** `RouteNotFoundException` — отсутствующие маршруты

### Затронутые файлы и методы

| Файл | Метод | Тип ошибки |
|------|-------|------------|
| `tests/Feature/Auth/AuthenticationTest.php` | `test_users_can_not_authenticate_with_invalid_password`, `test_users_are_rate_limited` | RouteNotFoundException |
| `tests/Feature/Auth/MagicLoginTest.php` | `test_throttle_blocks_excessive_requests` | RouteNotFoundException |

### Гипотеза

Маршруты аутентификации (login, password reset) были переименованы или удалены, но тесты ссылаются на старые имена роутов (например, `route('login')`). Нужно обновить тесты под текущие имена маршрутов.
