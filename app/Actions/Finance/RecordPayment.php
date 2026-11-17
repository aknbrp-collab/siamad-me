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
use App\Support\BillFees;
use App\Support\Money;
use App\Support\Upload;
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
 * Each fee is paid on its own (after-review D55): a payment names the fees it pays
 * with an amount for each (`allocations`, bill line id => amount), or it is spread
 * over the fees in the bill's order. Per fee (BillFees::check): at most its number
 * of payments, never more than what is left of it, at least
 * payment.min_installment_amount unless it settles it, and the last allowed payment
 * settles it.
 */
class RecordPayment
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(Bill $bill, User $recorder, array $input, PaymentSource $source = PaymentSource::Staff): Payment
    {
        $data = Validator::make($input, [
            // The total; with allocations it may be left out (it is their sum).
            'amount' => ['nullable', 'required_without:allocations', 'integer', 'min:1'],
            'allocations' => ['nullable', 'array'],
            'allocations.*' => ['nullable', 'integer', 'min:0'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:255', 'required_if:method,'.PaymentMethod::Transfer->value],
            'proof' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', Upload::max()],
            // The account the money goes into (cash book); left empty, it is set when verified.
            'cash_account_id' => ['nullable', 'integer', Rule::exists('cash_accounts', 'id')->where('is_active', true)],
        ], [
            'reference.required_if' => __('Enter the bank reference of the transfer.'),
        ])->validate();

        return DB::transaction(function () use ($bill, $recorder, $data, $source) {
            $bill = Bill::query()->lockForUpdate()->findOrFail($bill->id);
            [$amount, $allocations] = $this->allocations($bill, $data);

            $payment = $bill->payments()->create([
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

            foreach ($allocations as $lineId => $part) {
                $payment->allocations()->create(['bill_line_id' => $lineId, 'amount' => $part]);
            }

            return $payment;
        });
    }

    /**
     * The payment's total and its amount per fee, checked against each fee's rules.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: int, 1: array<int, int>}
     */
    private function allocations(Bill $bill, array $data): array
    {
        if ($bill->status === BillStatus::Void) {
            $this->fail(__('This bill is void.'));
        }

        if (BillFees::open($bill) === 0) {
            $this->fail(__('Nothing is left to pay: the bill is paid or the rest is awaiting verification.'));
        }

        /** @var array<int, int> $given */
        $given = array_filter(array_map('intval', (array) ($data['allocations'] ?? [])), fn (int $amount) => $amount > 0);

        if ($given !== []) {
            $amount = array_sum($given);

            if (isset($data['amount']) && (int) $data['amount'] !== $amount) {
                $this->fail(__('The amount must be the sum of the fees paid (:sum).', ['sum' => Money::format($amount)]));
            }

            BillFees::check($bill, $given);

            return [$amount, $given];
        }

        if (array_key_exists('allocations', $data) && ! isset($data['amount'])) {
            BillFees::check($bill, []);
        }

        // Without a choice of fees: spread over the fees in the bill's order.
        $amount = (int) $data['amount'];
        $spread = BillFees::spread($bill, $amount);
        BillFees::check($bill, $spread, 'amount');

        return [$amount, $spread];
    }

    /**
     * What can still be paid on the bill, the minimum instalment, and each fee with what is left of it.
     *
     * @return array{open: int, min: int, fees: list<array{id: int, description: string, due: int, paid: int, pending: int, left: int, used: int, max: int, months: int|null}>}
     */
    public function allowance(Bill $bill): array
    {
        return [
            'open' => BillFees::open($bill),
            'min' => (int) Setting::get('payment.min_installment_amount', 200000),
            'fees' => array_values(BillFees::of($bill)->map(fn (array $fee) => [
                'id' => $fee['line']->id,
                'description' => $fee['line']->description,
                'due' => $fee['due'],
                'paid' => $fee['paid'],
                'pending' => $fee['pending'],
                'left' => $fee['left'],
                'used' => $fee['used'],
                'max' => $fee['max'],
                'months' => $fee['line']->months,
            ])->all()),
        ];
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
