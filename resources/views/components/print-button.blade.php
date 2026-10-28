{{--
    A button that opens a PDF. Staff first get the print dialog (with or without
    signature and stamp, which ones); everyone else opens the PDF with the defaults.
--}}
@props(['href', 'document', 'preset' => null])
{{-- $preset: ['signatory' => id|null, 'stamp' => id|null, 'marks' => bool] to preselect instead of the document's defaults (e.g. a letter's own signer). --}}

@if (\App\Support\ActiveRole::get()?->isStaff())
    {{-- Directives such as @js are not compiled inside component attributes, so the event data is echoed. --}}
    <flux:button {{ $attributes }} x-data x-on:click.prevent="$dispatch('print-options', {{ \Illuminate\Support\Js::from(['url' => $href, 'document' => $document->value, 'preset' => $preset]) }})">{{ $slot }}</flux:button>
@else
    <flux:button {{ $attributes }} :href="$href" target="_blank">{{ $slot }}</flux:button>
@endif
