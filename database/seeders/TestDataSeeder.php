<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class TestDataSeeder extends Seeder
{
    public function run(): void
    {
        Appointment::query()->delete();
        Client::query()->delete();
        Service::query()->delete();
        User::query()->delete();

        $master = User::create([
            'name' => 'Анна Мастерова',
            'email' => 'test-master@vovremia.local',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
            'phone' => '+79001234567',
            'is_master' => true,
            'master_slug' => 'test-master',
            'specialty' => 'Маникюр & Педикюр',
            'address' => 'г. Городец, ул. Ленина, д. 10',
            'telegram_notifications' => true,
            'max_notifications' => true,
            'soft_deposit' => true,
            'deposit_timeout' => 15,
            'deposit_percent' => 20,
        ]);

        $services = [
            Service::create([
                'user_id' => $master->id,
                'title' => 'Маникюр с покрытием',
                'price' => 1800.00,
                'duration_minutes' => 60,
            ]),
            Service::create([
                'user_id' => $master->id,
                'title' => 'Педикюр',
                'price' => 2200.00,
                'duration_minutes' => 90,
            ]),
            Service::create([
                'user_id' => $master->id,
                'title' => 'Снятие + выравнивание',
                'price' => 800.00,
                'duration_minutes' => 30,
            ]),
            Service::create([
                'user_id' => $master->id,
                'title' => 'Дизайн ногтей',
                'price' => 500.00,
                'duration_minutes' => 120,
            ]),
        ];

        $client = Client::create([
            'user_id' => $master->id,
            'name' => 'Мария Клиентова',
            'phone' => '+79009876543',
            'telegram_id' => '123456789',
        ]);

        $today = Carbon::today();

        Appointment::create([
            'master_id' => $master->id,
            'client_id' => $client->id,
            'service_id' => $services[0]->id,
            'start_time' => $today->copy()->setTime(10, 0),
            'status' => 'booked',
        ]);

        Appointment::create([
            'master_id' => $master->id,
            'client_id' => $client->id,
            'service_id' => $services[2]->id,
            'start_time' => $today->copy()->setTime(13, 30),
            'status' => 'booked',
        ]);

        Appointment::create([
            'master_id' => $master->id,
            'client_id' => $client->id,
            'service_id' => $services[1]->id,
            'start_time' => $today->copy()->setTime(17, 0),
            'status' => 'booked',
        ]);

        $this->command->info('Тестовые данные созданы:');
        $this->command->info('  Мастер: test-master (slug)');
        $this->command->info('  Услуги: '.count($services).' шт.');
        $this->command->info('  Клиент: Мария Клиентова (Client model)');
        $this->command->info('  Записи на сегодня: 3 шт.');
        $this->command->info('    10:00–11:00 (booked, 60 мин)');
        $this->command->info('    13:30–14:00 (booked, 30 мин)');
        $this->command->info('    17:00–18:30 (booked, 90 мин)');
    }
}
