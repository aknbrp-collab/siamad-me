<?php

use App\Enums\FeeFrequency;
use App\Models\AcademicYear;
use App\Models\ClassGroup;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Semester;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Fees')] class extends Component {
    #[Url(as: 'year')]
    public ?int $academicYearId = null;

    // Fee type form
    public ?int $feeTypeId = null;
    public string $feeName = '';
    public string $frequency = 'per_semester';
    public bool $feeActive = true;

    // Rate form
    public ?int $rateId = null;
    public ?int $rateFeeTypeId = null;
    public ?int $rateClassGroupId = null;
    public ?int $rateEntryYear = null;
    public ?int $rateAmount = null;

    public ?int $copyFromYearId = null;

    public function mount(): void
    {
        $this->academicYearId ??= Semester::active()?->academic_year_id
            ?? AcademicYear::query()->orderByDesc('name')->value('id');
    }

    /**
     * @return Collection<int, FeeType>
     */
    #[Computed]
    public function feeTypes(): Collection
    {
        return FeeType::query()->orderBy('name')->get();
    }

    /**
     * @return Collection<int, AcademicYear>
     */
    #[Computed]
    public function years(): Collection
    {
        return AcademicYear::query()->orderByDesc('name')->get();
    }

    /**
     * @return Collection<int, FeeRate>
     */
    #[Computed]
    public function rates(): Collection
    {
        return FeeRate::query()
            ->with(['feeType', 'classGroup'])
            ->where('academic_year_id', $this->academicYearId)
            ->get()
            ->sortBy(fn (FeeRate $rate) => $rate->feeType->name.'|'.$rate->specificity())
            ->values();
    }

    /**
     * @return Collection<int, ClassGroup>
     */
    #[Computed]
    public function classGroups(): Collection
    {
        return ClassGroup::query()->orderBy('level')->orderBy('name')->get();
    }

    public function createFeeType(): void
    {
        $this->reset('feeTypeId', 'feeName');
        $this->frequency = FeeFrequency::PerSemester->value;
        $this->feeActive = true;
        $this->resetErrorBag();
        Flux::modal('fee-type-form')->show();
    }

    public function editFeeType(int $id): void
    {
        $feeType = FeeType::query()->findOrFail($id);

        $this->feeTypeId = $feeType->id;
        $this->feeName = $feeType->name;
        $this->frequency = $feeType->frequency->value;
        $this->feeActive = $feeType->is_active;
        $this->resetErrorBag();
        Flux::modal('fee-type-form')->show();
    }

    public function saveFeeType(): void
    {
        $data = $this->validate([
            'feeName' => ['required', 'string', 'max:100', Rule::unique('fee_types', 'name')->ignore($this->feeTypeId)],
            'frequency' => ['required', Rule::enum(FeeFrequency::class)],
            'feeActive' => ['boolean'],
        ]);

        FeeType::query()->updateOrCreate(['id' => $this->feeTypeId], [
            'name' => $data['feeName'],
            'frequency' => $data['frequency'],
            'is_active' => $data['feeActive'],
        ]);

        Flux::modal('fee-type-form')->close();
        unset($this->feeTypes, $this->rates);
        Flux::toast(variant: 'success', text: __('Fee type saved. Existing bills keep their amounts.'));
    }

    public function createRate(): void
    {
        $this->reset('rateId', 'rateClassGroupId', 'rateEntryYear', 'rateAmount');
        $this->rateFeeTypeId = $this->feeTypes->firstWhere('is_active', true)?->id;
        $this->resetErrorBag();
        Flux::modal('rate-form')->show();
    }

    public function editRate(int $id): void
    {
        $rate = FeeRate::query()->findOrFail($id);

        $this->rateId = $rate->id;
        $this->rateFeeTypeId = $rate->fee_type_id;
        $this->rateClassGroupId = $rate->class_group_id;
        $this->rateEntryYear = $rate->entry_year;
        $this->rateAmount = $rate->amount;
        $this->resetErrorBag();
        Flux::modal('rate-form')->show();
    }

    public function saveRate(): void
    {
        $data = $this->validate([
            'academicYearId' => ['required', 'integer', Rule::exists('academic_years', 'id')],
            'rateFeeTypeId' => ['required', 'integer', Rule::exists('fee_types', 'id')],
            'rateClassGroupId' => ['nullable', 'integer', Rule::exists('class_groups', 'id')],
            'rateEntryYear' => ['nullable', 'integer', 'between:1990,2100'],
            'rateAmount' => ['required', 'integer', 'min:0', 'max:1000000000'],
        ]);

        $duplicate = FeeRate::query()
            ->where('academic_year_id', $data['academicYearId'])
            ->where('fee_type_id', $data['rateFeeTypeId'])
            ->where('class_group_id', $data['rateClassGroupId'])
            ->where('entry_year', $data['rateEntryYear'])
            ->when($this->rateId, fn ($query) => $query->whereKeyNot($this->rateId))
            ->exists();

        if ($duplicate) {
            $this->addError('rateAmount', __('A rate for this fee, class and entry year already exists in this academic year.'));

            return;
        }

        FeeRate::query()->updateOrCreate(['id' => $this->rateId], [
            'academic_year_id' => $data['academicYearId'],
            'fee_type_id' => $data['rateFeeTypeId'],
            'class_group_id' => $data['rateClassGroupId'],
            'entry_year' => $data['rateEntryYear'],
            'amount' => $data['rateAmount'],
        ]);

        Flux::modal('rate-form')->close();
        unset($this->rates);
        Flux::toast(variant: 'success', text: __('Rate saved. It applies to bills generated from now on.'));
    }

    public function deleteRate(int $id): void
    {
        FeeRate::query()->findOrFail($id)->delete();

        unset($this->rates);
        Flux::toast(variant: 'success', text: __('Rate removed. Existing bills keep their amounts.'));
    }

    public function copyRates(): void
    {
        $this->validate(['copyFromYearId' => ['required', 'integer', Rule::exists('academic_years', 'id'), 'different:academicYearId']]);

        $copied = 0;

        foreach (FeeRate::query()->where('academic_year_id', $this->copyFromYearId)->get() as $rate) {
            $exists = FeeRate::query()
                ->where('academic_year_id', $this->academicYearId)
                ->where('fee_type_id', $rate->fee_type_id)
                ->where('class_group_id', $rate->class_group_id)
                ->where('entry_year', $rate->entry_year)
                ->exists();

            if (! $exists) {
                FeeRate::create([...$rate->only(['fee_type_id', 'class_group_id', 'entry_year', 'amount']), 'academic_year_id' => $this->academicYearId]);
                $copied++;
            }
        }

        Flux::modal('copy-rates')->close();
        unset($this->rates);
        Flux::toast(variant: 'success', text: trans_choice(':count rate copied.|:count rates copied.', $copied));
    }
}; ?>

