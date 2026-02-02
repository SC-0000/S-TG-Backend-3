<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\Transaction;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use App\Services\BillingService;

class TransactionController extends Controller
{   
    protected $billingService;
     protected string $apiKey;
    public function __construct(BillingService $billingService)
    {
        $this->billingService = $billingService;
         $this->apiKey = config('services.billing.publishable_key');
        
    }

    /**
     * Enable autopay for a given invoice.
     */
    public function enableAutopay(Request $request, $invoiceId)
    {
        $result = $this->billingService->enableAutopay($invoiceId);

        return response()->json([
            'success' => $result['success'],
            'status' => $result['status'],
            'body' => $result['body'],
            'json' => $result['json'],
        ], $result['success'] ? 200 : 400);
    }

    public function index()
    {
        $user = Auth::user();

        $query = Transaction::with('user');

        if ($user->role === 'super_admin') {
            // Super admin: optionally filter by organization via the transaction's user org
            if (request()->filled('organization_id')) {
                $query->whereHas('user', function ($q) {
                    $q->where('current_organization_id', request()->organization_id);
                });
            }
        } elseif ($user->current_organization_id) {
            // Admin: only users from their current organization
            $query->whereHas('user', function ($q) use ($user) {
                $q->where('current_organization_id', $user->current_organization_id);
            });
        }

        $organizations = $user->role === 'super_admin'
            ? \App\Models\Organization::orderBy('name')->get()
            : null;

        return Inertia::render('@admin/Transactions/Index', [
            'transactions' => $query->latest()->get(),
            'organizations' => $organizations,
            'filters' => request()->only('organization_id'),
        ]);
    }

    public function create()
    {
        return Inertia::render('@admin/Transactions/Create');
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'user_id' => 'required|integer',
            'amount'          => 'required|numeric',
            'currency'        => 'nullable|string',
            'payment_method'  => 'required|in:credit_card,paypal,bank_transfer',
            'status'          => 'required|in:pending,completed,failed,refunded',
        ]);
        

        $transaction = Transaction::create($validatedData);

        return redirect()->route('transactions.show', $transaction->id)
                         ->with('success', 'Transaction created successfully!');
    }

    public function show(Transaction $transaction)
    {   
       $user = Auth::user();
       
       // Calculate due date based on service start times
       $serviceStartTimes = $transaction->items
           ->filter(fn($item) => $item->item_type === \App\Models\Service::class)
           ->map(function($item) {
               $service = \App\Models\Service::find($item->item_id);
               return $service ? $service->start_datetime : null;
           })
           ->filter()
           ->map(fn($dt) => \Carbon\Carbon::parse($dt));

       $dueDate = null;
       if ($serviceStartTimes->isNotEmpty()) {
           $earliest = $serviceStartTimes->min();
           $dueDate = $earliest->copy()->subDay()->format('Y-m-d');
       }
       
        return Inertia::render('@public/Transactions/Show', [
            'transaction' => $transaction->load([
                'items.item', 'invoice', 'logs', 'refunds',
            ]),
            'customerId' => $user->billing_customer_id,
            'apiKey' => $this->apiKey,
            'dueDate' => $dueDate,
        ]);
    }
    public function edit($id)
    {
        $transaction = Transaction::findOrFail($id);
        return Inertia::render('@admin/Transactions/Edit', ['transaction' => $transaction]);
    }

    public function update(Request $request, $id)
    {
        $transaction = Transaction::findOrFail($id);
        $validatedData = $request->validate([
            'user_id'         => 'required|integer|exists:users,id',
            'amount'          => 'required|numeric',
            'currency'        => 'nullable|string',
            'payment_method'  => 'required|in:credit_card,paypal,bank_transfer',
            'status'          => 'required|in:pending,completed,failed,refunded',
        ]);

        $transaction->update($validatedData);

        return redirect()->route('transactions.show', $transaction->id)
                         ->with('success', 'Transaction updated successfully!');
    }

    public function destroy($id)
    {
        $transaction = Transaction::findOrFail($id);
        $transaction->delete();
        return redirect()->route('transactions.index')->with('success', 'Transaction deleted successfully!');
    }
}
