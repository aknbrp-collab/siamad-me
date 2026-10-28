<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? __($title).' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

@if ($brandIcon = \App\Support\Branding::appLogoUrl())
    {{-- The uploaded app logo (Superadmin → Identity) as the tab and home-screen icon. --}}
    <link rel="icon" href="{{ route('brand.asset', ['asset' => 'icon-192', 'v' => substr(md5($brandIcon), 0, 8)]) }}" type="image/png">
@else
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
@endif
<link rel="apple-touch-icon" href="{{ route('brand.asset', ['asset' => 'apple-touch-icon']) }}">
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#66BB6A">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="upload-max-kb" content="{{ \App\Support\Upload::maxKb() }}" data-message="{{ __('The file is larger than :size MB. Choose a smaller file.', ['size' => \App\Support\Upload::maxMb()]) }}">
@if (App\Support\WebPush::enabled())
    <meta name="push-key" content="{{ config('siakad.push.public_key') }}">
@endif

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
