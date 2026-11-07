<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * N12: понятные сообщения — чужая занятая ссылка / своя / новая свободная.
 */
class MasterSlugConflictMessageTest extends TestCase
{
    use RefreshDatabase;

    private const TAKEN_SLUG = 'taken-by-other';

    private const EXACT_MESSAGE = 'Эта ссылка уже занята. Придумайте другую';

    private function otherMaster(): User
    {
        return User::factory()->create([
            'master_slug' => self::TAKEN_SLUG,
            'phone' => '79000000101',
        ]);
    }

    public function test_foreign_taken_slug_rejected_with_exact_message(): void
    {
        $this->otherMaster();
        $user = User::factory()->create([
            'master_slug' => 'my-current-slug',
            'phone' => '79000000102',
        ]);

        $response = $this
            ->actingAs($user)
            ->put(route('admin.settings.update'), [
                'name' => $user->name,
                'phone' => $user->phone,
                'master_slug' => self::TAKEN_SLUG,
            ]);

        $response->assertSessionHasErrors('master_slug');
        $response->assertSessionHasErrors([
            'master_slug' => self::EXACT_MESSAGE,
        ]);

        $this->assertSame('my-current-slug', $user->fresh()->master_slug);
    }

    public function test_own_current_slug_still_saves(): void
    {
        $user = User::factory()->create([
            'master_slug' => 'my-current-slug',
            'phone' => '79000000103',
        ]);

        $response = $this
            ->actingAs($user)
            ->put(route('admin.settings.update'), [
                'name' => $user->name,
                'phone' => $user->phone,
                'master_slug' => 'my-current-slug',
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $this->assertSame('my-current-slug', $user->fresh()->master_slug);
    }

    public function test_new_free_slug_saves(): void
    {
        $this->otherMaster();
        $user = User::factory()->create([
            'master_slug' => 'my-current-slug',
            'phone' => '79000000104',
        ]);

        $response = $this
            ->actingAs($user)
            ->put(route('admin.settings.update'), [
                'name' => $user->name,
                'phone' => $user->phone,
                'master_slug' => 'brand-new-free-slug',
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $this->assertSame('brand-new-free-slug', $user->fresh()->master_slug);
    }

    public function test_settings_validation_messages_are_russian_not_english(): void
    {
        $user = User::factory()->create([
            'master_slug' => 'my-current-slug',
            'phone' => '79000000105',
        ]);

        $response = $this
            ->actingAs($user)
            ->put(route('admin.settings.update'), [
                'name' => '',
                'phone' => '',
                'master_slug' => 'My_Slug_With_Underscore',
            ]);

        // Поля — публичный assertSessionHasErrors; текст — callback у assertSessionHas
        // (TestResponse::session() в Laravel 13 protected, из теста недоступен).
        $response->assertSessionHasErrors(['name', 'phone', 'master_slug']);

        $response->assertSessionHas('errors', function ($errors): bool {
            $messages = $errors->getBag('default')->getMessages();

            $this->assertArrayHasKey('name', $messages);
            $this->assertArrayHasKey('phone', $messages);
            $this->assertArrayHasKey('master_slug', $messages);

            $all = implode("\n", array_map(fn ($v) => implode("\n", (array) $v), $messages));

            $this->assertStringNotContainsString('The given data was invalid', $all);
            $this->assertStringNotContainsString('field is required', $all);
            $this->assertStringNotContainsString('already been taken', $all);
            $this->assertStringNotContainsString('format is invalid', $all);

            $this->assertStringContainsString('Заполните поле', $messages['name'][0]);
            $this->assertStringContainsString('Заполните поле', $messages['phone'][0]);

            return true;
        });
    }
}
