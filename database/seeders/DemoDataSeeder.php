<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
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

        $services = Service::where('user_id', $master->id)->get();

        if ($services->isEmpty()) {
            $services = collect([
                Service::create([
                    'user_id' => $master->id,
                    'title' => 'Стрижка мужская',
                    'price' => 1500.00,
                    'duration_minutes' => 30,
                ]),
                Service::create([
                    'user_id' => $master->id,
                    'title' => 'Окрашивание',
                    'price' => 3500.00,
                    'duration_minutes' => 120,
                ]),
                Service::create([
                    'user_id' => $master->id,
                    'title' => 'Маникюр',
                    'price' => 2000.00,
                    'duration_minutes' => 60,
                ]),
                Service::create([
                    'user_id' => $master->id,
                    'title' => 'Педикюр',
                    'price' => 2500.00,
                    'duration_minutes' => 60,
                ]),
                Service::create([
                    'user_id' => $master->id,
                    'title' => 'Коррекция ресниц',
                    'price' => 1200.00,
                    'duration_minutes' => 45,
                ]),
            ]);
        }

        $clients = $this->createClients($master->id);
        $servicesArray = $services->toArray();

        $this->createAppointments($master->id, $clients, $servicesArray);

        $this->command->info('Demo-данные созданы:');
        $this->command->info("  Мастер: {$master->name} (ID: {$master->id})");
        $this->command->info("  Услуги: {$services->count()} шт.");
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

    private function createAppointments(string $masterId, $clients, array $services): void
    {
        $statuses = ['paid', 'paid', 'paid', 'paid', 'booked', 'booked', 'booked', 'booked', 'cancelled'];
        $clientIds = $clients->pluck('id')->toArray();
        $serviceIds = array_column($services, 'id');
        $serviceDurations = array_column($services, 'duration_minutes');

        $today = Carbon::today();

        $slots = [];

        // 10% today (10 записей) — по часам
        for ($i = 0; $i < 10; $i++) {
            $hour = 9 + $i;
            if ($hour > 19) {
                $hour = 9 + ($i % 11);
            }

            $slots[] = $today->copy()->setTime($hour, 0);
        }

        // 30% this week (30 записей) — понедельник-воскресенье
        for ($i = 0; $i < 30; $i++) {
            $daysAgo = $today->dayOfWeekIso - 1;
            $dayOffset = $i % 7;
            $daysBack = $daysAgo - $dayOffset;

            if ($daysBack < 0) {
                $daysBack += 7;
            }

            $hour = 9 + ($i % 11);
            $slots[] = $today->copy()->subDays($daysBack)->setTime($hour, 0);
        }

        // 40% last months (40 записей) — от 8 до 60 дней назад
        for ($i = 0; $i < 40; $i++) {
            $daysBack = 8 + ($i * 2);
            $hour = 9 + ($i % 11);

            $slots[] = $today->copy()->subDays($daysBack)->setTime($hour, 0);
        }

        // 20% older (20 записей) — от 61 до 365 дней назад
        for ($i = 0; $i < 20; $i++) {
            $daysBack = 61 + ($i * 15);
            $hour = 9 + ($i % 11);

            $slots[] = $today->copy()->subDays($daysBack)->setTime($hour, 0);
        }

        $slots = collect($slots)->shuffle();

        $appointments = [];

        foreach ($slots as $index => $slot) {
            $serviceIndex = $index % count($serviceIds);
            $clientId = $clientIds[array_rand($clientIds)];
            $status = $statuses[array_rand($statuses)];
            $provider = ['telegram', 'max'][array_rand(['telegram', 'max'])];

            $appointments[] = [
                'id' => Str::uuid7()->toString(),
                'master_id' => $masterId,
                'client_id' => $clientId,
                'service_id' => $serviceIds[$serviceIndex],
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
