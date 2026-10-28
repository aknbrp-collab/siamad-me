<?php

use App\Actions\Finance\AddFeeToBills;
use App\Actions\Finance\CopyFeeRates;
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

    // Add a fee to bills that already exist (v4 D23)
    public ?int $addFeeTypeId = null;
    public ?int $addSemesterId = null;

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

    /**
     * Semesters of the selected year whose records can still change.
     *
     * @return Collection<int, Semester>
     */
    #[Computed]
    public function openSemesters(): Collection
    {
        return Semester::query()->with('academicYear')
            ->where('academic_year_id', $this->academicYearId)
            ->orderBy('term')
            ->get()
            ->reject(fn (Semester $semester) => $semester->isReadOnly())
            ->values();
    }

    /**
     * @return array{entries: \Illuminate\Support\Collection<int, array<string, mixed>>, skipped: array{has_fee: int, no_rate: int, once: int}}|null
     */
    #[Computed]
    public function addPreview(): ?array
    {
        $feeType = $this->feeTypes->firstWhere('id', $this->addFeeTypeId);
        $semester = $this->openSemesters->firstWhere('id', $this->addSemesterId);

        return $feeType && $semester ? app(AddFeeToBills::class)->preview($feeType, $semester) : null;
    }

    public function openAddFee(): void
    {
        $this->addFeeTypeId = $this->rates->first(fn (FeeRate $rate) => $rate->feeType->is_active)?->fee_type_id;
        $this->addSemesterId = $this->openSemesters->firstWhere('status.value', 'active')?->id ?? $this->openSemesters->first()?->id;
        $this->resetErrorBag();
        unset($this->addPreview);
        Flux::modal('add-fee')->show();
    }

    public function addFee(AddFeeToBills $add): void
    {
        $this->resetErrorBag();
        $feeType = $this->feeTypes->firstWhere('id', $this->addFeeTypeId);
        $semester = $this->openSemesters->firstWhere('id', $this->addSemesterId);

        if ($feeType === null || $semester === null) {
            $this->addError('addFee', __('Choose a fee and a semester.'));

            return;
        }

        $changed = $add->handle($feeType, $semester, auth()->user());

        Flux::modal('add-fee')->close();
        Flux::toast(variant: 'success', text: trans_choice(':fee added to :count bill.|:fee added to :count bills.', $changed, ['fee' => $feeType->name]));
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

        $result = app(CopyFeeRates::class)->handle(AcademicYear::query()->findOrFail($this->copyFromYearId), AcademicYear::query()->findOrFail($this->academicYearId));

        Flux::modal('copy-rates')->close();
        unset($this->rates);
        Flux::toast(
            variant: $result['skipped'] > 0 ? 'warning' : 'success',
            text: trans_choice(':count rate copied.|:count rates copied.', $result['copied'])
                .($result['skipped'] > 0 ? ' '.trans_choice(':count class rate skipped: no class is at that level in this year yet.|:count class rates skipped: no class is at that level in this year yet.', $result['skipped']) : ''),
        );
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
                <flux:button size="sm" icon="document-plus" wire:click="openAddFee" :disabled="$this->rates->isEmpty() || $this->openSemesters->isEmpty()" data-test="open-add-fee">{{ __('Add to existing bills') }}</flux:button>
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
            <flux:input wire:model="rateAmount" :label="__('Amount (Rp)')" type="number" min="0" step="1" required
                :description:trailing="__('For monthly fees, the amount per month.')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="add-fee" class="w-full max-w-2xl">
        <form wire:submit="addFee" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Add a fee to existing bills') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Bills keep the fees they were generated with. Use this when a fee or rate was added after the bills were made: the fee is added as a new line at the matching rate, and percentage scholarships grow with it. Paid bills become partly paid again.') }}</flux:text>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model.live="addFeeTypeId" :label="__('Fee')" :placeholder="__('Choose…')" required>
                    @foreach ($this->rates->pluck('feeType')->unique('id')->where('is_active', true) as $feeType)
                        <flux:select.option :value="$feeType->id">{{ $feeType->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="addSemesterId" :label="__('Semester')" :placeholder="__('Choose…')" required>
                    @foreach ($this->openSemesters as $semester)
                        <flux:select.option :value="$semester->id">{{ $semester->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            @if ($preview = $this->addPreview)
                @if ($preview['entries']->isEmpty())
                    <flux:callout icon="information-circle" :text="__('No bill gets this fee.')" />
                @else
                    <div class="max-h-72 overflow-y-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                        <table class="min-w-full text-sm">
                            <thead class="sticky top-0 bg-zinc-50 text-left dark:bg-zinc-800">
                                <tr>
                                    <th class="px-3 py-2 font-medium">{{ __('Mahasantri') }}</th>
                                    <th class="px-3 py-2 text-end font-medium">{{ __('Added') }}</th>
                                    <th class="px-3 py-2 text-end font-medium">{{ __('Scholarship') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                                @foreach ($preview['entries'] as $entry)
                                    <tr wire:key="add-{{ $entry['bill']->id }}">
                                        <td class="px-3 py-1.5">
                                            <div class="font-medium">{{ $entry['bill']->student->user->name }}</div>
                                            <div class="text-xs text-zinc-500">{{ $entry['bill']->number }} · {{ $entry['class'] }} · {{ $entry['bill']->status->label() }}</div>
                                        </td>
                                        <td class="px-3 py-1.5 text-end tabular-nums">{{ Money::format($entry['amount']) }}</td>
                                        <td class="px-3 py-1.5 text-end tabular-nums">{{ $entry['discount'] > 0 ? '−'.Money::format($entry['discount']) : '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <flux:text class="text-sm">
                        {{ trans_choice(':count bill gets :amount in total.|:count bills get :amount in total.', $preview['entries']->count(), ['amount' => Money::format((int) $preview['entries']->sum(fn ($entry) => $entry['amount'] - $entry['discount']))]) }}
                    </flux:text>
                @endif
                @if (array_sum($preview['skipped']) > 0)
                    <flux:text class="text-xs">
                        {{ __('Skipped: :has already have it · :none have no matching rate · :once were billed this one-time fee before.', ['has' => $preview['skipped']['has_fee'], 'none' => $preview['skipped']['no_rate'], 'once' => $preview['skipped']['once']]) }}
                    </flux:text>
                @endif
            @endif

            @error('addFee')
                <flux:callout variant="danger" icon="exclamation-triangle" :text="$message" />
            @enderror
            @error('semester')
                <flux:callout variant="danger" icon="exclamation-triangle" :text="$message" />
            @enderror

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" :disabled="! $this->addPreview || $this->addPreview['entries']->isEmpty()" data-test="add-fee">{{ __('Add to bills') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="copy-rates" class="w-full max-w-md">
        <form wire:submit="copyRates" class="space-y-6">
            <flux:heading size="lg">{{ __('Copy rates') }}</flux:heading>
            <flux:text>{{ __('Rates of the chosen year are copied into the selected year. Existing rates are kept. A rate for a class goes to the class at the same level in the selected year, since classes move up.') }}</flux:text>
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
