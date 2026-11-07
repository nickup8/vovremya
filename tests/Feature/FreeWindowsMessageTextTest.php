<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * N12: сообщения free-windows без литерала {$...} и без английского текста.
 */
class FreeWindowsMessageTextTest extends TestCase
{
    use RefreshDatabase;

    private User $master;

    protected function setUp(): void
    {
        parent::setUp();

        $plan = TariffPlan::create([
            'code' => 'pro',
            'name' => 'Про',
            'price_monthly' => 1000,
            'features' => ['free_windows', 'calendar'],
            'is_active' => true,
        ]);
        $this->master = User::factory()->master()->create([
            'is_service_provider' => true,
            'settings' => ['timezone' => 'Europe/Moscow'],
        ]);
        $workspace = Workspace::create(['name' => 'FW Msg WS', 'owner_id' => $this->master->id]);
        $this->master->update(['workspace_id' => $workspace->id]);
        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $plan->id,
            'status' => 'active',
            'expires_at' => now()->addYear(),
        ]);
        $this->master->createDefaultWorkingHours();
    }

    public function test_publication_date_out_of_range_message_is_readable_russian(): void
    {
        $from = now()->addDays(2)->format('Y-m-d');
        $to = now()->addDays(4)->format('Y-m-d');
        $outside = now()->addDays(10)->format('Y-m-d');

        $response = $this->actingAs($this->master)->postJson('/admin/free-windows/publications', [
            'mode' => 'all',
            'date_from' => $from,
            'date_to' => $to,
            'days' => [
                ['date' => $outside, 'ranges' => [['start' => '09:00', 'end' => '18:00']]],
            ],
        ]);

        $this->assertContains($response->status(), [422, 302]);

        if ($response->status() === 422) {
            $message = $response->json('message')
                ?? ($response->json('errors.days.0') ?? null);
            $this->assertNotNull($message);
            $this->assertStringNotContainsString('{$day', $message);
            $this->assertStringNotContainsString('{$start}', $message);
            $this->assertStringNotContainsString('The ', $message);
            $this->assertStringContainsString('выходит за пределы', $message);
            $this->assertStringContainsString($outside, $message);
        }
    }

    public function test_free_windows_validation_messages_are_russian(): void
    {
        $response = $this->actingAs($this->master)->getJson('/admin/free-windows');

        $this->assertContains($response->status(), [422, 302]);

        if ($response->status() === 422) {
            $message = (string) $response->json('message');
            $this->assertStringNotContainsString('field is required', $message);
            $this->assertStringNotContainsString('given data was invalid', $message);
            $this->assertStringContainsString('Заполните поле', $message);
        }
    }
}
