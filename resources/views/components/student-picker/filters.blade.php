@props([
    'classes' => [],
    'gender' => true,
    'noClass' => false,
    'placeholder' => null,
])

{{-- The filters of a mahasantri picker (v7 D43); the page binds them through PicksStudents.
     Search on top, class and Putra/Putri below: it fits narrow columns and modals as well. --}}
<div {{ $attributes->class('grid grid-cols-2 gap-2') }} data-test="student-picker-filters">
    <div class="col-span-2">
        <flux:input wire:model.live.debounce.300ms="studentSearch" icon="magnifying-glass" size="sm" :placeholder="$placeholder ?? __('Search NIM or name…')" data-test="picker-search" />
    </div>
    <div @class(['min-w-0', 'col-span-2' => ! $gender])>
        <flux:select wire:model.live="pickerClass" size="sm" data-test="picker-class">
            <flux:select.option value="">{{ __('All classes') }}</flux:select.option>
            @if ($noClass)
                <flux:select.option value="{{ \App\Support\StudentPicker::NO_CLASS }}">{{ __('No class yet') }}</flux:select.option>
            @endif
            @foreach ($classes as $class)
                <flux:select.option :value="$class['id']">{{ $class['name'] }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>
    @if ($gender)
        <div class="min-w-0">
            <flux:select wire:model.live="pickerGender" size="sm" data-test="picker-gender">
                <flux:select.option value="">{{ __('Putra & Putri') }}</flux:select.option>
                @foreach (\App\Enums\Gender::cases() as $case)
                    <flux:select.option :value="$case->value">{{ $case->classLabel() }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    @endif
</div>
