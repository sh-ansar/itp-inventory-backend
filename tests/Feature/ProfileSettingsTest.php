<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class ProfileSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_view_and_update_only_own_profile(): void
    {
        $a=User::factory()->create(['is_active'=>true,'name'=>'Original']);
        $b=User::factory()->create(['is_active'=>true,'name'=>'Second']);
        $this->getJson('/api/v1/profile')->assertUnauthorized();
        $this->actingAs($a)->getJson('/api/v1/profile')
            ->assertOk()->assertJsonPath('data.name','Original');
        $this->putJson('/api/v1/profile',['name'=>'New Name','email'=>'unverified@example.com'])
            ->assertOk()->assertJsonPath('data.name','New Name');
        $this->assertSame('unverified@example.com'!==$a->fresh()->email,true);
        $this->assertSame('Second',$b->fresh()->name);
    }

    public function test_password_change_requires_old_password_confirmation_and_strength(): void
    {
        $user=User::factory()->create(['password'=>'OldPassword#2026','is_active'=>true]);
        $this->actingAs($user);
        $url='/api/v1/profile/password';
        $this->putJson($url,[
            'current_password'=>'wrong','password'=>'NewPassword#2027','password_confirmation'=>'NewPassword#2027',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->putJson($url,[
            'current_password'=>'OldPassword#2026','password'=>'short','password_confirmation'=>'short',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->putJson($url,[
            'current_password'=>'OldPassword#2026','password'=>'NewPassword#2027','password_confirmation'=>'wrong',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->putJson($url,[
            'current_password'=>'OldPassword#2026','password'=>'NewPassword#2027','password_confirmation'=>'NewPassword#2027',
        ])->assertOk()->assertJsonPath('ok',true);
        $this->assertTrue(Hash::check('NewPassword#2027',$user->fresh()->password));
        $this->assertFalse(Hash::check('OldPassword#2026',$user->fresh()->password));
    }
}
