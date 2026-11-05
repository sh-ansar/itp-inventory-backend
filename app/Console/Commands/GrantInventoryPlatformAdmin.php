<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class GrantInventoryPlatformAdmin extends Command
{
    protected $signature = 'inventory:platform-grant {email : Existing user email} {permission=platform.manage} {--yes : Confirm direct permission change}';
    protected $description = 'Grant an Inventory-only platform permission to an existing account';

    public function handle(): int
    {
        $permission=$this->argument('permission');
        if (!in_array($permission,\App\Services\Commercial\Access::PERMISSIONS,true)) {
            $this->error('Unsupported permission.');
            return self::FAILURE;
        }
        $user=User::query()->where('email',strtolower(trim($this->argument('email'))))->first();
        if (!$user) { $this->error('No matching Inventory user.'); return self::FAILURE; }
        if (!$this->option('yes')) {
            $this->error('The --yes flag is required to change access.');
            return self::FAILURE;
        }
        DB::table('inventory_platform_grants')->insertOrIgnore([
            'user_id'=>$user->id,'permission'=>$permission,'created_by'=>null,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        $this->info('Inventory permission granted to user #'.$user->id.': '.$permission);
        return self::SUCCESS;
    }
}
