<?php

namespace Tests\Feature;

use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class BootstrapAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_bootstrap_requires_hidden_password_and_creates_only_one_company_admin(): void
    {
        $password = 'StrongPassword123!';
        $this->artisan('inventory:bootstrap-admin', [
            '--email' => 'inventory-admin@example.test',
            '--company' => 'ITP Mining',
        ])
            ->expectsQuestion('New Inventory password', $password)
            ->expectsQuestion('Repeat Inventory password', $password)
            ->assertExitCode(0);

        $user = User::query()->where('email', 'inventory-admin@example.test')->firstOrFail();
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertNotSame($password, $user->password);
        $this->assertSame(1, CompanyMembership::query()->where('role_code', 'company_admin')->count());
        $this->assertDatabaseHas('companies', ['name' => 'ITP Mining']);

        $this->artisan('inventory:bootstrap-admin', [
            '--email' => 'second-admin@example.test',
        ])->assertExitCode(1);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_invalid_email_cannot_bootstrap(): void
    {
        $this->artisan('inventory:bootstrap-admin', ['--email'=>'not-an-email'])
            ->assertExitCode(1);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_weak_password_and_mismatch_are_rejected_without_creating_accounts(): void
    {
        $this->artisan('inventory:bootstrap-admin', ['--email'=>'admin@example.test'])
            ->expectsQuestion('New Inventory password', 'short')
            ->expectsQuestion('Repeat Inventory password', 'short')
            ->assertExitCode(1);

        $this->artisan('inventory:bootstrap-admin', ['--email'=>'admin@example.test'])
            ->expectsQuestion('New Inventory password', 'StrongPassword123!')
            ->expectsQuestion('Repeat Inventory password', 'WrongPassword123!')
            ->assertExitCode(1);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('company_memberships', 0);
    }
}