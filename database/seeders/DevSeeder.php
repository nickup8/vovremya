<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\Workspace;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DevSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->warn('Skipping DevSeeder in production.');

            return;
        }

        DB::transaction(function () {
            $master = User::firstOrCreate(
                ['email' => 'dev-master@vovremia.local'],
                [
                    'name' => 'Тестовый Мастер',
                    'email' => 'dev-master@vovremia.local',
                    'email_verified_at' => now(),
                    'password' => bcrypt('password'),
                    'phone' => '+79000000001',
                    'is_master' => true,
                    'master_slug' => 'dev-master',
                    'specialty' => 'Тестовый мастер',
                    'address' => 'Тестовый адрес, 1',
                    'telegram_notifications' => true,
                    'max_notifications' => true,
                    'soft_deposit' => true,
                    'deposit_timeout' => 15,
                    'deposit_percent' => 20,
                    'slot_interval' => 30,
                ]
            );

            // Гарантировать workspace
            if (! $master->workspace_id) {
                $workspace = Workspace::firstOrCreate(
                    ['owner_id' => $master->id],
                    ['name' => 'Dev Studio']
                );
                $master->update(['workspace_id' => $workspace->id]);
                $master->refresh();
            }

            $serviceData = [
                ['title' => 'Маникюр', 'price' => 1500, 'duration' => 60],
                ['title' => 'Педикюр', 'price' => 2000, 'duration' => 90],
                ['title' => 'Покрытие гель-лаком', 'price' => 800, 'duration' => 45],
                ['title' => 'Дизайн ногтей', 'price' => 500, 'duration' => 30],
                ['title' => 'Снятие покрытия', 'price' => 300, 'duration' => 30],
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

            $clients = collect();
            for ($i = 1; $i <= 10; $i++) {
                $clients->push(Client::firstOrCreate(
                    ['user_id' => $master->id, 'phone' => "+790000000{$i}0"],
                    [
                        'name' => "Тестовый Клиент {$i}",
                        'telegram_id' => "tg_dev_{$i}",
                    ]
                ));
            }

            $existingCount = Appointment::where('master_id', $master->id)->count();
            if ($existingCount >= 50) {
                $this->command->info("Already have {$existingCount} appointments, skipping.");

                return;
            }

            $statuses = ['paid', 'paid', 'booked', 'booked', 'booked'];
            $clientIds = $clients->pluck('id')->toArray();
            $today = Carbon::today();

            $appointments = [];
            for ($i = 0; $i < 50; $i++) {
                $daysBack = $i % 7;
                $hour = 9 + ($i % 10);
                $slot = $today->copy()->subDays($daysBack)->setTime($hour, 0);

                $ms = $services->random();

                $appointments[] = [
                    'id'                => Str::uuid7()->toString(),
                    'master_id'         => $master->id,
                    'client_id'         => $clientIds[array_rand($clientIds)],
                    'master_service_id' => $ms->id,
                    'service_name'      => $ms->catalog->title,
                    'price'             => $ms->effective_price,
                    'duration'          => 60, // фиксировано для избежания overlap в сидере
                    'start_time'        => $slot,
                    'status'            => $statuses[array_rand($statuses)],
                    'provider'          => ['telegram', 'max'][array_rand(['telegram', 'max'])],
                    'created_at'        => $slot->copy()->subDays(rand(1, 3)),
                    'updated_at'        => $slot,
                ];
            }

            foreach (array_chunk($appointments, 50) as $chunk) {
                Appointment::insert($chunk);
            }
        });

        $master = User::where('email', 'dev-master@vovremia.local')->first();

        $this->command->info('Dev data seeded:');
        $this->command->info("  Master: {$master->name} (ID: {$master->id})");
        $this->command->info('  Services: '.MasterService::where('master_id', $master->id)->count());
        $this->command->info('  Clients: '.Client::where('user_id', $master->id)->count());
        $this->command->info('  Appointments: '.Appointment::where('master_id', $master->id)->count());
    }
}
