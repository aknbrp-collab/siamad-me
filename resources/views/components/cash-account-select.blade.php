{{-- The account money goes into (cash book). Hidden while no account exists yet. --}}
@props(['method', 'label'])

@php($accounts = \App\Support\CashBook::accountsFor($method))

@if ($accounts->isNotEmpty())
    <flux:select {{ $attributes }} :label="$label" size="sm">
        <flux:select.option value="">{{ __('— not booked to an account —') }}</flux:select.option>
        @foreach ($accounts as $account)
            <flux:select.option :value="$account->id">{{ $account->label() }}</flux:select.option>
        @endforeach
    </flux:select>
@endif
