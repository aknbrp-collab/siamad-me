<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $letter->number }}</title>
    @include('records.style')
    <style>
        body { font-size: 11px; line-height: 1.5; }
        .meta td { padding: 1px 6px 1px 0; vertical-align: top; }
        .to { margin-top: 14px; }
        .body { margin-top: 14px; text-align: justify; }
        .body p { margin: 0 0 8px; }
        .void { position: fixed; top: 40%; left: 10%; width: 80%; text-align: center; font-size: 52px; font-weight: bold;
            color: rgba(220, 38, 38, 0.18); transform: rotate(-20deg); }
    </style>
</head>
<body>
    @if ($letter->isCancelled())
        <div class="void">DIBATALKAN</div>
    @endif

    @include('records.letterhead')

    <table class="meta">
        <tr><td>Nomor</td><td>: {{ $letter->number }}</td></tr>
        <tr><td>Lampiran</td><td>: -</td></tr>
        <tr><td>Perihal</td><td>: <strong>{{ $letter->subject_line }}</strong></td></tr>
    </table>

    @if ($letter->recipient)
        <div class="to">Kepada Yth.<br>{!! nl2br(e($letter->recipient)) !!}<br>di tempat</div>
    @endif

    <div class="body">
        @foreach (preg_split('/\R{2,}/', trim($letter->body)) as $paragraph)
            <p>{!! nl2br(e($paragraph)) !!}</p>
        @endforeach
    </div>

    @include('records.signature')

    @if ($letter->isCancelled())
        <div style="clear: both; padding-top: 16px" class="muted">Dibatalkan {{ $letter->cancelled_at->translatedFormat('j F Y') }}: {{ $letter->cancel_reason }}</div>
    @endif
</body>
</html>
