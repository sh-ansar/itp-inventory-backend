<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class InventoryBrowserApiSessionRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_origin_browser_session_authenticates_protected_api(): void
    {
        config()->set('inventory.browser_login_enabled', true);
        config()->set('sanctum.stateful', ['127.0.0.1:5234']);
        $user = User::factory()->create([
            'is_active' => true,
            'email' => 'qa.session@example.test',
            'password' => Hash::make('qa-session-test-only'),
        ]);
        $this->withHeader('Referer', 'http://127.0.0.1:5234/inventory/registry');
        $this->postJson('/auth/login', [
            'email' => 'qa.session@example.test',
            'password' => 'qa-session-test-only',
        ])->assertOk()->assertJsonPath('id', $user->id);
        $this->getJson('/auth/me')->assertOk();
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('id', $user->id);
    }
}
