<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\CreditTransactionType;
use App\Models\User;
use App\Services\Billing\CreditLedger;
use App\Support\Money\Usd;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(private readonly CreditLedger $ledger) {}

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        return DB::transaction(function () use ($input) {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            $welcomeCredit = Usd::of(config('billing.signup_credit'));

            if (! $welcomeCredit->isZero()) {
                $this->ledger->credit($user, $welcomeCredit, CreditTransactionType::SignupBonus);
            }

            return $user->refresh();
        });
    }
}
