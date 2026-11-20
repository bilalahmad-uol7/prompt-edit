<?php

namespace App\Http\Controllers;

use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use App\Services\Billing\CreditLedger;
use App\Support\Money\Usd;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CreditController extends Controller
{
    public function index(Request $request): Response
    {
        $transactions = $request->user()->creditTransactions()
            ->with('generation:id,prompt')
            ->latest('id')
            ->paginate(15)
            ->through(fn (CreditTransaction $transaction) => [
                'id' => $transaction->id,
                'type' => $transaction->type->value,
                'label' => $transaction->type->label(),
                'amount' => $transaction->amount_nanos->toDecimal(),
                'balance_after' => $transaction->balance_after_nanos->toDecimal(),
                'prompt' => $transaction->generation?->prompt,
                'created_at' => $transaction->created_at->toIso8601String(),
            ]);

        return Inertia::render('credits/Index', [
            'packs' => config('billing.credit_packs'),
            'transactions' => $transactions,
        ]);
    }

    /**
     * A fake purchase: no payment is taken, credit is simply added.
     */
    public function store(Request $request, CreditLedger $ledger): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'string', Rule::in(config('billing.credit_packs'))],
        ]);

        $amount = Usd::of($validated['amount']);
        $ledger->credit($request->user(), $amount, CreditTransactionType::Purchase);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Added {$amount->format(2)} in credits."]);

        return back();
    }
}
