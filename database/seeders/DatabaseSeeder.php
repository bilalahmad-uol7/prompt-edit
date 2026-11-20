<?php

namespace Database\Seeders;

use App\Enums\CreditTransactionType;
use App\Models\User;
use App\Services\Billing\CreditLedger;
use App\Support\Money\Usd;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Creates an operator account (for the admin report) and, outside production, a demo user.
     *
     * In production set SEED_ADMIN_EMAIL and SEED_ADMIN_PASSWORD; without them a random password is generated and printed.
     */
    public function run(CreditLedger $ledger): void
    {
        $email = (string) config('app.seed_admin.email');
        $password = config('app.seed_admin.password') ?: (app()->isProduction() ? Str::password(20) : 'password');

        $admin = User::query()->firstOrCreate(['email' => $email], [
            'name' => 'Operator',
            'password' => $password,
            'email_verified_at' => now(),
        ]);
        $admin->forceFill(['is_admin' => true])->save();

        if ($admin->wasRecentlyCreated) {
            $ledger->credit($admin, Usd::of('10.00'), CreditTransactionType::Purchase);
            $this->command?->info("Admin: {$email} / {$password}");
        }

        if (! app()->isProduction()) {
            $demo = User::query()->firstOrCreate(['email' => 'test@example.com'], [
                'name' => 'Test User',
                'password' => 'password',
                'email_verified_at' => now(),
            ]);

            if ($demo->wasRecentlyCreated) {
                $ledger->credit($demo, Usd::of('1.00'), CreditTransactionType::SignupBonus);
            }
        }
    }
}
