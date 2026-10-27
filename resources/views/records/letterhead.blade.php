{{-- The institution's letterhead (kop) for every PDF. Images are file paths for dompdf. --}}
@php($brand = \App\Support\Branding::letterhead())
<style>
    .kop { width: 100%; border-collapse: collapse; border-bottom: 3px double #1b5e20; margin-bottom: 12px; }
    .kop td { vertical-align: middle; padding: 0 0 6px; }
    .kop .kop-logo { width: 64px; }
    .kop .kop-logo img { width: 60px; height: auto; }
    .kop .kop-text { text-align: center; }
    .kop .kop-arabic img { height: 30px; width: auto; }
    .kop .kop-foundation { font-size: 9px; letter-spacing: 1px; text-transform: uppercase; color: #3f3f46; margin: 0; }
    .kop .kop-name { font-size: 15px; font-weight: bold; margin: 1px 0; color: #18181b; }
    .kop .kop-line { font-size: 8.5px; color: #52525b; margin: 1px 0 0; }
</style>
<table class="kop">
    <tr>
        <td class="kop-logo">@if ($brand['logo'])<img src="{{ $brand['logo'] }}" alt="">@endif</td>
        <td class="kop-text">
            @if ($brand['arabic'])<div class="kop-arabic"><img src="{{ $brand['arabic'] }}" alt=""></div>@endif
            @if ($brand['foundation'])<p class="kop-foundation">{{ $brand['foundation'] }}</p>@endif
            <p class="kop-name">{{ $brand['name'] }}</p>
            @if ($brand['licence'])<p class="kop-line">{{ $brand['licence'] }}</p>@endif
            @if ($brand['address'])<p class="kop-line">{{ $brand['address'] }}</p>@endif
            @if ($brand['contact'])<p class="kop-line">{{ $brand['contact'] }}</p>@endif
        </td>
        {{-- Balances the logo so the text stays centred on the page. --}}
        <td class="kop-logo"></td>
    </tr>
</table>
