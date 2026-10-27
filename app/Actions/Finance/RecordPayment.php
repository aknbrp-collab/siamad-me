<?php

namespace App\Actions\Finance;

use App\Enums\BillStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentSource;
use App\Enums\PaymentStatus;
use App\Models\Bill;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Admin Payment records money received. The payment stays unverified (it does
 * not count yet) until an Admin confirms the money arrived.
 *
 * Instalment rules (design §5C):
 * - at most bills.max_installments payments that are pending or verified;
 * - each at least payment.min_installment_amount, unless it settles the rest;
 * - never more than what is left after pending and verified payments;
 * - the last allowed instalment must settle the rest.
 */
class RecordPayment
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(Bill $bill, User $recorder, array $input, PaymentSource $source = PaymentSource::Staff): Payment
    {
        $data = Validator::make($input, [
            'amount' => ['required', 'integer', 'min:1'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:255', 'required_if:method,'.PaymentMethod::Transfer->value],
            'proof' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:2048'],
            // The account the money goes into (cash book); left empty, it is set when verified.
            'cash_account_id' => ['nullable', 'integer', Rule::exists('cash_accounts', 'id')->where('is_active', true)],
        ], [
            'reference.required_if' => __('Enter the bank reference of the transfer.'),
        ])->validate();

        return DB::transaction(function () use ($bill, $recorder, $data, $source) {
            $bill = Bill::query()->lockForUpdate()->findOrFail($bill->id);
            $amount = (int) $data['amount'];

            $this->checkInstallmentRules($bill, $amount);

            return $bill->payments()->create([
                'amount' => $amount,
                'method' => $data['method'],
                'source' => $source,
                'paid_on' => $data['paid_on'],
                'reference' => $data['reference'] ?? null,
                'proof_path' => $this->storeProof($data['proof'] ?? null, $bill),
                'status' => PaymentStatus::Unverified,
                'recorded_by' => $recorder->id,
                'cash_account_id' => $data['cash_account_id'] ?? null,
            ]);
        });
    }

    /**
     * The amount still open for new payments and how many instalments remain.
     *
     * @return array{open: int, used: int, max: int, min: int}
     */
    public function allowance(Bill $bill): array
    {
        $counting = $bill->payments()->whereIn('status', PaymentStatus::counting())->get(['amount']);

        return [
            'open' => max(0, $bill->net_amount - (int) $counting->sum('amount')),
            'used' => $counting->count(),
            'max' => $bill->max_installments,
            'min' => (int) Setting::get('payment.min_installment_amount', 200000),
        ];
    }

    private function checkInstallmentRules(Bill $bill, int $amount): void
    {
        if ($bill->status === BillStatus::Void) {
            $this->fail(__('This bill is void.'));
        }

        ['open' => $open, 'used' => $used, 'max' => $max, 'min' => $min] = $this->allowance($bill);

        if ($open === 0) {
            $this->fail(__('Nothing is left to pay: the bill is paid or the rest is awaiting verification.'));
        }

        if ($used >= $max) {
            $this->fail(__('This bill already has the maximum of :max instalments.', ['max' => $max]));
        }

        if ($amount > $open) {
            $this->fail(__('The amount is more than what is left (:open).', ['open' => Money::format($open)]));
        }

        if ($used + 1 === $max && $amount !== $open) {
            $this->fail(__('This is the last allowed instalment; it must settle the rest (:open).', ['open' => Money::format($open)]));
        }

        if ($amount < $min && $amount !== $open) {
            $this->fail(__('An instalment must be at least :min, unless it settles the rest (:open).', [
                'min' => Money::format($min),
                'open' => Money::format($open),
            ]));
        }
    }

    private function storeProof(mixed $proof, Bill $bill): ?string
    {
        if (! $proof instanceof UploadedFile) {
            return null;
        }

        $path = $proof->store("payments/{$bill->id}", 'local');

        if ($path === false) {
            throw new RuntimeException('The payment proof could not be stored.');
        }

        return $path;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['amount' => $message]);
    }
}
