<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Recreate appointments table without old FK, then migrate data
        // SQLite doesn't support DROP FOREIGN KEY, so we rebuild

        $appointments = DB::table('appointments')->select('*')->get();

        Schema::dropIfExists('appointments_temp');

        Schema::create('appointments_temp', function (Blueprint $table) {
            $table->id();
            $table->foreignId('master_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->dateTime('start_time');
            $table->string('status')->default('pending_client');
            $table->string('provider')->nullable();
            $table->timestamps();
        });

        // 2. For each appointment with old client_id (pointing to users),
        //    find or create a Client, then insert with new client_id
        foreach ($appointments as $appt) {
            $newClientId = null;

            if ($appt->client_id) {
                $user = DB::table('users')->where('id', $appt->client_id)->first();

                if ($user) {
                    $existingClient = DB::table('clients')
                        ->where('user_id', $appt->master_id)
                        ->where('phone', $user->phone)
                        ->first();

                    if (! $existingClient) {
                        $newClientId = DB::table('clients')->insertGetId([
                            'user_id' => $appt->master_id,
                            'phone' => $user->phone ?? '',
                            'name' => $user->name ?? 'Клиент',
                            'telegram_id' => $user->telegram_id,
                            'max_id' => $user->max_id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    } else {
                        $newClientId = $existingClient->id;
                    }
                }
            }

            DB::table('appointments_temp')->insert([
                'id' => $appt->id,
                'master_id' => $appt->master_id,
                'client_id' => $newClientId,
                'service_id' => $appt->service_id,
                'start_time' => $appt->start_time,
                'status' => $appt->status,
                'provider' => $appt->provider ?? null,
                'created_at' => $appt->created_at,
                'updated_at' => $appt->updated_at,
            ]);
        }

        Schema::dropIfExists('appointments');
        Schema::rename('appointments_temp', 'appointments');

        // Recreate indexes
        Schema::table('appointments', function (Blueprint $table) {
            $table->index('master_id');
            $table->index('client_id');
            $table->index('start_time');
            $table->index('status');
        });
    }

    public function down(): void
    {
        $appointments = DB::table('appointments')->select('*')->get();

        Schema::dropIfExists('appointments_temp');

        Schema::create('appointments_temp', function (Blueprint $table) {
            $table->id();
            $table->foreignId('master_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->dateTime('start_time');
            $table->string('status')->default('pending_client');
            $table->timestamps();
        });

        foreach ($appointments as $appt) {
            $userId = null;

            if ($appt->client_id) {
                $client = DB::table('clients')->where('id', $appt->client_id)->first();
                if ($client) {
                    $userId = $client->user_id;
                }
            }

            DB::table('appointments_temp')->insert([
                'id' => $appt->id,
                'master_id' => $appt->master_id,
                'client_id' => $userId,
                'service_id' => $appt->service_id,
                'start_time' => $appt->start_time,
                'status' => $appt->status,
                'created_at' => $appt->created_at,
                'updated_at' => $appt->updated_at,
            ]);
        }

        Schema::dropIfExists('appointments');
        Schema::rename('appointments_temp', 'appointments');

        Schema::table('appointments', function (Blueprint $table) {
            $table->index('master_id');
            $table->index('client_id');
            $table->index('start_time');
            $table->index('status');
        });
    }
};
