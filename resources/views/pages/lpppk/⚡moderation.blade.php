<?php

use App\Actions\Discussions\ModerateLpppk;
use App\Models\LpppkSanction;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('LPPPK moderation')] class extends Component {
    public string $search = '';

    public ?int $personId = null;
    public string $action = 'mute';
    public ?string $mutedUntil = null;
    public string $reason = '';

    /**
     * Mahasantri and muhadhir matching the search.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function people(): Collection
    {
        if (mb_strlen(trim($this->search)) < 2) {
            return new Collection;
        }

        $term = '%'.mb_strtolower(trim($this->search)).'%';

        return User::query()
            ->with(['student', 'teacher'])
            ->where(fn (Builder $query) => $query->whereHas('student')->orWhereHas('teacher'))
            ->where(fn (Builder $query) => $query
                ->whereRaw('lower(name) like ?', [$term])
                ->orWhereRaw('lower(username) like ?', [$term]))
            ->orderBy('name')
            ->limit(15)
            ->get();
    }

    /**
     * Everyone muted or restricted now.
     *
     * @return Collection<int, LpppkSanction>
     */
    #[Computed]
    public function sanctions(): Collection
    {
        return LpppkSanction::query()
            ->with(['user', 'decider'])
            ->where(fn (Builder $query) => $query->whereNotNull('restricted_at')
                ->orWhere(fn (Builder $query) => $query->whereNotNull('muted_at')->where(fn (Builder $query) => $query->whereNull('muted_until')->orWhere('muted_until', '>', now()))))
            ->latest('updated_at')
            ->get();
    }

    #[Computed]
    public function person(): ?User
    {
        return $this->personId ? User::query()->find($this->personId) : null;
    }

    public function start(int $userId, string $action): void
    {
        $this->personId = $userId;
        $this->action = $action === 'restrict' ? 'restrict' : 'mute';
        $this->mutedUntil = null;
        $this->reason = '';
        $this->resetErrorBag();
        unset($this->person);
        Flux::modal('sanction-form')->show();
    }

    public function decide(ModerateLpppk $moderate): void
    {
        $this->resetErrorBag();
        $person = User::query()->findOrFail($this->personId);

        $this->action === 'restrict'
            ? $moderate->restrict($person, $this->reason, auth()->user())
            : $moderate->mute($person, filled($this->mutedUntil) ? Carbon::parse((string) $this->mutedUntil) : null, $this->reason, auth()->user());

        Flux::modal('sanction-form')->close();
        unset($this->sanctions);
        Flux::toast(variant: 'success', text: $this->action === 'restrict' ? __('Access to LPPPK closed.') : __('Muted.'));
    }

    public function lift(int $userId, ModerateLpppk $moderate): void
    {
        $moderate->lift(User::query()->findOrFail($userId), auth()->user());
        unset($this->sanctions);
        Flux::toast(variant: 'success', text: __('They can take part in LPPPK again.'));
    }
}; ?>

<section class="w-full max-w-3xl">
    <x-page-header :title="__('LPPPK moderation')" :subtitle="__('Mute a person in every LPPPK channel (they can still read), or close LPPPK to them. They are told, with your reason. Single messages are removed in the channel itself.')">
        <x-slot:actions>
            <flux:button icon="arrow-left" :href="route('lpppk')" wire:navigate>{{ __('LPPPK') }}</flux:button>
        </x-slot:actions>
    </x-page-header>

    <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Find a mahasantri or muhadhir: name, NIM or NIDM…')" class="mb-2" />
    @if ($this->people->isNotEmpty())
        <div class="mb-8 divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
            @foreach ($this->people as $person)
                <div wire:key="person-{{ $person->id }}" class="flex flex-wrap items-center gap-2 px-3 py-2 text-sm">
                    <span class="me-auto">
                        <span class="font-mono text-zinc-500">{{ $person->username }}</span>
                        <span class="ms-2 font-medium">{{ $person->name }}</span>
                        <span class="ms-1 text-xs text-zinc-500">{{ $person->teacher ? __('Muhadhir') : __('Mahasantri') }}</span>
                    </span>
                    <flux:button size="xs" icon="speaker-x-mark" wire:click="start({{ $person->id }}, 'mute')" data-test="mute-{{ $person->id }}">{{ __('Mute') }}</flux:button>
                    <flux:button size="xs" variant="danger" icon="no-symbol" wire:click="start({{ $person->id }}, 'restrict')" data-test="restrict-{{ $person->id }}">{{ __('Close access') }}</flux:button>
                </div>
            @endforeach
        </div>
    @endif

    <flux:heading class="mb-2 mt-6">{{ __('Muted or without access') }}</flux:heading>
    <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
        @forelse ($this->sanctions as $sanction)
            <div wire:key="sanction-{{ $sanction->id }}" class="flex flex-wrap items-center gap-2 px-3 py-2.5 text-sm">
                <span class="me-auto">
                    <span class="font-medium">{{ $sanction->user->name }}</span>
                    @if ($sanction->isRestricted())
                        <flux:badge size="sm" color="red" class="ms-1">{{ __('No access') }}</flux:badge>
                    @else
                        <flux:badge size="sm" color="amber" class="ms-1">{{ $sanction->muted_until ? __('Muted until :date', ['date' => $sanction->muted_until->translatedFormat('j M H:i')]) : __('Muted') }}</flux:badge>
                    @endif
                    <span class="block text-xs text-zinc-500">{{ $sanction->reason ?? '—' }} · {{ $sanction->decider->name }}, {{ $sanction->updated_at->translatedFormat('j M Y') }}</span>
                </span>
                <flux:button size="xs" wire:click="lift({{ $sanction->user_id }})" wire:confirm="{{ __('Lift this for :name?', ['name' => $sanction->user->name]) }}" data-test="lift-{{ $sanction->user_id }}">{{ __('Lift') }}</flux:button>
            </div>
        @empty
            <flux:text class="p-3 text-sm">{{ __('Nobody is muted or without access.') }}</flux:text>
        @endforelse
    </div>

    <flux:modal name="sanction-form" class="w-full max-w-md">
        <form wire:submit="decide" class="space-y-4">
            <flux:heading size="lg">{{ $action === 'restrict' ? __('Close LPPPK to :name', ['name' => $this->person?->name]) : __('Mute :name in LPPPK', ['name' => $this->person?->name]) }}</flux:heading>
            @if ($action === 'mute')
                <flux:input wire:model="mutedUntil" type="datetime-local" :label="__('Until')" :description:trailing="__('Leave empty to mute until you lift it.')" />
                <flux:error name="mutedUntil" />
            @else
                <flux:text>{{ __('They no longer see LPPPK at all until you lift it.') }}</flux:text>
            @endif
            <flux:textarea wire:model="reason" :label="__('Reason (shown to them)')" rows="2" />
            <flux:error name="reason" />
            <flux:error name="person" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="danger">{{ $action === 'restrict' ? __('Close access') : __('Mute') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
