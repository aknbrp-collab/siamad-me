<?php

use App\Actions\Letters\ManageLetters;
use App\Models\IncomingLetter;
use App\Support\SpreadsheetDownload;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

new #[Title('Incoming letters')] class extends Component {
    use WithFileUploads, WithPagination;

    #[Url]
    public ?int $year = null;

    public string $search = '';

    #[Url]
    public bool $openOnly = false;

    public ?int $editingId = null;
    public ?string $receivedOn = null;
    public string $sender = '';
    public ?string $senderNumber = null;
    public ?string $senderDate = null;
    public string $subject = '';
    public $scan = null;
    public ?string $dispositionTo = null;
    public ?string $dispositionNote = null;
    public bool $done = false;

    public function mount(): void
    {
        $this->year ??= now()->year;
    }

    /**
     * @return LengthAwarePaginator<int, IncomingLetter>
     */
    #[Computed]
    public function letters(): LengthAwarePaginator
    {
        $term = '%'.mb_strtolower(trim($this->search)).'%';

        return IncomingLetter::query()
            ->whereYear('received_on', (int) $this->year)
            ->when($this->openOnly, fn ($query) => $query->whereNull('disposition_done_at'))
            ->when(trim($this->search) !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereRaw('lower(agenda_number) like ?', [$term])
                ->orWhereRaw('lower(sender) like ?', [$term])
                ->orWhereRaw('lower(subject) like ?', [$term])
                ->orWhereRaw('lower(coalesce(sender_number, \'\')) like ?', [$term])))
            ->orderByDesc('received_on')->orderByDesc('id')
            ->paginate(25);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        $this->reset('editingId', 'sender', 'senderNumber', 'senderDate', 'subject', 'scan', 'dispositionTo', 'dispositionNote', 'done');
        $this->receivedOn = now()->toDateString();
        $this->resetErrorBag();
        Flux::modal('incoming-form')->show();
    }

    public function edit(int $id): void
    {
        $letter = IncomingLetter::query()->findOrFail($id);
        $this->editingId = $letter->id;
        $this->receivedOn = $letter->received_on->toDateString();
        $this->sender = $letter->sender;
        $this->senderNumber = $letter->sender_number;
        $this->senderDate = $letter->sender_date?->toDateString();
        $this->subject = $letter->subject;
        $this->scan = null;
        $this->dispositionTo = $letter->disposition_to;
        $this->dispositionNote = $letter->disposition_note;
        $this->done = $letter->disposition_done_at !== null;
        $this->resetErrorBag();
        Flux::modal('incoming-form')->show();
    }

    public function save(ManageLetters $letters): void
    {
        $this->resetErrorBag();
        $input = [
            'received_on' => $this->receivedOn,
            'sender' => trim($this->sender),
            'sender_number' => trim((string) $this->senderNumber) ?: null,
            'sender_date' => $this->senderDate ?: null,
            'subject' => trim($this->subject),
            'scan' => $this->scan,
            'disposition_to' => trim((string) $this->dispositionTo) ?: null,
            'disposition_note' => trim((string) $this->dispositionNote) ?: null,
        ];

        $letter = $this->editingId
            ? $letters->updateIncoming(IncomingLetter::query()->findOrFail($this->editingId), $input, $this->done)
            : $letters->recordIncoming($input, auth()->user());

        Flux::modal('incoming-form')->close();
        unset($this->letters);
        Flux::toast(variant: 'success', text: __('Saved as :number.', ['number' => $letter->agenda_number]));
    }

    public function export(): BinaryFileResponse
    {
        $letters = IncomingLetter::query()->whereYear('received_on', (int) $this->year)->orderBy('received_on')->orderBy('id')->get();

        return SpreadsheetDownload::make('agenda-surat-masuk-'.$this->year.'.xlsx', $letters->map(fn (IncomingLetter $letter) => [
            __('Agenda number') => $letter->agenda_number,
            __('Received on') => $letter->received_on->format('Y-m-d'),
            __('Sender') => $letter->sender,
            __('Their number') => $letter->sender_number ?? '',
            __('Their date') => $letter->sender_date?->format('Y-m-d') ?? '',
            __('Subject') => $letter->subject,
            __('Disposition to') => $letter->disposition_to ?? '',
            __('Instruction') => $letter->disposition_note ?? '',
            __('Done') => $letter->disposition_done_at?->format('Y-m-d') ?? '',
        ]));
    }
}; ?>

