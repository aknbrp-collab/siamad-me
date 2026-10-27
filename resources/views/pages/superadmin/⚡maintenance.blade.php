<?php

use App\Models\AuditLog;
use App\Models\MaintenanceSession;
use App\Support\MaintenanceMode;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Maintenance mode')] class extends Component {
    use WithPagination;

    public string $reason = '';

    /** The session whose changes are listed; the latest by default. */
    #[Url(as: 'session')]
    public ?int $sessionId = null;

    #[Computed]
    public function current(): ?MaintenanceSession
    {
        return MaintenanceMode::current();
    }

    /**
     * @return Collection<int, MaintenanceSession>
     */
    #[Computed]
    public function sessions(): Collection
    {
        return MaintenanceSession::query()->with(['starter', 'ender'])->withCount('changes')->latest('id')->limit(50)->get();
    }

    #[Computed]
    public function selected(): ?MaintenanceSession
    {
        return $this->sessions->firstWhere('id', $this->sessionId) ?? $this->sessions->first();
    }

    /**
     * @return LengthAwarePaginator<int, AuditLog>|null
     */
    #[Computed]
    public function changes(): ?LengthAwarePaginator
    {
        return $this->selected === null ? null : AuditLog::query()
            ->with('user')
            ->where('maintenance_session_id', $this->selected->id)
            ->latest('id')
            ->paginate(25);
    }

    public function show(int $id): void
    {
        $this->sessionId = $id;
        $this->resetPage();
        unset($this->selected, $this->changes);
    }

    public function turnOn(): void
    {
        $this->resetErrorBag();
        MaintenanceMode::turnOn(auth()->user(), $this->reason);
        $this->reset('reason', 'sessionId');
        $this->refresh();
        Flux::toast(variant: 'warning', text: __('Maintenance mode is on. Closed semesters can be changed until you turn it off.'));
    }

    public function turnOff(): void
    {
        MaintenanceMode::turnOff(auth()->user());
        $this->refresh();
        Flux::toast(variant: 'success', text: __('Maintenance mode is off. Closed semesters are locked again.'));
    }

    private function refresh(): void
    {
        unset($this->current, $this->sessions, $this->selected, $this->changes);
        // The banner is part of the layout: reload the page so it appears or disappears.
        $this->redirect(route('superadmin.maintenance'), navigate: true);
    }

    /**
     * Changed field names of an audit entry, for the list.
     */
    public function fields(AuditLog $log): string
    {
        return collect(array_keys($log->new_values ?? $log->old_values ?? []))->take(6)->implode(', ');
    }
}; ?>

<section class="w-full max-w-5xl">
    <x-page-header :title="__('Maintenance mode')" :subtitle="__('Opens closed semesters and past dates to Admin and Admin Keuangan, to enter data from previous years. Everything changed meanwhile is recorded with the session.')" />

    <flux:card class="mb-8 space-y-4">
        @if ($this->current)
            <div class="flex flex-wrap items-start gap-3">
                <flux:icon.wrench-screwdriver class="size-6 text-amber-500" />
                <div class="min-w-0 flex-1">
                    <flux:heading size="lg">{{ __('On') }}</flux:heading>
                    <flux:text>{{ __('Turned on by :name on :date.', ['name' => $this->current->starter->name, 'date' => $this->current->started_at->translatedFormat('j F Y H:i')]) }}</flux:text>
                    <flux:text class="mt-1 italic">“{{ $this->current->reason }}”</flux:text>
                </div>
                <flux:button variant="primary" icon="lock-closed" wire:click="turnOff" wire:confirm="{{ __('Turn maintenance mode off? Closed semesters are locked again.') }}" data-test="turn-off">{{ __('Turn off') }}</flux:button>
            </div>
        @else
            <form wire:submit="turnOn" class="space-y-4">
                <div>
                    <flux:heading size="lg">{{ __('Off') }}</flux:heading>
                    <flux:text>{{ __('Closed semesters are read-only. Turn maintenance mode on only for a major reason, such as entering the data of a previous academic year, and turn it off when done.') }}</flux:text>
                </div>
                <flux:textarea wire:model="reason" :label="__('Reason')" rows="2" :placeholder="__('e.g. Entering 2025/2026 grades and payments from the paper archive')" required />
                <flux:error name="reason" />
                <flux:button type="submit" variant="danger" icon="wrench-screwdriver" wire:confirm="{{ __('Turn maintenance mode on? Admin and Admin Keuangan can then change closed semesters.') }}" data-test="turn-on">{{ __('Turn on') }}</flux:button>
            </form>
        @endif
    </flux:card>

    <flux:heading size="lg" class="mb-3">{{ __('Sessions') }}</flux:heading>
    <div class="mb-8 overflow-x-auto">
        <flux:table class="table-stack">
            <flux:table.columns>
                <flux:table.column>{{ __('Turned on') }}</flux:table.column>
                <flux:table.column>{{ __('Reason') }}</flux:table.column>
                <flux:table.column>{{ __('Turned off') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Changes') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->sessions as $session)
                    <flux:table.row :key="$session->id" @class(['bg-brand-soft' => $this->selected?->id === $session->id])>
                        <flux:table.cell>
                            <div class="text-sm">{{ $session->started_at->translatedFormat('j M Y H:i') }}</div>
                            <div class="text-xs text-zinc-500">{{ $session->starter->name }}</div>
                        </flux:table.cell>
                        <flux:table.cell class="whitespace-normal text-sm">{{ $session->reason }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($session->ended_at)
                                <div class="text-sm">{{ $session->ended_at->translatedFormat('j M Y H:i') }}</div>
                                <div class="text-xs text-zinc-500">{{ $session->ender?->name }}</div>
                            @else
                                <flux:badge size="sm" color="amber">{{ __('Still on') }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell align="end">
                            <flux:button size="sm" variant="ghost" wire:click="show({{ $session->id }})">{{ trans_choice(':count change|:count changes', $session->changes_count) }}</flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="4" class="text-center">{{ __('Maintenance mode has never been used.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    @if ($this->selected)
        <flux:heading size="lg" class="mb-1">{{ __('Changes in the session of :date', ['date' => $this->selected->started_at->translatedFormat('j F Y')]) }}</flux:heading>
        <flux:text class="mb-3 text-sm">{{ __('Full old and new values are in the Audit log.') }}</flux:text>
        <div class="overflow-x-auto">
            <flux:table class="table-stack" :paginate="$this->changes">
                <flux:table.columns>
                    <flux:table.column>{{ __('Time') }}</flux:table.column>
                    <flux:table.column>{{ __('User') }}</flux:table.column>
                    <flux:table.column>{{ __('Event') }}</flux:table.column>
                    <flux:table.column>{{ __('Record') }}</flux:table.column>
                    <flux:table.column>{{ __('Fields') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($this->changes as $log)
                        <flux:table.row :key="$log->id">
                            <flux:table.cell class="text-sm">{{ $log->created_at->translatedFormat('j M H:i') }}</flux:table.cell>
                            <flux:table.cell class="text-sm">{{ $log->user?->name ?? '—' }}</flux:table.cell>
                            <flux:table.cell><flux:badge size="sm">{{ $log->event }}</flux:badge></flux:table.cell>
                            <flux:table.cell class="font-mono text-xs">{{ class_basename($log->auditable_type) }} #{{ $log->auditable_id }}</flux:table.cell>
                            <flux:table.cell class="whitespace-normal font-mono text-xs text-zinc-500">{{ $this->fields($log) }}</flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="5" class="text-center">{{ __('Nothing was changed in this session.') }}</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    @endif
</section>