<section class="w-full">
    <x-page-header :title="__('Fees')" :subtitle="__('Fee types and their amounts per academic year. The most specific rate (class + entry year) wins when bills are generated.')" />

    <div class="grid gap-8 xl:grid-cols-[22rem_1fr]">
        <div>
            <div class="mb-3 flex items-center justify-between">
                <flux:heading size="lg">{{ __('Fee types') }}</flux:heading>
                <flux:button size="sm" icon="plus" wire:click="createFeeType">{{ __('Add') }}</flux:button>
            </div>
            <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                @forelse ($this->feeTypes as $feeType)
                    <button type="button" wire:key="fee-{{ $feeType->id }}" wire:click="editFeeType({{ $feeType->id }})"
                        class="flex w-full items-center justify-between gap-3 px-3 py-2 text-start hover:bg-zinc-50 dark:hover:bg-zinc-800">
                        <span>
                            <span class="font-medium">{{ $feeType->name }}</span>
                            <span class="block text-xs text-zinc-500">{{ $feeType->frequency->label() }}</span>
                        </span>
                        @unless ($feeType->is_active)
                            <flux:badge size="sm">{{ __('Inactive') }}</flux:badge>
                        @endunless
                    </button>
                @empty
                    <flux:text class="p-3 text-sm">{{ __('Add fee types such as SPP, Daftar Ulang or Asrama.') }}</flux:text>
                @endforelse
            </div>
        </div>

        <div>
            <div class="mb-3 flex flex-wrap items-center gap-2">
                <flux:heading size="lg" class="me-auto">{{ __('Rates') }}</flux:heading>
                <flux:select wire:model.live="academicYearId" size="sm" class="w-40">
                    @foreach ($this->years as $year)
                        <flux:select.option :value="$year->id">{{ $year->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:modal.trigger name="copy-rates">
                    <flux:button size="sm" icon="document-duplicate" :disabled="$this->years->count() < 2">{{ __('Copy from year') }}</flux:button>
                </flux:modal.trigger>
                <flux:button size="sm" variant="primary" icon="plus" wire:click="createRate" :disabled="! $academicYearId || $this->feeTypes->isEmpty()">{{ __('Add rate') }}</flux:button>
            </div>

            <div class="overflow-x-auto">
                <flux:table class="table-stack">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Fee') }}</flux:table.column>
                        <flux:table.column>{{ __('Applies to') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Amount') }}</flux:table.column>
                        <flux:table.column></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($this->rates as $rate)
                            <flux:table.row :key="$rate->id">
                                <flux:table.cell class="font-medium">
                                    {{ $rate->feeType->name }}
                                    @if ($rate->feeType->frequency === FeeFrequency::Monthly)
                                        <span class="text-xs text-zinc-500">/ {{ __('month') }}</span>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>
                                    {{ $rate->classGroup?->name ?? __('All classes') }}
                                    · {{ $rate->entry_year ? __('Angkatan :year', ['year' => $rate->entry_year]) : __('all angkatan') }}
                                </flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ Money::format($rate->amount) }}</flux:table.cell>
                                <flux:table.cell align="end">
                                    <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="editRate({{ $rate->id }})" :aria-label="__('Edit')" />
                                    <flux:button size="sm" variant="ghost" icon="trash" wire:click="deleteRate({{ $rate->id }})" wire:confirm="{{ __('Remove this rate?') }}" :aria-label="__('Remove')" />
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="4" class="text-center">{{ __('No rates for this academic year yet.') }}</flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </div>
        </div>
    </div>

    <flux:modal name="fee-type-form" class="w-full max-w-md">
        <form wire:submit="saveFeeType" class="space-y-6">
            <flux:heading size="lg">{{ $feeTypeId ? __('Edit fee type') : __('Add fee type') }}</flux:heading>
            <flux:input wire:model="feeName" :label="__('Name')" placeholder="SPP" required />
            <flux:radio.group wire:model="frequency" :label="__('Billed')">
                @foreach (FeeFrequency::cases() as $case)
                    <flux:radio :value="$case->value" :label="$case->label()" />
                @endforeach
            </flux:radio.group>
            <flux:switch wire:model="feeActive" :label="__('Active (included when generating bills)')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="rate-form" class="w-full max-w-md">
        <form wire:submit="saveRate" class="space-y-6">
            <flux:heading size="lg">{{ $rateId ? __('Edit rate') : __('Add rate') }}</flux:heading>
            <flux:select wire:model="rateFeeTypeId" :label="__('Fee')" required>
                @foreach ($this->feeTypes as $feeType)
                    <flux:select.option :value="$feeType->id">{{ $feeType->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="grid gap-6 sm:grid-cols-2">
                <flux:select wire:model="rateClassGroupId" :label="__('Class')">
                    <flux:select.option value="">{{ __('All classes') }}</flux:select.option>
                    @foreach ($this->classGroups as $classGroup)
                        <flux:select.option :value="$classGroup->id">{{ $classGroup->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="rateEntryYear" :label="__('Angkatan (entry year)')" type="number" :placeholder="__('All')" />
            </div>
            <flux:input wire:model="rateAmount" :label="__('Amount (Rp)')" type="number" min="0" step="1000" required
                :description:trailing="__('For monthly fees, the amount per month.')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="copy-rates" class="w-full max-w-md">
        <form wire:submit="copyRates" class="space-y-6">
            <flux:heading size="lg">{{ __('Copy rates') }}</flux:heading>
            <flux:text>{{ __('Rates of the chosen year are copied into the selected year. Existing rates are kept.') }}</flux:text>
            <flux:select wire:model="copyFromYearId" :label="__('Copy from')" :placeholder="__('Choose a year…')">
                @foreach ($this->years->where('id', '!=', $academicYearId) as $year)
                    <flux:select.option :value="$year->id">{{ $year->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Copy') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
