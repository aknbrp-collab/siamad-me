<?php

namespace App\Livewire\Concerns;

use App\Actions\Finance\ConfirmTransfer;
use App\Actions\Finance\RecordPayment;
use App\Models\Bill;
use App\Models\Setting;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * "Konfirmasi transfer" on the mahasantri and wali santri bill pages: the
 * payer reports a bank transfer with its receipt; the finance office
 * verifies it. Validation errors use the action's keys (amount, paid_on,
 * reference, proof).
 */
trait ConfirmsTransfers
{
    use WithFileUploads;

    public ?int $confirmingBillId = null;

    public ?int $transferAmount = null;

    public ?string $transferPaidOn = null;

    public ?string $transferReference = null;

    public ?TemporaryUploadedFile $transferProof = null;

    /**
     * Reload the bills shown on the page after a confirmation.
     */
    abstract protected function refreshBills(): void;

    #[Computed]
    public function confirmingBill(): ?Bill
    {
        return $this->confirmingBillId ? Bill::query()->with('semester.academicYear')->find($this->confirmingBillId) : null;
    }

    /**
     * Open amount, instalments used / allowed and the minimum, for the bill being confirmed.
     *
     * @return array{open: int, used: int, max: int, min: int}|null
     */
    #[Computed]
    public function transferAllowance(): ?array
    {
        return $this->confirmingBill ? app(RecordPayment::class)->allowance($this->confirmingBill) : null;
    }

    #[Computed]
    public function bankAccounts(): ?string
    {
        $accounts = Setting::get('payment.bank_accounts');

        return is_string($accounts) && trim($accounts) !== '' ? trim($accounts) : null;
    }

    public function openTransfer(int $billId, ConfirmTransfer $confirm): void
    {
        $bill = Bill::query()->findOrFail($billId);
        abort_unless($confirm->canPay(auth()->user(), $bill), 403);

        $this->resetErrorBag();
        $this->reset('transferAmount', 'transferReference', 'transferProof');
        $this->confirmingBillId = $bill->id;
        $this->transferPaidOn = now()->toDateString();
        unset($this->confirmingBill, $this->transferAllowance);

        Flux::modal('confirm-transfer')->show();
    }

    public function submitTransfer(ConfirmTransfer $confirm): void
    {
        $this->resetErrorBag();
        $bill = Bill::query()->findOrFail($this->confirmingBillId);

        $confirm->handle($bill, auth()->user(), [
            'amount' => $this->transferAmount,
            'paid_on' => $this->transferPaidOn,
            'reference' => $this->transferReference,
            'proof' => $this->transferProof,
        ]);

        Flux::modal('confirm-transfer')->close();
        $this->reset('confirmingBillId', 'transferAmount', 'transferPaidOn', 'transferReference', 'transferProof');
        $this->refreshBills();

        Flux::toast(variant: 'success', text: __('Transfer sent. The finance office will check it against the bank statement.'));
    }
}
