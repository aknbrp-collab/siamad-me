<?php

use App\Actions\Finance\GenerateBills;
use App\Enums\BillStatus;
use App\Enums\Gender;
use App\Models\Bill;
use App\Models\ClassEnrollment;
use App\Models\ClassGroup;
use App\Models\FeeType;
use App\Models\Semester;
use App\Support\ActiveRole;
use App\Support\BillAccess;
use App\Support\InstallmentGrid;
use App\Support\Money;
use App\Support\SpreadsheetDownload;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

new #[Title('Bills')] class extends Component {
    use WithPagination;

    /** Semester to show; ALL_SEMESTERS lists bills of every semester (e.g. arrears of closed years). */
    #[Url(as: 'semester')]
    public ?int $semesterId = null;

    public const ALL_SEMESTERS = 0;

    /** Status filter value for unpaid and partly paid bills together. */
    public const OUTSTANDING = 'outstanding';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $search = '';

    /** list | grid ("Rekap angsuran", v6 D33) */
    #[Url(except: 'list')]
    public string $view = 'list';

    /** Grid: only this class. */
    #[Url(as: 'class')]
    public ?int $classId = null;

    /** Grid: male | female (finance office; a collector already sees one gender). */
    #[Url(except: '')]
    public string $gender = '';

    /** Grid: one fee type (after-review D58); null shows every fee. */
    #[Url(as: 'fee')]
    public ?int $feeTypeId = null;

    public ?string $dueOn = null;

    /** @var array{students: int, net: int, empty: int}|null */
    public ?array $preview = null;

    public function mount(): void
    {
        $this->semesterId ??= Semester::active()?->id ?? Semester::query()->orderByDesc('id')->value('id');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['semesterId', 'status', 'search'], true)) {
            $this->resetPage();
        }

        if ($property === 'semesterId') {
            $this->classId = null;
        }
    }

    /**
     * @return Collection<int, Semester>
     */
    #[Computed]
    public function semesters(): Collection
    {
        return Semester::query()->with('academicYear')->orderByDesc('academic_year_id')->orderByDesc('term')->get();
    }

    #[Computed]
    public function semester(): ?Semester
    {
        return $this->semesters->firstWhere('id', $this->semesterId);
    }

    /**
     * @return Builder<Bill>
     */
    private function query(): Builder
    {
        $term = '%'.mb_strtolower(trim($this->search)).'%';

        return BillAccess::scope(Bill::query(), auth()->user(), ActiveRole::get())
            ->with(['student.user', 'semester.academicYear'])
            ->when($this->semesterId !== self::ALL_SEMESTERS, fn (Builder $query) => $query->where('semester_id', $this->semesterId))
            ->when($this->status === self::OUTSTANDING, fn (Builder $query) => $query->whereIn('status', [BillStatus::Unpaid, BillStatus::Partial]))
            ->when(! in_array($this->status, ['', self::OUTSTANDING], true), fn (Builder $query) => $query->where('status', $this->status))
            ->when(trim($this->search) !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereRaw('lower(number) like ?', [$term])
                ->orWhereHas('student', fn (Builder $query) => $query
                    ->whereRaw('lower(nim) like ?', [$term])
                    ->orWhereHas('user', fn (Builder $query) => $query->whereRaw('lower(name) like ?', [$term])))))
            ->orderBy('number');
    }

    /**
     * @return LengthAwarePaginator<int, Bill>
     */
    #[Computed]
    public function bills(): LengthAwarePaginator
    {
        return $this->query()->paginate(25);
    }

    /**
     * Each bill's class: the student's class in the bill's semester, by bill id.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function classNames(): array
    {
        $classes = ClassEnrollment::query()
            ->whereIn('semester_id', $this->bills->pluck('semester_id')->unique())
            ->whereIn('student_id', $this->bills->pluck('student_id')->unique())
            ->get(['student_id', 'semester_id', 'class_name'])
            ->keyBy(fn (ClassEnrollment $enrollment) => $enrollment->student_id.'-'.$enrollment->semester_id);

        return $this->bills->mapWithKeys(fn (Bill $bill) => [
            $bill->id => $classes->get($bill->student_id.'-'.$bill->semester_id)?->class_name ?? '—',
        ])->all();
    }

    #[Computed]
    public function allSemesters(): bool
    {
        return $this->semesterId === self::ALL_SEMESTERS;
    }

    /**
     * Petugas Pembayaran: the gender of the bills shown (null for the finance office).
     */
    #[Computed]
    public function collectorGender(): ?Gender
    {
        return BillAccess::gender(auth()->user(), ActiveRole::get());
    }

    #[Computed]
    public function administers(): bool
    {
        return BillAccess::administers(ActiveRole::get());
    }

    public function openGenerate(GenerateBills $generate): void
    {
        abort_unless($this->administers, 403);

        $this->resetErrorBag();
        $entries = $generate->preview($this->semester);

        $this->preview = [
            'students' => $entries->where('gross', '>', 0)->count(),
            'net' => (int) $entries->sum('net'),
            'empty' => $entries->where('gross', 0)->count(),
        ];
        $this->dueOn ??= now()->addMonth()->format('Y-m-d');

        Flux::modal('generate-bills')->show();
    }

    public function generate(GenerateBills $generate): void
    {
        abort_unless($this->administers, 403);

        $this->resetErrorBag();
        $this->validate(['dueOn' => ['nullable', 'date']]);

        $created = $generate->handle($this->semester, $this->dueOn ?: null);

        Flux::modal('generate-bills')->close();
        unset($this->bills);
        Flux::toast(variant: 'success', text: trans_choice(':count bill created.|:count bills created.', $created));
    }

    /**
     * @return array{mode: string, fee: FeeType|null, columns: list<array{key: int, label: string}>, rows: \Illuminate\Support\Collection<int, array{bill: Bill, class: string, arrears: int, fees: array<int, array{due: int, paid: int, pending: int, left: int}>, due: int, paid: int, pending: int, left: int, cells: list<string>, used: int, max: int}>}|null
     */
    #[Computed]
    public function grid(): ?array
    {
        if ($this->view !== 'grid' || $this->semester === null) {
            return null;
        }

        $fee = $this->feeTypeId ? FeeType::query()->find($this->feeTypeId) : null;

        return InstallmentGrid::build($this->semester, auth()->user(), ActiveRole::get(), $this->classId, $this->search, Gender::tryFrom($this->gender), $fee);
    }

    /**
     * Fee types billed in the semester shown, for the grid filter.
     *
     * @return \Illuminate\Support\Collection<int, FeeType>
     */
    #[Computed]
    public function feeTypes(): \Illuminate\Support\Collection
    {
        return $this->semester ? InstallmentGrid::feeTypes($this->semester) : collect();
    }

    /**
     * Classes with mahasantri in the semester shown, for the grid filter.
     *
     * @return Collection<int, ClassGroup>
     */
    #[Computed]
    public function classes(): Collection
    {
        $ids = ClassEnrollment::query()->where('semester_id', $this->semesterId)->distinct()->pluck('class_group_id');

        return ClassGroup::withTrashed()->whereKey($ids)->get()->sortBy('name')->values();
    }

    public function updatedView(): void
    {
        $this->classId = null;

        // The grid is per semester.
        if ($this->view === 'grid' && $this->semesterId === self::ALL_SEMESTERS) {
            $this->semesterId = Semester::active()?->id ?? $this->semesters->first()?->id;
        }
    }

    public function exportGrid(): BinaryFileResponse
    {
        abort_if($this->grid === null, 404);
        $grid = $this->grid;
        $mark = ['paid' => 'V', 'pending' => '?', 'none' => '', 'open' => ''];

        // The export follows the view shown (after-review D58).
        $rows = $grid['rows']->map(function (array $row) use ($grid, $mark) {
            $line = [
                __('NIM') => $row['bill']->student->nim,
                __('Name') => $row['bill']->student->user->name,
                __('Class') => $row['class'],
            ];

            if ($grid['mode'] === InstallmentGrid::ALL) {
                foreach ($grid['columns'] as $column) {
                    $amounts = $row['fees'][$column['key']] ?? null;
                    $line[$column['label'].' · '.__('Paid amount')] = $amounts['paid'] ?? null;
                    $line[$column['label'].' · '.__('Left')] = $amounts['left'] ?? null;
                }
                $line[__('Total left')] = $row['left'];
            } else {
                // Assigned one by one: spreading would renumber the numeric keys.
                foreach ($row['cells'] as $index => $cell) {
                    $line[__('Month :n', ['n' => $index + 1])] = $mark[$cell];
                }
                $line[__('Paid amount')] = $row['paid'];
                $line[__('Waiting for verification')] = $row['pending'];
                $line[__('Left')] = $row['left'];
                if ($grid['mode'] === InstallmentGrid::FEE) {
                    $line[__('Instalments')] = $row['used'].'/'.$row['max'];
                }
            }

            $line[__('Previous semesters')] = $row['arrears'];
            $line[__('Status')] = $row['bill']->status->label();

            return $line;
        });

        $suffix = $grid['fee'] ? '-'.\Illuminate\Support\Str::slug($grid['fee']->name) : '';

        return SpreadsheetDownload::make('rekap-angsuran-'.$this->semesterId.$suffix.'.xlsx', $rows);
    }

    public function export(): BinaryFileResponse
    {
        $rows = $this->query()->lazy()->map(fn (Bill $bill) => [
            __('Bill') => $bill->number,
            __('Semester') => $bill->semester->label(),
            __('NIM') => $bill->student->nim,
            __('Name') => $bill->student->user->name,
            __('Net') => $bill->net_amount,
            __('Paid amount') => $bill->paid_amount,
            __('Outstanding') => $bill->status === BillStatus::Void ? 0 : $bill->outstanding(),
            __('Due') => $bill->due_on?->format('Y-m-d'),
            __('Status') => $bill->status->label(),
        ]);

        return SpreadsheetDownload::make('bills-'.now()->format('Ymd').'.xlsx', $rows);
    }
}; ?>

