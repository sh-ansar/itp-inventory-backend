<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ManageInventoryIntegrationKey extends Command
{
    protected $signature = 'inventory:integration-key
        {company : Inventory company UUID}
        {--system=1c : System code}
        {--name=Accounting integration : Human-readable key name}
        {--hours=720 : Lifetime in hours (max 2160)}
        {--revoke= : Existing integration key UUID to revoke}';

    protected $description = 'Issue or revoke a company-bound, read-only accounting API token';

    public function handle(): int
    {
        $company = Company::query()->find($this->argument('company'));
        if (! $company) {
            $this->error('Unknown Inventory company UUID.');
            return self::FAILURE;
        }
        if ($this->option('revoke')) {
            $count = DB::table('inventory_integration_keys')
                ->where('id',(string)$this->option('revoke'))
                ->where('company_id',$company->id)
                ->whereNull('revoked_at')->update(['revoked_at'=>now(),'updated_at'=>now()]);
            if ($count !== 1) {
                $this->error('No active integration key found for this company.');
                return self::FAILURE;
            }
            $this->info('Integration key revoked.');
            return self::SUCCESS;
        }
        $system = trim((string)$this->option('system'));
        $name = trim((string)$this->option('name'));
        $hours = filter_var($this->option('hours'), FILTER_VALIDATE_INT);
        if (! preg_match('/^[a-z0-9_-]{2,40}$/D',$system) ||
            $name === '' || mb_strlen($name)>120 ||
            $hours === false || $hours<1 || $hours>2160) {
            $this->error('Invalid system, name or token lifetime.');
            return self::FAILURE;
        }

        $secret='inv_'.Str::random(64);
        $id=(string)Str::uuid();
        DB::table('inventory_integration_keys')->insert([
            'id'=>$id,'company_id'=>$company->id,
            'system_code'=>$system,'name'=>$name,
            'token_hash'=>hash('sha256',$secret),
            'abilities'=>json_encode(['assets:read','references:read','stock:read','imports:preview'],
                JSON_THROW_ON_ERROR),
            'expires_at'=>now()->addHours($hours),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        $this->warn('Show this secret to the accounting integration owner once only. Do not log it or commit it.');
        $this->line('Key ID: '.$id);
        $this->line('Expires: '.now()->addHours($hours)->toIso8601String());
        $this->line('Bearer token (one-time): '.$secret);
        return self::SUCCESS;
    }
}
