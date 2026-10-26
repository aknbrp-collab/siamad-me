<?php

use App\Actions\Finance\ManageScholarshipAwards;
use App\Enums\ScholarshipType;
use App\Enums\StudentStatus;
use App\Models\AcademicYear;
use App\Models\Scholarship;
use App\Models\ScholarshipAward;
use App\Models\Semester;
use App\Models\Student;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Scholarships')] class extends Component {
    #[Url(as: 'year')]
    public ?int $academicYearId = null;

    #[Url(as: 'scholarship')]
    public ?int $selectedId = null;

    // Scholarship form
    public ?int $editingId = null;
    public string $name = '';
    public string $fundingSource = '';
    public string $type = 'percent';
    public ?int $value = null;
    public ?int $quota = null;
    public bool $isActive = true;

    // Award form
    public string $studentSearch = '';
    public ?int $awardSemesterId = null;
    public ?string $awardNote = null;

    public function mount(): void
    {
        $this->academicYearId ??= Semester::active()?->academic_year_id
            ?? AcademicYear::query()->orderByDesc('name')->value('id');
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
     * @return Collection<int, Scholarship>
     */
    #[Computed]
    public function scholarships(): Collection
    {
        return Scholarship::query()
            ->withCount(['awards as recipients_count' => fn ($query) => $query->select(DB::raw('count(distinct student_id)'))])
            ->where('academic_year_id', $this->academicYearId)
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function selected(): ?Scholarship
    {
        return $this->scholarships->firstWhere('id', $this->selectedId);
    }

    /**
     * @return Collection<int, ScholarshipAward>
     */
    #[Computed]
    public function awards(): Collection
    {
        if ($this->selected === null) {
            return new Collection;
        }

        return $this->selected->awards()
            ->with(['student.user', 'semester.academicYear', 'billLine'])
            ->orderBy('semester_id')
            ->get();
    }

    /**
     * @return Collection<int, Semester>
     */
    #[Computed]
    public function openSemesters(): Collection
    {
        return Semester::query()
            ->with('academicYear')
            ->where('academic_year_id', $this->academicYearId)
            ->where('status', '!=', 'closed')
            ->get();
    }

    /**
     * @return Collection<int, Student>
     */
    #[Computed]
    public function candidates(): Collection
    {
        if (mb_strlen(trim($this->studentSearch)) < 2) {
            return new Collection;
        }

        $term = '%'.mb_strtolower(trim($this->studentSearch)).'%';

        return Student::query()
            ->with('user')
            ->where('status', StudentStatus::Active)
            ->where(fn (Builder $query) => $query
                ->whereRaw('lower(nim) like ?', [$term])
                ->orWhereHas('user', fn (Builder $query) => $query->whereRaw('lower(name) like ?', [$term])))
            ->orderBy('nim')
            ->limit(10)
            ->get();
    }

    public function updatedAcademicYearId(): void
    {
        $this->selectedId = null;
    }

    public function select(int $id): void
    {
        $this->selectedId = $id;
        $this->awardSemesterId = $this->openSemesters->firstWhere('status.value', 'active')?->id ?? $this->openSemesters->first()?->id;
        $this->studentSearch = '';
    }

    public function create(): void
    {
        $this->reset('editingId', 'name', 'fundingSource', 'value', 'quota');
        $this->type = ScholarshipType::Percent->value;
        $this->isActive = true;
        $this->resetErrorBag();
        Flux::modal('scholarship-form')->show();
    }

    public function edit(int $id): void
    {
        $scholarship = Scholarship::query()->findOrFail($id);

        $this->editingId = $scholarship->id;
        $this->name = $scholarship->name;
        $this->fundingSource = $scholarship->funding_source;
        $this->type = $scholarship->type->value;
        $this->value = $scholarship->value;
        $this->quota = $scholarship->quota;
        $this->isActive = $scholarship->is_active;
        $this->resetErrorBag();
        Flux::modal('scholarship-form')->show();
    }

    public function save(): void
    {
        $data = $this->validate([
            'academicYearId' => ['required', 'integer', Rule::exists('academic_years', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'fundingSource' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(ScholarshipType::class)],
            'value' => [
                Rule::requiredIf($this->type !== ScholarshipType::Full->value), 'nullable', 'integer', 'min:1',
                $this->type === ScholarshipType::Percent->value ? 'max:100' : 'max:1000000000',
            ],
            'quota' => ['nullable', 'integer', 'min:1'],
            'isActive' => ['boolean'],
        ]);

        $scholarship = Scholarship::query()->updateOrCreate(['id' => $this->editingId], [
            'academic_year_id' => $data['academicYearId'],
            'name' => $data['name'],
            'funding_source' => $data['fundingSource'],
            'type' => $data['type'],
            'value' => $data['type'] === ScholarshipType::Full->value ? 0 : $data['value'],
            'quota' => $data['quota'],
            'is_active' => $data['isActive'],
        ]);

        Flux::modal('scholarship-form')->close();
        unset($this->scholarships);
        $this->select($scholarship->id);
        Flux::toast(variant: 'success', text: __('Scholarship saved. Discounts already on bills are not changed.'));
    }

    public function award(int $studentId, ManageScholarshipAwards $manage): void
    {
        $this->resetErrorBag();

        $this->validate(['awardSemesterId' => ['required', 'integer']], ['awardSemesterId.required' => __('Choose a semester.')]);

        $manage->award(
            Scholarship::query()->findOrFail($this->selectedId),
            Student::query()->findOrFail($studentId),
            Semester::query()->findOrFail($this->awardSemesterId),
            auth()->user(),
            $this->awardNote ?: null,
        );

        $this->studentSearch = '';
        $this->awardNote = null;
        unset($this->awards, $this->scholarships);
        Flux::toast(variant: 'success', text: __('Scholarship awarded.'));
    }

    public function revoke(int $awardId, ManageScholarshipAwards $manage): void
    {
        $this->resetErrorBag();

        $manage->revoke(ScholarshipAward::query()->where('scholarship_id', $this->selectedId)->findOrFail($awardId));

        unset($this->awards, $this->scholarships);
        Flux::toast(variant: 'success', text: __('Award withdrawn and removed from the bill.'));
    }
}; ?>

<section class="w-full">
    <x-page-header :title="__('Scholarships')" :subtitle="__('Fee waivers by funding source. Awards are per semester and are applied to the bill as a discount line.')">
        <x-slot:actions>
            <flux:select wire:model.live="academicYearId" class="w-40">
                @foreach ($this->years as $year)
                    <flux:select.option :value="$year->id">{{ $year->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:button variant="primary" icon="plus" wire:click="create" :disabled="! $academicYearId">{{ __('Add scholarship') }}</flux:button>
        </x-slot:actions>
    </x-page-header>

    @foreach (['award', 'semester'] as $key)
        @error($key)
            <flux:callout variant="danger" icon="exclamation-triangle" class="mb-4" :text="$message" />
        @enderror
    @endforeach

    <div class="grid gap-6 lg:grid-cols-[22rem_1fr]">
        <div class="flex flex-col gap-2">
            @forelse ($this->scholarships as $scholarship)
                <button type="button" wire:key="scholarship-{{ $scholarship->id }}" wire:click="select({{ $scholarship->id }})"
                    @class([
                        'rounded-lg border p-3 text-start transition',
                        'border-zinc-400 bg-zinc-50 dark:border-zinc-500 dark:bg-zinc-800' => $selectedId === $scholarship->id,
                        'border-zinc-200 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800' => $selectedId !== $scholarship->id,
                    ])>
                    <div class="flex items-center justify-between gap-2">
                        <span class="font-medium">{{ $scholarship->name }}</span>
                        <flux:badge size="sm" :color="$scholarship->is_active ? 'green' : 'zinc'">{{ $scholarship->valueLabel() }}</flux:badge>
                    </div>
                    <div class="mt-1 text-xs text-zinc-500">
                        {{ $scholarship->funding_source }} ·
                        {{ $scholarship->quota ? __(':used of :quota mahasantri', ['used' => $scholarship->recipients_count, 'quota' => $scholarship->quota]) : trans_choice(':count mahasantri|:count mahasantri', $scholarship->recipients_count) }}
                    </div>
                </button>
            @empty
                <flux:text class="text-sm">{{ __('No scholarships in this academic year.') }}</flux:text>
            @endforelse
        </div>

        <div>
            @if ($this->selected)
                <div class="mb-4 flex items-start justify-between gap-4">
                    <div>
                        <flux:heading size="lg">{{ $this->selected->name }}</flux:heading>
                        <flux:text class="text-sm">{{ $this->selected->type->label() }} · {{ $this->selected->valueLabel() }} · {{ $this->selected->funding_source }}</flux:text>
                    </div>
                    <flux:button size="sm" icon="pencil-square" wire:click="edit({{ $this->selected->id }})">{{ __('Edit') }}</flux:button>
                </div>

                @if ($this->selected->is_active && $this->openSemesters->isNotEmpty())
                    <flux:card class="mb-6 space-y-3">
                        <flux:heading>{{ __('Award to a mahasantri') }}</flux:heading>
                        <div class="grid gap-3 sm:grid-cols-[12rem_1fr]">
                            <flux:select wire:model="awardSemesterId" size="sm">
                                @foreach ($this->openSemesters as $semester)
                                    <flux:select.option :value="$semester->id">{{ $semester->term->label() }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:input wire:model="awardNote" size="sm" :placeholder="__('Note (optional), e.g. SK number')" />
                        </div>
                        <flux:input wire:model.live.debounce.300ms="studentSearch" icon="magnifying-glass" size="sm" :placeholder="__('Find mahasantri by NIM or name…')" />
                        @foreach ($this->candidates as $candidate)
                            <div wire:key="candidate-{{ $candidate->id }}" class="flex items-center justify-between text-sm">
                                <span><span class="font-mono text-zinc-500">{{ $candidate->nim }}</span> {{ $candidate->user->name }}</span>
                                <flux:button size="xs" wire:click="award({{ $candidate->id }})">{{ __('Award') }}</flux:button>
                            </div>
                        @endforeach
                    </flux:card>
                @endif

                <div class="overflow-x-auto">
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Mahasantri') }}</flux:table.column>
                            <flux:table.column>{{ __('Semester') }}</flux:table.column>
                            <flux:table.column align="end">{{ __('On bill') }}</flux:table.column>
                            <flux:table.column></flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @forelse ($this->awards as $award)
                                <flux:table.row :key="$award->id">
                                    <flux:table.cell>
                                        <span class="font-mono text-zinc-500">{{ $award->student->nim }}</span>
                                        <span class="font-medium">{{ $award->student->user->name }}</span>
                                        @if ($award->note)
                                            <div class="text-xs text-zinc-500">{{ $award->note }}</div>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell>{{ $award->semester->term->label() }}</flux:table.cell>
                                    <flux:table.cell align="end" class="tabular-nums">
                                        {{ $award->billLine ? Money::format(-$award->billLine->amount) : __('when billed') }}
                                    </flux:table.cell>
                                    <flux:table.cell align="end">
                                        @unless ($award->semester->isLocked())
                                            <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="revoke({{ $award->id }})" wire:confirm="{{ __('Withdraw this award? The discount is removed from the bill.') }}" :aria-label="__('Withdraw')" />
                                        @endunless
                                    </flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="4" class="text-center">{{ __('No awards yet.') }}</flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                </div>
            @else
                <flux:text class="py-8 text-center">{{ __('Choose a scholarship to see and award it.') }}</flux:text>
            @endif
        </div>
    </div>

    <flux:modal name="scholarship-form" class="w-full max-w-md">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit scholarship') : __('Add scholarship') }}</flux:heading>
            <flux:input wire:model="name" :label="__('Name')" placeholder="Beasiswa Yatim" required />
            <flux:input wire:model="fundingSource" :label="__('Funding source')" placeholder="Yayasan / Pemerintah / Donatur" required />
            <flux:radio.group wire:model.live="type" :label="__('Type')">
                @foreach (ScholarshipType::cases() as $case)
                    <flux:radio :value="$case->value" :label="$case->label()" />
                @endforeach
            </flux:radio.group>
            @if ($type !== ScholarshipType::Full->value)
                <flux:input wire:model="value" type="number" min="1"
                    :label="$type === ScholarshipType::Percent->value ? __('Percentage') : __('Amount (Rp)')" required />
            @endif
            <flux:input wire:model="quota" type="number" min="1" :label="__('Quota (mahasantri)')" :description:trailing="__('Leave empty for no limit.')" />
            <flux:switch wire:model="isActive" :label="__('Active')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
