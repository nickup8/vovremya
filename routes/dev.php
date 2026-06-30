<?php

use App\Models\Appointment;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Route::prefix('dev')->middleware(['web'])->group(function () {
    Route::get('/impersonate/{userId}', function (int $userId): JsonResponse|RedirectResponse {
        if (app()->isProduction()) {
            abort(404);
        }

        $user = User::find($userId);

        if (! $user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        Auth::loginUsingId($user->id);

        return redirect()->route('admin.calendar');
    })->name('dev.impersonate');

    Route::get('/seed-test-data', function (): JsonResponse {
        if (app()->isProduction()) {
            abort(404);
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

            $serviceTitles = [
                ['title' => 'Маникюр', 'price' => 1500, 'duration_minutes' => 60],
                ['title' => 'Педикюр', 'price' => 2000, 'duration_minutes' => 90],
                ['title' => 'Покрытие гель-лаком', 'price' => 800, 'duration_minutes' => 45],
                ['title' => 'Дизайн ногтей', 'price' => 500, 'duration_minutes' => 30],
                ['title' => 'Снятие покрытия', 'price' => 300, 'duration_minutes' => 30],
            ];

            $services = collect();
            foreach ($serviceTitles as $s) {
                $services->push(Service::firstOrCreate(
                    ['user_id' => $master->id, 'title' => $s['title']],
                    [
                        'price' => $s['price'],
                        'duration_minutes' => $s['duration_minutes'],
                    ]
                ));
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
            if ($existingCount < 50) {
                $statuses = ['paid', 'paid', 'booked', 'booked', 'booked'];
                $clientIds = $clients->pluck('id')->toArray();
                $serviceIds = $services->pluck('id')->toArray();
                $today = Carbon::today();

                $slots = [];
                for ($i = 0; $i < 50; $i++) {
                    $daysBack = $i % 7;
                    $hour = 9 + ($i % 10);
                    $slot = $today->copy()->subDays($daysBack)->setTime($hour, 0);
                    $slots[] = $slot;
                }

                $appointments = [];
                foreach ($slots as $index => $slot) {
                    $appointments[] = [
                        'master_id' => $master->id,
                        'client_id' => $clientIds[array_rand($clientIds)],
                        'service_id' => $serviceIds[array_rand($serviceIds)],
                        'start_time' => $slot,
                        'status' => $statuses[array_rand($statuses)],
                        'provider' => array_rand(['telegram', 'max']),
                        'created_at' => $slot->copy()->subDays(rand(1, 3)),
                        'updated_at' => $slot,
                    ];
                }

                $chunks = array_chunk($appointments, 50);
                foreach ($chunks as $chunk) {
                    Appointment::insert($chunk);
                }
            }
        });

        $master = User::where('email', 'dev-master@vovremia.local')->first();

        return response()->json([
            'ok' => true,
            'master_id' => $master->id,
            'master_slug' => $master->master_slug,
            'services' => Service::where('user_id', $master->id)->count(),
            'clients' => Client::where('user_id', $master->id)->count(),
            'appointments' => Appointment::where('master_id', $master->id)->count(),
            'impersonate_url' => "/dev/impersonate/{$master->id}",
        ]);
    })->name('dev.seed-test-data');
});
