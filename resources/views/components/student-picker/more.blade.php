@props(['shown' => 0, 'total' => 0, 'empty' => null])

{{-- Below a mahasantri picker: how many are shown, and the next page (v7 D43). --}}
<div {{ $attributes->class('flex flex-wrap items-center justify-between gap-2 text-xs text-zinc-500') }} data-test="student-picker-more">
    <span>
        @if ($total === 0)
            {{ $empty ?? __('No mahasantri found.') }}
        @else
            {{ __('Showing :shown of :total', ['shown' => $shown, 'total' => $total]) }}
        @endif
    </span>
    @if ($shown < $total)
        <flux:button size="xs" variant="ghost" icon="chevron-down" wire:click="morePickerStudents" data-test="picker-more">{{ __('Load more') }}</flux:button>
    @endif
</div>
