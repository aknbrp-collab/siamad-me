{{--
    Signature block. $marks (App\Support\PrintMarks), $document (App\Enums\PrintDocument),
    optional $date (Carbon) and $note (a line under the name, e.g. who verified a payment).
    Without a signatory: the old blank line under "Bagian …" for a wet signature.
--}}
@php
    $city = \App\Models\Setting::get('institution.city');
    $signatureFile = $marks->signatureFile();
    $stampFile = $marks->stampFile();
@endphp
<style>
    .ttd { margin-top: 20px; width: 44%; float: right; text-align: center; }
    .ttd .ttd-space { position: relative; height: 62px; }
    .ttd .ttd-sign { position: absolute; left: 50%; margin-left: -60px; top: 2px; height: 58px; width: 120px; }
    .ttd .ttd-stamp { position: absolute; left: 50%; margin-left: -96px; top: -6px; height: 74px; width: auto; opacity: 0.9; }
    .ttd .ttd-name { font-weight: bold; text-decoration: underline; }
    .ttd .ttd-note { color: #71717a; font-size: 8.5px; }
</style>
<div class="ttd">
    {{ $city ? $city.', ' : '' }}{{ ($date ?? now())->translatedFormat('j F Y') }}<br>
    {{ $marks->signatory?->position ?? $document->fallbackTitle() }}
    <div class="ttd-space">
        @if ($stampFile)<img class="ttd-stamp" src="{{ $stampFile }}" alt="">@endif
        @if ($signatureFile)<img class="ttd-sign" src="{{ $signatureFile }}" alt="">@endif
    </div>
    @if ($marks->signatory)
        <span class="ttd-name">{{ $marks->signatory->name }}</span>
    @else
        ____________________
    @endif
    @isset($note)
        <div class="ttd-note">{{ $note }}</div>
    @endisset
</div>
