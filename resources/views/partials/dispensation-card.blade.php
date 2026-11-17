{{--
    Surat permohonan dispensasi on a bills page (v7 D47).
    $request (DispensationRequest|null), $granted (bool), $canRequest (whether the semester bill is still
    unpaid), $editable (the mahasantri writes; the wali only reads).
--}}
@if ($request || ($canRequest && $editable))
    <flux:card id="dispensasi" class="space-y-3" data-test="dispensation-card">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <flux:heading>{{ __('Dispensation request') }}</flux:heading>
                <flux:text class="mt-1 text-sm">
                    @if ($request)
                        {{ $request->semester->label() }} · {{ __('written :date', ['date' => $request->updated_at->translatedFormat('j F Y')]) }}
                    @else
                        {{ __('The bill of this semester is not paid off yet. If you need more time, write a request to the Mudir: print it, have it signed, and hand it to the academic office.') }}
                    @endif
                </flux:text>
            </div>
            @if ($request)
                @if ($granted)
                    <flux:badge size="sm" color="green" icon="check">{{ __('Granted') }}</flux:badge>
                @else
                    <flux:badge size="sm" color="amber">{{ __('Waiting for the Mudir') }}</flux:badge>
                @endif
            @endif
        </div>

        @if ($request)
            <div class="whitespace-pre-line rounded-lg bg-zinc-50 px-3 py-2 text-sm dark:bg-zinc-800">{{ $request->reason }}</div>
        @endif

        <div class="flex flex-wrap gap-2">
            @if ($request)
                <flux:button size="sm" variant="primary" icon="printer" :href="route('dispensations.letter', $request)" target="_blank" data-test="print-dispensation">{{ __('Print the letter') }}</flux:button>
            @endif
            @if ($editable && $canRequest)
                <flux:button size="sm" :icon="$request ? 'pencil-square' : 'document-plus'" wire:click="writeDispensation" data-test="write-dispensation">{{ $request ? __('Change the reason') : __('Write a request') }}</flux:button>
            @endif
        </div>
    </flux:card>
@endif
