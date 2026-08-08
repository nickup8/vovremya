<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $master = User::where('is_master', true)->first();

        if (! $master) {
            $this->command->warn('Мастер не найден. Пропуск DemoDataSeeder.');

            return;
        }

        if (! $master->workspace_id) {
            $ws = Workspace::firstOrCreate(
                ['owner_id' => $master->id],
                ['name' => "{$master->name} Studio"]
            );
            $master->update(['workspace_id' => $ws->id]);
            $master->refresh();
        }

        $serviceData = [
            ['title' => 'Стрижка мужская', 'price' => 1500, 'duration' => 30],
            ['title' => 'Окрашивание', 'price' => 3500, 'duration' => 120],
            ['title' => 'Маникюр', 'price' => 2000, 'duration' => 60],
            ['title' => 'Педикюр', 'price' => 2500, 'duration' => 60],
            ['title' => 'Коррекция ресниц', 'price' => 1200, 'duration' => 45],
        ];

        $services = collect();
        foreach ($serviceData as $s) {
            $catalog = ServiceCatalog::firstOrCreate(
                ['workspace_id' => $master->workspace_id, 'title' => $s['title']],
                ['base_price' => $s['price'], 'base_duration' => $s['duration'], 'is_active' => true]
            );
            $ms = MasterService::firstOrCreate(
                ['master_id' => $master->id, 'catalog_id' => $catalog->id],
                ['is_active' => true]
            );
            $ms->setRelation('catalog', $catalog);
            $services->push($ms);
        }

        $clients = $this->createClients($master->id);

        $this->createAppointments($master->id, $clients, $services);

        $this->command->info('Demo-данные созданы:');
        $this->command->info("  Мастер: {$master->name} (ID: {$master->id})");
        $this->command->info('  Услуги: '.MasterService::where('master_id', $master->id)->count().' шт.');
        $this->command->info("  Клиенты: {$clients->count()} шт.");
        $this->command->info('  Визиты: 100 шт. (распределены по времени)');
    }

    private function createClients(string $masterId)
    {
        $existingClients = Client::where('user_id', $masterId)->count();

        if ($existingClients >= 10) {
            return Client::where('user_id', $masterId)->get();
        }

        $clientNames = [
            ['name' => 'Алексей Петров', 'phone' => '+79161234501'],
            ['name' => 'Елена Сидорова', 'phone' => '+79161234502'],
            ['name' => 'Дмитрий Козлов', 'phone' => '+79161234503'],
            ['name' => 'Ольга Новикова', 'phone' => '+79161234504'],
            ['name' => 'Сергей Морозов', 'phone' => '+79161234505'],
            ['name' => 'Анна Волкова', 'phone' => '+79161234506'],
            ['name' => 'Павел Лебедев', 'phone' => '+79161234507'],
            ['name' => 'Наталья Соколова', 'phone' => '+79161234508'],
            ['name' => 'Игорь Попов', 'phone' => '+79161234509'],
            ['name' => 'Марина Федорова', 'phone' => '+79161234510'],
        ];

        $clients = collect();

        foreach ($clientNames as $data) {
            $client = Client::firstOrCreate(
                ['user_id' => $masterId, 'phone' => $data['phone']],
                [
                    'name' => $data['name'],
                    'telegram_id' => 'demo_'.rand(100000, 999999),
                ]
            );
            $clients->push($client);
        }

        return $clients;
    }

    private function createAppointments(string $masterId, $clients, $services): void
    {
        $statuses = ['paid', 'paid', 'paid', 'paid', 'booked', 'booked', 'booked', 'booked', 'cancelled'];
        $clientIds = $clients->pluck('id')->toArray();

        $today = Carbon::today();

        // 2-часовые зазоры — duration=60 не пересекается
        $dailySchedule = [
            ['hour' => 9, 'minute' => 0],
            ['hour' => 11, 'minute' => 0],
            ['hour' => 13, 'minute' => 0],
            ['hour' => 15, 'minute' => 0],
            ['hour' => 17, 'minute' => 0],
            ['hour' => 19, 'minute' => 0],
        ];

        $slots = [];

        // 1) Вчера — 6 слотов (избегаем конфликт с TestDataSeeder на сегодня)
        $yesterday = $today->copy()->subDay();
        foreach ($dailySchedule as $t) {
            $slots[] = $yesterday->copy()->setTime($t['hour'], $t['minute']);
        }

        // 2) Прошлая неделя — по одному слоту на (день, время), начиная со вчерашнего дня
        //    Уникальные комбинации daysBack + timeIndex → без дубликатов
        $weekSlots = [];
        for ($d = 2; $d <= 7; $d++) {
            for ($t = 0; $t < count($dailySchedule); $t++) {
                $weekSlots[] = [$d, $t];
            }
        }
        // Добавляем дополнительные дни, если нужно больше 30
        for ($d = 8; count($weekSlots) < 30; $d++) {
            for ($t = 0; $t < count($dailySchedule) && count($weekSlots) < 30; $t++) {
                $weekSlots[] = [$d, $t];
            }
        }
        foreach (array_slice($weekSlots, 0, 30) as [$daysBack, $timeIdx]) {
            $t = $dailySchedule[$timeIdx];
            $slots[] = $today->copy()->subDays($daysBack)->setTime($t['hour'], $t['minute']);
        }

        // 3) Прошлые месяцы — 40 записей, по одному на (день, время)
        $monthSlots = [];
        for ($d = 9; count($monthSlots) < 40; $d += 2) {
            for ($t = 0; $t < count($dailySchedule) && count($monthSlots) < 40; $t++) {
                $monthSlots[] = [$d, $t];
            }
        }
        foreach ($monthSlots as [$daysBack, $timeIdx]) {
            $t = $dailySchedule[$timeIdx];
            $slots[] = $today->copy()->subDays($daysBack)->setTime($t['hour'], $t['minute']);
        }

        // 4) Старые записи — 20 штук, по одному на (день, время)
        $oldSlots = [];
        for ($d = 61; count($oldSlots) < 20; $d += 15) {
            for ($t = 0; $t < count($dailySchedule) && count($oldSlots) < 20; $t++) {
                $oldSlots[] = [$d, $t];
            }
        }
        foreach ($oldSlots as [$daysBack, $timeIdx]) {
            $t = $dailySchedule[$timeIdx];
            $slots[] = $today->copy()->subDays($daysBack)->setTime($t['hour'], $t['minute']);
        }

        $appointments = [];

        foreach ($slots as $index => $slot) {
            $ms = $services[$index % $services->count()];
            $clientId = $clientIds[array_rand($clientIds)];
            $status = $statuses[array_rand($statuses)];
            $provider = ['telegram', 'max'][array_rand(['telegram', 'max'])];

            $appointments[] = [
                'id' => Str::uuid7()->toString(),
                'master_id' => $masterId,
                'client_id' => $clientId,
                'master_service_id' => $ms->id,
                'service_name' => $ms->catalog->title,
                'price' => $ms->effective_price,
                'duration' => 60,
                'start_time' => $slot,
                'status' => $status,
                'provider' => $provider,
                'created_at' => $slot->copy()->subDays(rand(1, 3)),
                'updated_at' => $slot,
            ];
        }

        // Пакетная вставка для производительности
        $chunks = array_chunk($appointments, 50);

        foreach ($chunks as $chunk) {
            Appointment::insert($chunk);
        }
    }
}
