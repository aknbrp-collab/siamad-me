<?php

use App\Actions\Letters\ManageLetters;
use App\Enums\LetterSubject;
use App\Models\LetterType;
use App\Models\Signatory;
use App\Models\Stamp;
use App\Support\LetterNumbering;
use App\Support\LetterTemplate;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Letter types')] class extends Component {
    // Numbering (D12).
    public string $numberFormat = '';
    public int $padding = 3;
    public int $startYear;
    public int $startNumber = 1;

    // Letter type form.
    public ?int $typeId = null;
    public string $name = '';
    public string $code = '';
    public string $subject = 'none';
    public bool $requestable = false;
    public string $template = '';
    public ?int $signatoryId = null;
    public ?int $stampId = null;
    public bool $printMarks = true;
    public bool $isActive = true;

    public function mount(): void
    {
        $this->numberFormat = LetterNumbering::format();
        $this->padding = LetterNumbering::padding();
        $this->startYear = now()->year;
        $this->startNumber = LetterNumbering::startFor($this->startYear);
    }

    /**
     * @return Collection<int, LetterType>
     */
    #[Computed]
    public function types(): Collection
    {
        return LetterType::query()->with(['signatory', 'stamp'])->orderByDesc('is_active')->orderBy('name')->get();
    }

    /**
     * @return Collection<int, Signatory>
     */
    #[Computed]
    public function signatories(): Collection
    {
        return Signatory::query()->where('is_active', true)->orderBy('name')->get();
    }

    /**
     * @return Collection<int, Stamp>
     */
    #[Computed]
    public function stamps(): Collection
    {
        return Stamp::query()->where('is_active', true)->orderBy('name')->get();
    }

    /**
     * The next number as it will look with the format typed now (live example).
     */
    #[Computed]
    public function example(): string
    {
        $next = max(LetterNumbering::peek($this->startYear), $this->startNumber);
        $code = $this->types->first()->code ?? 'SKA';

        return LetterNumbering::render($next, $code, now(), $this->numberFormat ?: LetterNumbering::DEFAULT_FORMAT, max(1, min(6, $this->padding)));
    }

    public function updatedStartYear(): void
    {
        $this->startNumber = LetterNumbering::startFor($this->startYear);
    }

    public function saveNumbering(ManageLetters $letters): void
    {
        $this->resetErrorBag();
        $letters->saveNumbering($this->numberFormat, $this->padding, $this->startYear, $this->startNumber);
        Flux::toast(variant: 'success', text: __('Numbering saved.'));
    }

    public function createType(): void
    {
        $this->reset('typeId', 'name', 'code', 'requestable', 'template', 'signatoryId', 'stampId');
        $this->subject = 'none';
        $this->printMarks = true;
        $this->isActive = true;
        $this->resetErrorBag();
        Flux::modal('type-form')->show();
    }

    public function editType(int $id): void
    {
        $type = LetterType::query()->findOrFail($id);
        $this->typeId = $type->id;
        $this->name = $type->name;
        $this->code = $type->code;
        $this->subject = $type->subject->value;
        $this->requestable = $type->requestable;
        $this->template = $type->template;
        $this->signatoryId = $type->signatory_id;
        $this->stampId = $type->stamp_id;
        $this->printMarks = $type->print_marks;
        $this->isActive = $type->is_active;
        $this->resetErrorBag();
        Flux::modal('type-form')->show();
    }

    public function saveType(ManageLetters $letters): void
    {
        $this->resetErrorBag();
        $letters->saveType($this->typeId ? LetterType::query()->findOrFail($this->typeId) : null, [
            'name' => trim($this->name),
            'code' => strtoupper(trim($this->code)),
            'subject' => $this->subject,
            'requestable' => $this->requestable,
            'template' => $this->template,
            'signatory_id' => $this->signatoryId ?: null,
            'stamp_id' => $this->stampId ?: null,
            'print_marks' => $this->printMarks,
            'is_active' => $this->isActive,
        ]);

        Flux::modal('type-form')->close();
        unset($this->types);
        Flux::toast(variant: 'success', text: __('Letter type saved.'));
    }
}; ?>

