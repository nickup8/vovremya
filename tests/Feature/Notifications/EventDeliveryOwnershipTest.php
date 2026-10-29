<?php

namespace Tests\Feature\Notifications;

use App\Enums\AppointmentNotificationEventType;
use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Jobs\SendAppointmentNotificationJob;
use App\Models\Appointment;
use App\Models\AppointmentNotificationDelivery;
use App\Models\AppointmentNotificationEvent;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\User;
use App\Services\MaxApiClient;
use App\Services\Notification\AppointmentChannelResolver;
use App\Services\VkApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Дефект 3 (P2): параллельные доставки одного события по разным каналам.
 *
 * Ранний delivered-exit — обычный exists() до claim. Claims независимы
 * по (event, channel). Сценарий:
 * A проходит проверку и начинает отправку MAX.
 * Маршрут меняется.
 * B проходит проверку до записи успеха A, захватывает VK и тоже отправляет.
 *
 * Оба имеют допустимые поканальные claims → одно событие обрабатывается
 * двумя живыми исполнителями. Это отличается от неизбежного дубля при
 * неизвестном результате HTTP.
 *
 * Контракт: владение согласовано на уровне события/выбора маршрута.
 * HTTP выполняется вне DB-транзакции.
 *
 * Детерминированный тест: A удерживается ВНУТРИ HTTP-заглушки, затем
 * меняется маршрут и запускается B. Общий ready/go перед handle() такого
 * пересечения не доказывает.
 */
class EventDeliveryOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private User $master;

    private MasterService $service;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.max.api_url' => 'https://api.max.example',
            'services.max.bot_token' => 'test_max_token',
            'services.vk.bot_token' => 'test_vk_token',
            'services.vk.group_id' => '1',
            'services.vk.app_id' => '1',
        ]);

        Queue::fake();

        $this->travelTo(Carbon::parse('2026-11-10 08:00:00', 'Europe/Moscow'));

        $this->master = User::factory()->master()->create([
            'settings' => ['timezone' => 'Europe/Moscow'],
        ]);
        $this->service = MasterService::factory()->forMaster($this->master)->create([
            'price_override' => 1500,
            'duration_override' => 60,
        ]);
        $this->client = Client::factory()->create([
            'user_id' => $this->master->id,
            'max_id' => 'max-1',
            'vk_id' => '222',
            'vk_messages_allowed' => true,
        ]);
    }

    private function appointment(array $attrs = []): Appointment
    {
        return Appointment::factory()
            ->forMaster($this->master)
            ->forClient($this->client)
            ->withMasterService($this->service)
            ->create(array_merge([
                'status' => AppointmentStatus::Booked,
                'source' => AppointmentSource::Admin,
                'start_time' => Carbon::parse('2026-11-12 10:00:00', 'Europe/Moscow'),
            ], $attrs));
    }

    private function seedEvent(Appointment $appointment): AppointmentNotificationEvent
    {
        return AppointmentNotificationEvent::create([
            'appointment_id' => $appointment->id,
            'event_type' => AppointmentNotificationEventType::CreatedByMaster,
            'event_version' => (int) $appointment->fresh()->notification_version,
            'payload' => [
                'text' => 'Мастер записал вас',
                'client_id' => $appointment->client_id,
            ],
            'dispatched_at' => now(),
        ]);
    }

    /**
     * Resolver с управляемой последовательностью ответов.
     *
     * @param  array<int, string|null>  $answers
     */
    private function sequenceResolver(array $answers): AppointmentChannelResolver
    {
        return new class($answers) extends AppointmentChannelResolver
        {
            /** @param array<int, string|null> $answers */
            public function __construct(private array $answers) {}

            public function resolve(Appointment $appointment): ?string
            {
                if ($this->answers !== []) {
                    return array_shift($this->answers);
                }

                return parent::resolve($appointment);
            }
        };
    }

    /**
     * Запуск job'а с подменой resolver'а в контейнере: deliver()
     * перечитывает канал через app(), а не через аргумент handle().
     *
     * @param  array<int, string|null>  $answers
     */
    private function runJob(
        SendAppointmentNotificationJob $job,
        array $answers,
        MaxApiClient $maxApi,
        VkApiClient $vkApi,
    ): void {
        $resolver = $this->sequenceResolver($answers);
        $this->app->instance(AppointmentChannelResolver::class, $resolver);

        $job->handle($resolver, $maxApi, $vkApi);
    }

    /**
     * Детерминированное пересечение: A удерживается ВНУТРИ HTTP-заглушки
     * MAX; маршрут меняется на VK; B запускается до записи успеха A.
     * B не должен доставить в другой канал.
     */
    public function test_route_change_during_http_blocks_second_executor_on_other_channel(): void
    {
        $appointment = $this->appointment();
        $event = $this->seedEvent($appointment);

        $maxSends = 0;
        $vkSends = 0;

        // Общий VK-мок: B не должен ни разу отправить.
        $vkMock = Mockery::mock(VkApiClient::class);
        $vkMock->shouldNotReceive('sendMessage');
        $vkMock->shouldNotReceive('sendMessageWithKeyboard');

        $maxMock = Mockery::mock(MaxApiClient::class);
        $maxMock->shouldReceive('sendMessage')
            ->once()
            ->andReturnUsing(function () use ($event, $vkMock, &$maxSends, &$vkSends) {
                $maxSends++;

                // Маршрут сменился, пока A внутри HTTP, до recordDelivery.
                $this->client->update(['max_id' => null, 'vk_id' => '333']);

                // B: пытается доставить то же событие в новый канал VK.
                // MaxApiClient для B — отдельный мок, не затирающий контейнер.
                $bMaxMock = Mockery::mock(MaxApiClient::class);
                $bMaxMock->shouldNotReceive('sendMessage');

                $this->runJob(
                    new SendAppointmentNotificationJob($event->id),
                    ['vk', 'vk'],
                    $bMaxMock,
                    $vkMock,
                );

                $vkSends = AppointmentNotificationDelivery::query()
                    ->where('appointment_notification_event_id', $event->id)
                    ->where('channel', 'vk')
                    ->where('status', AppointmentNotificationDelivery::STATUS_DELIVERED)
                    ->count();

                return 'mid-a';
            });

        $this->runJob(
            new SendAppointmentNotificationJob($event->id),
            ['max', 'max'],
            $maxMock,
            $vkMock,
        );

        $this->assertSame(1, $maxSends, 'A должен доставить ровно один раз');
        $this->assertSame(0, $vkSends, 'B не должен доставить в другой канал, пока A держит событие');

        $delivered = AppointmentNotificationDelivery::query()
            ->where('appointment_notification_event_id', $event->id)
            ->where('status', AppointmentNotificationDelivery::STATUS_DELIVERED)
            ->get();
        $this->assertCount(1, $delivered, 'ровно одна доставленная строка на событие');
        $this->assertSame('max', $delivered->first()->channel);
        $this->assertSame('mid-a', $delivered->first()->provider_message_id);
    }

    /**
     * A держит живой claim MAX (как внутри HTTP). B стартует и пытается
     * claim'нуть VK. Согласование на уровне события: чужой живой claim
     * на ДРУГОМ канале блокирует новый проход.
     */
    public function test_live_claim_on_one_channel_blocks_claim_on_another(): void
    {
        $appointment = $this->appointment();
        $event = $this->seedEvent($appointment);

        // A держит max (живой lease, как внутри HTTP).
        $aToken = Str::uuid();
        AppointmentNotificationDelivery::create([
            'appointment_notification_event_id' => $event->id,
            'channel' => 'max',
            'status' => AppointmentNotificationDelivery::STATUS_PENDING,
            'claim_token' => (string) $aToken,
            'lease_expires_at' => now()->addSeconds(60),
        ]);

        $vkMock = Mockery::mock(VkApiClient::class);
        $vkMock->shouldNotReceive('sendMessage');
        $vkMock->shouldNotReceive('sendMessageWithKeyboard');

        $bMaxMock = Mockery::mock(MaxApiClient::class);
        $bMaxMock->shouldNotReceive('sendMessage');

        $this->runJob(
            new SendAppointmentNotificationJob($event->id),
            ['vk', 'vk'],
            $bMaxMock,
            $vkMock,
        );

        $vkRow = AppointmentNotificationDelivery::query()
            ->where('appointment_notification_event_id', $event->id)
            ->where('channel', 'vk')
            ->first();

        if ($vkRow !== null) {
            $this->assertNotSame(
                AppointmentNotificationDelivery::STATUS_DELIVERED,
                $vkRow->status,
                'B не должен доставить vk, пока max удерживается живым claim\'ом',
            );
        }

        $deliveredCount = AppointmentNotificationDelivery::query()
            ->where('appointment_notification_event_id', $event->id)
            ->where('status', AppointmentNotificationDelivery::STATUS_DELIVERED)
            ->count();
        $this->assertSame(0, $deliveredCount, 'ни одна доставка не должна пройти при живом чужом claim');
    }

    /**
     * B прошёл раннюю delivered-проверку в handle() → A записал delivered
     * MAX → B продолжает claim VK. Повторного HTTP быть не должно:
     * проверка delivered по ЛЮБОМУ каналу внутри claim-транзакции
     * возвращает терминальный исход без нового claim'а и без сети.
     */
    public function test_vk_claim_after_delivered_max_performs_no_http(): void
    {
        $appointment = $this->appointment();
        $event = $this->seedEvent($appointment);

        $maxSends = 0;
        $maxMock = Mockery::mock(MaxApiClient::class);
        $maxMock->shouldReceive('sendMessage')
            ->once()
            ->andReturnUsing(function () use (&$maxSends) {
                $maxSends++;

                return 'mid-a';
            });

        // B не должен слать ни max, ни vk.
        $bMaxMock = Mockery::mock(MaxApiClient::class);
        $bMaxMock->shouldNotReceive('sendMessage');

        $vkMock = Mockery::mock(VkApiClient::class);
        $vkMock->shouldNotReceive('sendMessage');
        $vkMock->shouldNotReceive('sendMessageWithKeyboard');

        // Resolver B: первый вызов — ПОСЛЕ ранней проверки handle() и ДО
        // claim. В этой точке A полностью доставляет MAX (delivered
        // записан), затем B продолжает с каналом vk.
        $runA = function () use ($event, $maxMock): void {
            $this->runJob(
                new SendAppointmentNotificationJob($event->id),
                ['max', 'max'],
                $maxMock,
                Mockery::mock(VkApiClient::class),
            );
        };

        $bResolver = new class($runA) extends AppointmentChannelResolver
        {
            private bool $fired = false;

            public function __construct(private \Closure $runA) {}

            public function resolve(Appointment $appointment): ?string
            {
                if (! $this->fired) {
                    $this->fired = true;
                    ($this->runA)();

                    // A перепривязал свой resolver в контейнер —
                    // возвращаем резолвер B для перечитывания канала
                    // внутри deliver(), если claim дошёл до route.
                    app()->instance(AppointmentChannelResolver::class, $this);
                }

                return self::CHANNEL_VK;
            }
        };

        (new SendAppointmentNotificationJob($event->id))
            ->handle($bResolver, $bMaxMock, $vkMock);

        $this->assertSame(1, $maxSends, 'A должен доставить max ровно один раз');

        $delivered = AppointmentNotificationDelivery::query()
            ->where('appointment_notification_event_id', $event->id)
            ->where('status', AppointmentNotificationDelivery::STATUS_DELIVERED)
            ->get();
        $this->assertCount(1, $delivered, 'ровно одна доставленная строка на событие');
        $this->assertSame('max', $delivered->first()->channel);

        $vkRow = AppointmentNotificationDelivery::query()
            ->where('appointment_notification_event_id', $event->id)
            ->where('channel', 'vk')
            ->first();
        $this->assertNull($vkRow, 'claim VK не должен создаваться после подтверждённой доставки');
    }

    /**
     * Уже доставленное событие: повторный проход не уходит в другой канал.
     * (Существующий контракт, охраняемый regression-тестом.)
     */
    public function test_delivered_event_is_not_resent_after_route_change(): void
    {
        $appointment = $this->appointment();
        $event = $this->seedEvent($appointment);

        $maxMock = Mockery::mock(MaxApiClient::class);
        $maxMock->shouldReceive('sendMessage')->once()->andReturn('mid-max');

        $this->runJob(
            new SendAppointmentNotificationJob($event->id),
            ['max', 'max'],
            $maxMock,
            Mockery::mock(VkApiClient::class),
        );

        $this->client->update(['max_id' => null, 'vk_id' => '333']);

        $vkMock = Mockery::mock(VkApiClient::class);
        $vkMock->shouldNotReceive('sendMessage');
        $vkMock->shouldNotReceive('sendMessageWithKeyboard');

        $this->runJob(
            new SendAppointmentNotificationJob($event->id),
            ['vk', 'vk'],
            Mockery::mock(MaxApiClient::class),
            $vkMock,
        );

        $delivered = AppointmentNotificationDelivery::query()
            ->where('appointment_notification_event_id', $event->id)
            ->where('status', AppointmentNotificationDelivery::STATUS_DELIVERED)
            ->count();
        $this->assertSame(1, $delivered);
    }

    /**
     * Смена канала внутри одного исполнителя (release max → claim vk)
     * не блокируется собственным освобождённым claim'ом.
     */
    public function test_own_channel_switch_releases_then_claims_new_channel(): void
    {
        $appointment = $this->appointment();
        $event = $this->seedEvent($appointment);

        $vkSends = 0;
        $vkMock = Mockery::mock(VkApiClient::class);
        $vkMock->shouldReceive('sendMessage')
            ->once()
            ->with('222', Mockery::type('string'))
            ->andReturnUsing(function () use (&$vkSends) {
                $vkSends++;

                return 'mid-vk';
            });

        $maxMock = Mockery::mock(MaxApiClient::class);
        $maxMock->shouldNotReceive('sendMessage');

        // A: резолвит max → claim max → route перечитывает → vk.
        $this->runJob(
            new SendAppointmentNotificationJob($event->id),
            ['max', 'vk'],
            $maxMock,
            $vkMock,
        );

        $this->assertSame(1, $vkSends, 'смена канала внутри одного исполнителя должна доставить');

        $vkRow = AppointmentNotificationDelivery::query()
            ->where('appointment_notification_event_id', $event->id)
            ->where('channel', 'vk')
            ->firstOrFail();
        $this->assertSame(AppointmentNotificationDelivery::STATUS_DELIVERED, $vkRow->status);

        // Исходный max claim должен быть освобождён, не удержан.
        $maxRow = AppointmentNotificationDelivery::query()
            ->where('appointment_notification_event_id', $event->id)
            ->where('channel', 'max')
            ->first();
        if ($maxRow !== null) {
            $this->assertNull($maxRow->claim_token);
        }
    }
}
