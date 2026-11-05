<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class InventorySiteContentTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $email): User
    {
        return User::create(['name'=>'Site QA','email'=>$email,
            'password'=>Hash::make('StrongPassword123!'),'is_active'=>true]);
    }

    public function test_public_has_no_unpublished_private_fields(): void
    {
        $this->getJson('/api/v1/commercial/site-content?locale=ru')
            ->assertOk()->assertExactJson(['data'=>[]]);
        $this->getJson('/api/v1/platform-admin/site-content?locale=ru')->assertUnauthorized();
        $this->putJson('/api/v1/platform-admin/site-content',[
            'locale'=>'ru','values'=>['heroTitle'=>'test'],
        ])->assertUnauthorized();
    }

    public function test_explicit_grant_locale_isolation_and_audit(): void
    {
        $user=$this->user('site-qa@example.test');
        $this->actingAs($user);
        $this->getJson('/api/v1/platform-admin/site-content?locale=en')->assertForbidden();
        $this->putJson('/api/v1/platform-admin/site-content',[
            'locale'=>'ru','values'=>['heroTitle'=>'Custom home'],
        ])->assertForbidden();
        DB::table('inventory_platform_grants')->insert([
            'user_id'=>$user->id,'permission'=>'site.write',
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        $this->putJson('/api/v1/platform-admin/site-content',[
            'locale'=>'ru','values'=>['heroTitle'=>'Инвентаризация под контролем','heroText'=>'Только текст'],
        ])->assertOk()->assertJsonPath('data.heroTitle','Инвентаризация под контролем');
        $this->getJson('/api/v1/commercial/site-content?locale=ru')->assertJsonPath(
            'data.heroTitle','Инвентаризация под контролем');
        $this->getJson('/api/v1/commercial/site-content?locale=en')->assertExactJson(['data'=>[]]);
        $this->getJson('/api/v1/platform-admin/site-content?locale=ru')->assertForbidden();
        $this->assertDatabaseHas('inventory_commercial_audit',[
            'action'=>'site.content.saved','subject'=>'locale:ru',
        ]);
        $this->putJson('/api/v1/platform-admin/site-content',[
            'locale'=>'ru','values'=>['heroTitle'=>''],
        ])->assertOk();
        $this->getJson('/api/v1/commercial/site-content?locale=ru')
            ->assertJsonMissing(['heroTitle'=>'Инвентаризация под контролем']);
    }

    public function test_rejects_unrecognized_keys_markup_and_invalid_locale(): void
    {
        $user=$this->user('site-admin@example.test');
        DB::table('inventory_platform_grants')->insert([
            'user_id'=>$user->id,'permission'=>'platform.manage',
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        $this->actingAs($user);
        $this->putJson('/api/v1/platform-admin/site-content',[
            'locale'=>'ru','values'=>['adminPassword'=>'123'],
        ])->assertUnprocessable();
        $this->putJson('/api/v1/platform-admin/site-content',[
            'locale'=>'ru','values'=>['heroTitle'=>'<script>alert(1)</script>'],
        ])->assertUnprocessable();
        $this->putJson('/api/v1/platform-admin/site-content',[
            'locale'=>'de','values'=>['heroTitle'=>'German'],
        ])->assertUnprocessable();
    }
}
