<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Throwable;

final class BootstrapInventoryAdmin extends Command
{
    protected $signature = 'inventory:bootstrap-admin
        {--email= : Corporate administrator email}
        {--company=ITP Mining : Initial company name}';

    protected $description = 'Securely create the first Inventory administrator in the isolated database';

    public function handle(): int
    {
        if (CompanyMembership::query()->where('role_code', 'company_admin')->exists()) {
            $this->components->error('An administrator already exists; bootstrap is one-time only.');
            return self::FAILURE;
        }

        $email = mb_strtolower(trim((string) ($this->option('email') ?: $this->ask('Corporate email'))));
        $companyName = trim((string) $this->option('company'));
        if ($companyName === '' || mb_strlen($companyName) > 255) {
            $this->components->error('Invalid company name.');
            return self::FAILURE;
        }
        $emailValidator = Validator::make(['email' => $email], [
            'email' => ['required', 'email:rfc', 'max:255'],
        ]);
        if ($emailValidator->fails()) {
            $this->components->error('Provide a valid administrator email.');
            return self::FAILURE;
        }
        if (User::query()->where('email', $email)->exists()) {
            $this->components->error('This account already exists; bootstrap will not reset a password.');
            return self::FAILURE;
        }

        // Password is entered in the terminal as hidden input and never stored in
        // source, process arguments, CLI history or a plaintext credential file.
        $password = (string) $this->secret('New Inventory password');
        $confirmation = (string) $this->secret('Repeat Inventory password');
        if ($password !== $confirmation) {
            $this->components->error('Passwords do not match.');
            return self::FAILURE;
        }
        $validator = Validator::make(['password' => $password], [
            'password' => ['required', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);
        if ($validator->fails()) {
            $this->components->error('Password must be at least 12 characters with upper/lower case, a number and a symbol.');
            return self::FAILURE;
        }
        try {
            DB::transaction(static function () use ($email, $password, $companyName): void {
                // Recheck under transaction; no public user sign-up exists.
                if (CompanyMembership::query()->where('role_code', 'company_admin')->lockForUpdate()->exists()) {
                    throw new \RuntimeException('An administrator was created concurrently.');
                }
                $company = Company::query()->firstOrCreate(
                    ['name' => $companyName],
                    ['id' => (string) Str::uuid()]
                );
                $user = User::query()->create([
                    'name' => 'Inventory Administrator',
                    'email' => $email,
                    'password' => Hash::make($password),
                    'is_active' => true,
                ]);
                $user->companyMemberships()->create([
                    'company_id' => $company->id,
                    'role_code' => 'company_admin',
                ]);
            });
        } catch (Throwable $e) {
            $this->components->error('Bootstrap failed; nothing was published. Check local application logs.');
            report($e);
            return self::FAILURE;
        } finally {
            unset($password, $confirmation);
        }
        $this->components->info('Initial Inventory administrator created. Credentials remain known only to the operator.');
        return self::SUCCESS;
    }
}