<section class="w-full max-w-5xl">
    <x-page-header :title="__('Letter types')" :subtitle="__('The kinds of outgoing letters with their template and signer, and how letter numbers are made.')" />

    <flux:card class="mb-8 space-y-4">
        <div>
            <flux:heading>{{ __('Letter numbering') }}</flux:heading>
            <flux:text class="text-sm">{{ __('One counter for all letter types, starting again at 1 every January. Numbers are never reused, not even for cancelled letters.') }}</flux:text>
        </div>

        <form wire:submit="saveNumbering" class="space-y-4">
            <div x-data class="space-y-2">
                <flux:input wire:model.live.debounce.300ms="numberFormat" x-ref="format" :label="__('Number format')" class="font-mono" data-test="number-format" />
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <span class="text-zinc-500">{{ __('Insert:') }}</span>
                    @foreach (LetterNumbering::TOKENS as $token)
                        <flux:button size="xs" type="button" class="font-mono"
                            x-on:click="const el = $refs.format.querySelector('input') ?? $refs.format; const at = el.selectionStart ?? el.value.length; el.value = el.value.slice(0, at) + '{{ $token }}' + el.value.slice(el.selectionEnd ?? at); el.dispatchEvent(new Event('input'));">{{ $token }}</flux:button>
                    @endforeach
                </div>
                <flux:text class="text-xs">{{ __('{nomor} the counter · {kode} the code of the letter type · {bulan-romawi} the month in Roman numerals (Masehi) · {tahun} the year (Masehi). Type the separators and fixed text yourself, e.g. /MA/.') }}</flux:text>
            </div>
            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model.live="padding" :label="__('Digits of the counter')" type="number" min="1" max="6" />
                <flux:input wire:model.live="startYear" :label="__('Year')" type="number" min="2000" max="2100" />
                <flux:input wire:model.live="startNumber" :label="__('Start this year at')" type="number" min="1" :description:trailing="__('To continue from a paper register.')" />
            </div>
            <flux:error name="numberFormat" />
            <flux:error name="startNumber" />
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-brand-soft px-4 py-3">
                <span class="text-sm">{{ __('Next number:') }} <strong class="font-mono" data-test="number-example">{{ $this->example }}</strong></span>
                <flux:button type="submit" variant="primary" size="sm" data-test="save-numbering">{{ __('Save numbering') }}</flux:button>
            </div>
        </form>
    </flux:card>

    <div class="mb-3 flex items-center justify-between">
        <flux:heading size="lg">{{ __('Letter types') }}</flux:heading>
        <flux:button size="sm" variant="primary" icon="plus" wire:click="createType">{{ __('Add letter type') }}</flux:button>
    </div>
    <div class="overflow-x-auto">
        <flux:table class="table-stack">
            <flux:table.columns>
                <flux:table.column>{{ __('Letter type') }}</flux:table.column>
                <flux:table.column>{{ __('About') }}</flux:table.column>
                <flux:table.column>{{ __('Signed by') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($this->types as $type)
                    <flux:table.row :key="$type->id" @class(['opacity-60' => ! $type->is_active])>
                        <flux:table.cell class="whitespace-normal">
                            <span class="font-medium">{{ $type->name }}</span>
                            <span class="ms-1 font-mono text-xs text-zinc-500">{{ $type->code }}</span>
                            @if ($type->requestable)<flux:badge size="sm" color="green" class="ms-1">{{ __('Requestable') }}</flux:badge>@endif
                            @unless ($type->is_active)<flux:badge size="sm" class="ms-1">{{ __('Inactive') }}</flux:badge>@endunless
                        </flux:table.cell>
                        <flux:table.cell class="text-sm">{{ $type->subject->label() }}</flux:table.cell>
                        <flux:table.cell class="text-sm">{{ $type->signatory?->label() ?? '—' }}</flux:table.cell>
                        <flux:table.cell align="end">
                            <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="editType({{ $type->id }})" :aria-label="__('Edit')" />
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:modal name="type-form" class="w-full max-w-3xl">
        <form wire:submit="saveType" class="space-y-5">
            <flux:heading size="lg">{{ $typeId ? __('Edit letter type') : __('Add letter type') }}</flux:heading>
            <div class="grid gap-5 sm:grid-cols-3">
                <flux:input wire:model="name" :label="__('Name')" class="sm:col-span-2" required />
                <flux:input wire:model="code" :label="__('Code')" placeholder="SKA" class="font-mono" required />
            </div>
            <div class="grid gap-5 sm:grid-cols-2">
                <flux:select wire:model.live="subject" :label="__('About')">
                    @foreach (LetterSubject::cases() as $case)
                        <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <div class="pt-7">
                    <flux:checkbox wire:model="requestable" :label="__('Mahasantri can request it')" :disabled="$subject !== 'student'" />
                </div>
            </div>
            <flux:error name="requestable" />
            <flux:textarea wire:model="template" :label="__('Template')" rows="12" class="font-mono text-sm" required />
            <details class="text-sm">
                <summary class="cursor-pointer text-zinc-600 dark:text-zinc-400">{{ __('Placeholders') }}</summary>
                <dl class="mt-2 grid gap-x-4 gap-y-1 sm:grid-cols-[auto_1fr]">
                    @foreach (LetterTemplate::placeholders() as $placeholder => $description)
                        <dt class="font-mono text-xs">{{ $placeholder }}</dt>
                        <dd class="text-xs text-zinc-500">{{ $description }}</dd>
                    @endforeach
                </dl>
            </details>
            <div class="grid gap-5 sm:grid-cols-2">
                <flux:select wire:model="signatoryId" :label="__('Signed by (default)')">
                    <flux:select.option value="">{{ __('— blank signature line —') }}</flux:select.option>
                    @foreach ($this->signatories as $signatory)
                        <flux:select.option :value="$signatory->id">{{ $signatory->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="stampId" :label="__('Stamp (default)')">
                    <flux:select.option value="">{{ __('— none —') }}</flux:select.option>
                    @foreach ($this->stamps as $stamp)
                        <flux:select.option :value="$stamp->id">{{ $stamp->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <div class="flex flex-wrap gap-6">
                <flux:checkbox wire:model="printMarks" :label="__('Print the signature and stamp images')" />
                <flux:checkbox wire:model="isActive" :label="__('Active')" />
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-letter-type">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
