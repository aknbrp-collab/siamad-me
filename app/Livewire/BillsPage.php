<?php

namespace App\Livewire;

use App\Livewire\Concerns\ConfirmsTransfers;
use App\Models\Bill;
use Livewire\Component;

/**
 * Base for the mahasantri and wali santri bill pages: both show bills with
 * "Confirm transfer". (A PHP class, so static analysis sees the trait.)
 *
 * @property-read Bill|null $confirmingBill
 * @property-read array{open: int, used: int, max: int, min: int}|null $transferAllowance
 * @property-read string|null $bankAccounts
 */
abstract class BillsPage extends Component
{
    use ConfirmsTransfers;
}