<section class="w-full">
    <x-page-header :title="__('Bills')" :subtitle="__('One bill per mahasantri per semester. Status counts verified payments only.')">
        <x-slot:actions>
            @if ($view === 'grid')
                <flux:button icon="printer" x-on:click="window.print()" class="print:hidden">{{ __('Print') }}</flux:button>
                <flux:button icon="arrow-down-tray" wire:click="exportGrid" class="print:hidden" data-test="export-grid">{{ __('Export') }}</flux:button>
            @else
                <flux:button icon="arrow-down-tray" wire:click="export">{{ __('Export') }}</flux:button>
            @endif
            @if ($this->administers && $this->semester && ! $this->semester->isReadOnly())
                <flux:button variant="primary" icon="sparkles" wire:click="openGenerate">{{ __('Generate bills') }}</flux:button>
            @endif
        </x-slot:actions>
    </x-page-header>

    @unless ($this->administers)
        <flux:callout icon="information-circle" class="mb-4" data-test="collector-scope"
            :text="$this->collectorGender
                ? __('You see and record payments for the bills of :gender mahasantri only. An Admin Keuangan or Admin verifies them.', ['gender' => $this->collectorGender->classLabel()])
                : __('No Putra/Putri scope is set for your account yet. Please contact the Superadmin.')" />
    @endunless

    <flux:radio.group wire:model.live="view" variant="segmented" class="mb-4 w-full sm:w-auto print:hidden">
        <flux:radio value="list" icon="list-bullet" :label="__('Bill list')" />
        <flux:radio value="grid" icon="table-cells" :label="__('Instalment grid')" />
    </flux:radio.group>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row print:hidden">
        <flux:select wire:model.live="semesterId" class="sm:max-w-60">
            @if ($view !== 'grid')
                <flux:select.option :value="0">{{ __('All semesters') }}</flux:select.option>
            @endif
            @foreach ($this->semesters as $semester)
                <flux:select.option :value="$semester->id">{{ $semester->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        @if ($view === 'grid')
            <flux:select wire:model.live="feeTypeId" class="sm:max-w-56" data-test="grid-fee">
                <flux:select.option value="">{{ __('All fees') }}</flux:select.option>
                @foreach ($this->feeTypes as $type)
                    <flux:select.option :value="$type->id">{{ $type->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="classId" class="sm:max-w-56">
                <flux:select.option value="">{{ __('All classes') }}</flux:select.option>
                @foreach ($this->classes as $class)
                    <flux:select.option :value="$class->id">{{ $class->name }}</flux:select.option>
                @endforeach
            </flux:select>
            @if ($this->collectorGender === null)
                <flux:select wire:model.live="gender" class="sm:max-w-40">
                    <flux:select.option value="">{{ __('Putra & Putri') }}</flux:select.option>
                    @foreach (Gender::cases() as $case)
                        <flux:select.option :value="$case->value">{{ $case->classLabel() }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif
        @else
            <flux:select wire:model.live="status" class="sm:max-w-44">
                <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
                <flux:select.option value="outstanding">{{ __('Not fully paid') }}</flux:select.option>
                @foreach (BillStatus::cases() as $case)
                    <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif
        <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" :placeholder="__('Bill number, NIM or name…')" class="sm:max-w-xs" />
    </div>

    @if ($view === 'grid')
        @php($grid = $this->grid)
        <flux:heading class="mb-2 hidden print:block">{{ __('Instalment grid') }} · {{ $this->semester?->label() }}{{ $grid && $grid['fee'] ? ' · '.$grid['fee']->name : '' }}</flux:heading>
        @php($mode = $grid['mode'] ?? InstallmentGrid::ALL)
        <div class="mb-2 flex flex-wrap gap-4 text-xs text-zinc-500 print:hidden">
            @if ($mode === InstallmentGrid::MONTHLY)
                <span><span class="font-semibold text-green-700 dark:text-green-400">✓</span> {{ __('month paid (verified)') }}</span>
                <span><flux:icon.clock variant="micro" class="inline text-amber-500" /> {{ __('waiting for verification') }}</span>
                <span><span class="inline-block size-3 rounded border border-zinc-300 align-middle dark:border-zinc-600"></span> {{ __('not paid yet: click to open the bill') }}</span>
            @else
                <span>{{ __('Paid counts verified payments; left is what is not verified yet.') }}</span>
                <span><flux:icon.clock variant="micro" class="inline text-amber-500" /> {{ __('waiting for verification') }}</span>
            @endif
        </div>
        <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700" data-test="installment-grid" data-mode="{{ $mode }}">
            <table class="min-w-full text-sm">
                <thead class="bg-zinc-50 text-left dark:bg-zinc-800">
                    <tr>
                        <th class="px-3 py-2 font-medium">{{ __('NIM') }}</th>
                        <th class="px-3 py-2 font-medium">{{ __('Name') }}</th>
                        <th class="hidden px-3 py-2 font-medium md:table-cell print:table-cell">{{ __('Class') }}</th>
                        @if ($mode === InstallmentGrid::ALL)
                            @foreach ($grid['columns'] ?? [] as $column)
                                <th class="px-3 py-2 text-end font-medium">{{ $column['label'] }}<div class="text-xs font-normal text-zinc-500">{{ __('paid / left') }}</div></th>
                            @endforeach
                            <th class="px-3 py-2 text-end font-medium">{{ __('Total left') }}</th>
                        @else
                            @foreach ($grid['columns'] ?? [] as $column)
                                <th class="w-10 px-1 py-2 text-center font-medium" title="{{ __('Month :n', ['n' => $column['label']]) }}">{{ $column['label'] }}</th>
                            @endforeach
                            <th class="px-3 py-2 text-end font-medium">{{ __('Paid amount') }}</th>
                            <th class="px-3 py-2 text-end font-medium">{{ __('Left') }}</th>
                            @if ($mode === InstallmentGrid::FEE)
                                <th class="px-3 py-2 text-center font-medium">{{ __('Instalments') }}</th>
                            @endif
                        @endif
                        <th class="px-3 py-2 text-end font-medium">{{ __('Previous semesters') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($grid['rows'] ?? [] as $row)
                        @php($bill = $row['bill'])
                        <tr wire:key="grid-{{ $bill->id }}" @class(['bg-green-50/60 dark:bg-green-900/10' => $row['due'] > 0 && $row['left'] === 0])>
                            <td class="px-3 py-1.5 font-mono text-xs text-zinc-500">{{ $bill->student->nim }}</td>
                            <td class="px-3 py-1.5">
                                <a href="{{ route('finance.bills.show', $bill) }}" wire:navigate class="font-medium hover:underline">{{ $bill->student->user->name }}</a>
                            </td>
                            <td class="hidden px-3 py-1.5 text-xs text-zinc-500 md:table-cell print:table-cell">{{ $row['class'] }}</td>
                            @if ($mode === InstallmentGrid::ALL)
                                @foreach ($grid['columns'] as $column)
                                    @php($amounts = $row['fees'][$column['key']] ?? null)
                                    <td class="px-3 py-1.5 text-end text-xs whitespace-nowrap tabular-nums" data-fee="{{ $column['key'] }}">
                                        @if ($amounts === null)
                                            <span class="text-zinc-300 dark:text-zinc-600">—</span>
                                        @else
                                            <span class="text-green-700 dark:text-green-400">{{ Money::format($amounts['paid']) }}</span>
                                            /
                                            <span @class(['text-red-600 dark:text-red-400' => $amounts['left'] > 0, 'text-zinc-400' => $amounts['left'] === 0])>{{ Money::format($amounts['left']) }}</span>
                                            @if ($amounts['pending'] > 0)
                                                <flux:icon.clock variant="micro" class="inline text-amber-500" title="{{ __('Waiting for verification: :amount', ['amount' => Money::format($amounts['pending'])]) }}" />
                                            @endif
                                        @endif
                                    </td>
                                @endforeach
                                <td class="px-3 py-1.5 text-end font-medium whitespace-nowrap tabular-nums">{{ Money::format($row['left']) }}</td>
                            @else
                                @foreach ($row['cells'] as $index => $cell)
                                    <td class="px-1 py-1 text-center" data-cell="{{ $cell }}">
                                        @switch ($cell)
                                            @case ('paid')
                                                <span class="font-semibold text-green-700 dark:text-green-400" title="{{ __('Month :n paid', ['n' => $index + 1]) }}">✓</span>
                                                @break
                                            @case ('pending')
                                                <a href="{{ route('finance.bills.show', $bill) }}" wire:navigate title="{{ __('Waiting for verification') }}"><flux:icon.clock variant="micro" class="inline text-amber-500" /></a>
                                                @break
                                            @case ('open')
                                                <a href="{{ route('finance.bills.show', $bill) }}" wire:navigate title="{{ __('Record a payment') }}"
                                                    class="inline-block size-4 rounded border border-zinc-300 align-middle hover:border-[var(--color-accent)] hover:bg-[var(--color-accent)]/10 dark:border-zinc-600"></a>
                                                @break
                                            @default
                                                <span class="inline-block size-4 rounded bg-zinc-100 align-middle dark:bg-zinc-800"></span>
                                        @endswitch
                                    </td>
                                @endforeach
                                <td class="px-3 py-1.5 text-end whitespace-nowrap tabular-nums">
                                    {{ Money::format($row['paid']) }}
                                    @if ($row['pending'] > 0)
                                        <flux:icon.clock variant="micro" class="inline text-amber-500" title="{{ __('Waiting for verification: :amount', ['amount' => Money::format($row['pending'])]) }}" />
                                    @endif
                                </td>
                                <td @class(['px-3 py-1.5 text-end whitespace-nowrap tabular-nums', 'text-red-600 dark:text-red-400' => $row['left'] > 0, 'text-zinc-400' => $row['left'] === 0])>{{ Money::format($row['left']) }}</td>
                                @if ($mode === InstallmentGrid::FEE)
                                    <td class="px-3 py-1.5 text-center tabular-nums">{{ $row['used'] }}/{{ $row['max'] }}</td>
                                @endif
                            @endif
                            <td class="px-3 py-1.5 text-end whitespace-nowrap tabular-nums">
                                @if ($row['arrears'] > 0)
                                    <a href="{{ route('finance.bills.index', ['semester' => 0, 'status' => 'outstanding', 'search' => $bill->student->nim]) }}" wire:navigate class="text-red-600 hover:underline">{{ Money::format($row['arrears']) }}</a>
                                @else
                                    <span class="text-zinc-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ 8 + count($grid['columns'] ?? []) }}" class="px-3 py-6 text-center text-zinc-500">{{ __('No bills for this semester yet.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @else
    <div class="overflow-x-auto">
        <flux:table class="table-stack" :paginate="$this->bills">
            <flux:table.columns>
                <flux:table.column>{{ __('Bill') }}</flux:table.column>
                <flux:table.column>{{ __('Mahasantri') }}</flux:table.column>
                @if ($this->allSemesters)
                    <flux:table.column>{{ __('Semester') }}</flux:table.column>
                @endif
                <flux:table.column class="hidden md:table-cell">{{ __('Class') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Net') }}</flux:table.column>
                <flux:table.column align="end" class="hidden sm:table-cell">{{ __('Paid amount') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->bills as $bill)
                    <flux:table.row :key="$bill->id">
                        <flux:table.cell>
                            <flux:link :href="route('finance.bills.show', $bill)" wire:navigate class="font-mono text-sm">{{ $bill->number }}</flux:link>
                        </flux:table.cell>
                        <flux:table.cell>
                            <span class="font-mono text-zinc-500">{{ $bill->student->nim }}</span>
                            <span class="font-medium">{{ $bill->student->user->name }}</span>
                        </flux:table.cell>
                        @if ($this->allSemesters)
                            <flux:table.cell>{{ $bill->semester->label() }}</flux:table.cell>
                        @endif
                        <flux:table.cell class="hidden md:table-cell">{{ $this->classNames[$bill->id] }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ Money::format($bill->net_amount) }}</flux:table.cell>
                        <flux:table.cell align="end" class="hidden tabular-nums sm:table-cell">{{ Money::format($bill->paid_amount) }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm" :color="$bill->status->color()">{{ $bill->status->label() }}</flux:badge></flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="7" class="text-center">{{ $this->allSemesters ? __('No bills found.') : __('No bills for this semester yet.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
    @endif

    @if ($this->administers)
    <flux:modal name="generate-bills" class="w-full max-w-md">
        <form wire:submit="generate" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Generate bills') }}</flux:heading>
                <flux:text class="mt-1">{{ $this->semester?->label() }}</flux:text>
            </div>

            @if ($preview)
                <dl class="grid grid-cols-[1fr_auto] gap-x-4 gap-y-2 rounded-lg bg-zinc-50 p-4 text-sm dark:bg-zinc-800">
                    <dt>{{ __('Mahasantri to bill') }}</dt>
                    <dd class="text-end font-medium">{{ $preview['students'] }}</dd>
                    <dt>{{ __('Total (after scholarships)') }}</dt>
                    <dd class="text-end font-medium tabular-nums">{{ Money::format($preview['net']) }}</dd>
                    @if ($preview['empty'] > 0)
                        <dt class="text-amber-600">{{ __('No matching rate (skipped)') }}</dt>
                        <dd class="text-end text-amber-600">{{ $preview['empty'] }}</dd>
                    @endif
                </dl>
                <flux:text class="text-sm">{{ __('Only active mahasantri placed in a class this semester are billed. Anyone who already has a bill is skipped, so this is safe to run again.') }}</flux:text>
            @endif

            <flux:input wire:model="dueOn" :label="__('Due date')" type="date" />

            @error('semester')
                <flux:callout variant="danger" icon="exclamation-triangle" :text="$message" />
            @enderror

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" :disabled="! $preview || $preview['students'] === 0" data-test="confirm-generate-bills">
                    {{ trans_choice('Create :count bill|Create :count bills', $preview['students'] ?? 0) }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
    @endif
</section>