<section class="w-full">
    <x-page-header :title="__('Incoming letters')" :subtitle="__('Letters received, each with an agenda number, a scan and its disposition.')">
        <x-slot:actions>
            <flux:button size="sm" icon="paper-airplane" :href="route('admin.letters')" wire:navigate>{{ __('Outgoing letters') }}</flux:button>
            <flux:button variant="primary" icon="plus" wire:click="create" data-test="new-incoming">{{ __('Record a letter') }}</flux:button>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-4 flex flex-wrap items-end gap-3">
        <flux:input wire:model.live="year" :label="__('Year')" type="number" class="sm:max-w-28" />
        <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" :placeholder="__('Agenda number, sender, subject…')" class="sm:max-w-64" />
        <flux:checkbox wire:model.live="openOnly" :label="__('Not done yet')" />
        <flux:button size="sm" variant="ghost" icon="arrow-down-tray" wire:click="export" class="ms-auto">{{ __('Excel') }}</flux:button>
    </div>

    <div class="overflow-x-auto">
        <flux:table class="table-stack" :paginate="$this->letters">
            <flux:table.columns>
                <flux:table.column>{{ __('Agenda number') }}</flux:table.column>
                <flux:table.column>{{ __('From / subject') }}</flux:table.column>
                <flux:table.column>{{ __('Disposition') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->letters as $letter)
                    <flux:table.row :key="$letter->id">
                        <flux:table.cell>
                            <span class="font-mono text-sm">{{ $letter->agenda_number }}</span>
                            <div class="text-xs text-zinc-500">{{ $letter->received_on->translatedFormat('j M Y') }}</div>
                        </flux:table.cell>
                        <flux:table.cell class="whitespace-normal">
                            <div class="text-sm font-medium">{{ $letter->sender }}</div>
                            <div class="text-sm">{{ $letter->subject }}</div>
                            @if ($letter->sender_number)<div class="text-xs text-zinc-500">{{ $letter->sender_number }}@if ($letter->sender_date) · {{ $letter->sender_date->translatedFormat('j M Y') }}@endif</div>@endif
                        </flux:table.cell>
                        <flux:table.cell class="whitespace-normal text-sm">
                            @if ($letter->disposition_to)
                                {{ $letter->disposition_to }}
                                @if ($letter->disposition_note)<div class="text-xs text-zinc-500">{{ $letter->disposition_note }}</div>@endif
                            @else
                                —
                            @endif
                            <div class="mt-1">
                                @if ($letter->disposition_done_at)
                                    <flux:badge size="sm" color="green">{{ __('Done') }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="amber">{{ __('Open') }}</flux:badge>
                                @endif
                            </div>
                        </flux:table.cell>
                        <flux:table.cell align="end">
                            @if ($letter->scan_path)
                                <flux:button size="sm" variant="ghost" icon="paper-clip" :href="route('files.show', ['incoming-letter', $letter->id])" target="_blank" :aria-label="__('Scan')" />
                            @endif
                            <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $letter->id }})" :aria-label="__('Edit')" />
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="4" class="text-center">{{ __('No incoming letters in :year yet.', ['year' => $year]) }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:modal name="incoming-form" class="w-full max-w-xl">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingId ? __('Incoming letter') : __('Record a letter') }}</flux:heading>
            <div class="grid gap-5 sm:grid-cols-2">
                <flux:input wire:model="receivedOn" :label="__('Received on')" type="date" required />
                <flux:input wire:model="sender" :label="__('Sender')" required />
                <flux:input wire:model="senderNumber" :label="__('Their letter number')" />
                <flux:input wire:model="senderDate" :label="__('Their letter date')" type="date" />
            </div>
            <flux:textarea wire:model="subject" :label="__('Subject')" rows="2" required />
            <div>
                <flux:label>{{ __('Scan (PDF or photo, optional)') }}</flux:label>
                <input type="file" wire:model="scan" accept=".pdf,.jpg,.jpeg,.png" class="mt-2 block w-full text-sm">
                <flux:error name="scan" />
            </div>
            <flux:separator />
            <flux:heading>{{ __('Disposition') }}</flux:heading>
            <flux:input wire:model="dispositionTo" :label="__('To')" :placeholder="__('e.g. Bagian Akademik')" />
            <flux:textarea wire:model="dispositionNote" :label="__('Instruction')" rows="2" />
            @if ($editingId)
                <flux:checkbox wire:model="done" :label="__('Done')" />
            @endif
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-incoming">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
