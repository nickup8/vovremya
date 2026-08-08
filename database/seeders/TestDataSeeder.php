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

class TestDataSeeder extends Seeder
{
    public function run(): void
    {
        Appointment::query()->delete();
        Client::query()->delete();
        MasterService::query()->delete();
        ServiceCatalog::query()->delete();
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

        if (! $master->workspace_id) {
            $ws = Workspace::firstOrCreate(
                ['owner_id' => $master->id],
                ['name' => "{$master->name} Studio"]
            );
            $master->update(['workspace_id' => $ws->id]);
            $master->refresh();
        }

        $serviceData = [
            ['title' => 'Маникюр с покрытием', 'price' => 1800, 'duration' => 60],
            ['title' => 'Педикюр', 'price' => 2200, 'duration' => 90],
            ['title' => 'Снятие + выравнивание', 'price' => 800, 'duration' => 30],
            ['title' => 'Дизайн ногтей', 'price' => 500, 'duration' => 120],
        ];

        $services = [];
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
            $services[] = $ms;
        }

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
            'master_service_id' => $services[0]->id,
            'service_name' => $services[0]->catalog->title,
            'price' => $services[0]->effective_price,
            'duration' => 60,
            'start_time' => $today->copy()->setTime(10, 0),
            'status' => 'booked',
        ]);

        Appointment::create([
            'master_id' => $master->id,
            'client_id' => $client->id,
            'master_service_id' => $services[2]->id,
            'service_name' => $services[2]->catalog->title,
            'price' => $services[2]->effective_price,
            'duration' => 60,
            'start_time' => $today->copy()->setTime(13, 30),
            'status' => 'booked',
        ]);

        Appointment::create([
            'master_id' => $master->id,
            'client_id' => $client->id,
            'master_service_id' => $services[1]->id,
            'service_name' => $services[1]->catalog->title,
            'price' => $services[1]->effective_price,
            'duration' => 60,
            'start_time' => $today->copy()->setTime(17, 0),
            'status' => 'booked',
        ]);

        $this->command->info('Тестовые данные созданы:');
        $this->command->info('  Мастер: test-master (slug)');
        $this->command->info('  Услуги: '.MasterService::where('master_id', $master->id)->count().' шт.');
        $this->command->info('  Клиент: Мария Клиентова (Client model)');
        $this->command->info('  Записи на сегодня: 3 шт.');
        $this->command->info('    10:00–11:00 (booked, 60 мин)');
        $this->command->info('    13:30–14:00 (booked, 30 мин)');
        $this->command->info('    17:00–18:30 (booked, 90 мин)');
    }
}
