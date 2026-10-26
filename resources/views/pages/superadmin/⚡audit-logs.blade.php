<?php

use App\Models\AuditLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Audit log')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $event = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'event'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return LengthAwarePaginator<int, AuditLog>
     */
    #[Computed]
    public function logs(): LengthAwarePaginator
    {
        return AuditLog::query()
            ->with('user:id,name,username')
            ->when($this->event !== '', fn (Builder $query) => $query->where('event', $this->event))
            ->when($this->search !== '', function (Builder $query) {
                $term = '%'.mb_strtolower($this->search).'%';

                $query->where(fn (Builder $query) => $query
                    ->whereRaw('lower(auditable_type) like ?', [$term])
                    ->orWhere('auditable_id', $this->search)
                    ->orWhereHas('user', fn (Builder $query) => $query
                        ->whereRaw('lower(name) like ?', [$term])
                        ->orWhere('username', 'like', $term)));
            })
            ->latest('id')
            ->paginate(25);
    }

    public function formatValue(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE),
            default => (string) $value,
        };
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl" level="1">{{ __('Audit log') }}</flux:heading>
    <flux:subheading>{{ __('Every change to audited records: who, what, when, and from where.') }}</flux:subheading>

    <div class="mt-6 flex flex-col gap-3 sm:flex-row">
        <flux:input
            wire:model.live.debounce.400ms="search"
            icon="magnifying-glass"
            :placeholder="__('Search user, record type or ID…')"
            class="sm:max-w-sm"
        />
        <flux:select wire:model.live="event" class="sm:max-w-48">
            <flux:select.option value="">{{ __('All events') }}</flux:select.option>
            <flux:select.option value="created">{{ __('Created') }}</flux:select.option>
            <flux:select.option value="updated">{{ __('Updated') }}</flux:select.option>
            <flux:select.option value="deleted">{{ __('Deleted') }}</flux:select.option>
        </flux:select>
    </div>

    <div class="mt-6 flex flex-col gap-3">
        @forelse ($this->logs as $log)
            <div wire:key="log-{{ $log->id }}" class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                    <flux:badge
                        size="sm"
                        :color="match ($log->event) { 'created' => 'green', 'deleted' => 'red', default => 'blue' }"
                    >{{ __(ucfirst($log->event)) }}</flux:badge>
                    <span class="font-medium text-zinc-900 dark:text-white">
                        {{ class_basename($log->auditable_type) }} #{{ $log->auditable_id }}
                    </span>
                    <span class="text-zinc-500 dark:text-zinc-400">
                        {{ $log->user ? $log->user->name.' ('.$log->user->username.')' : __('System') }}
                    </span>
                    <span class="text-zinc-500 sm:ms-auto dark:text-zinc-400" title="{{ $log->created_at }}">
                        {{ $log->created_at->diffForHumans() }} · {{ $log->ip_address ?? '—' }}
                    </span>
                </div>

                @php($keys = array_keys(($log->new_values ?? []) + ($log->old_values ?? [])))
                @if ($keys !== [])
                    <dl class="mt-3 grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[minmax(8rem,auto)_1fr]">
                        @foreach ($keys as $key)
                            <dt class="font-mono text-zinc-500 dark:text-zinc-400">{{ $key }}</dt>
                            <dd class="break-all text-zinc-800 dark:text-zinc-200">
                                @if ($log->event === 'updated')
                                    <span class="text-red-600 line-through dark:text-red-400">{{ $this->formatValue($log->old_values[$key] ?? null) }}</span>
                                    →
                                    <span class="text-green-700 dark:text-green-400">{{ $this->formatValue($log->new_values[$key] ?? null) }}</span>
                                @else
                                    {{ $this->formatValue(($log->new_values ?? $log->old_values)[$key] ?? null) }}
                                @endif
                            </dd>
                        @endforeach
                    </dl>
                @endif
            </div>
        @empty
            <flux:text class="py-8 text-center">{{ __('No audit entries found.') }}</flux:text>
        @endforelse
    </div>

    <div class="mt-6">
        {{ $this->logs->links() }}
    </div>
</section>
