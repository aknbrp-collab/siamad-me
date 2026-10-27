<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Kartu Ujian {{ $student->nim }}</title>
    @include('records.style')
    <style>
        .card { border: 1px solid #18181b; padding: 10px; }
        .photo { float: right; width: 72px; height: 92px; border: 1px solid #a1a1aa; text-align: center; }
        .photo img { width: 72px; height: 92px; }
        .note { margin-top: 8px; padding: 5px; border: 1px dashed #71717a; }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $institution['name'] }}</h1>
        @if ($institution['address'])<p>{{ $institution['address'] }}</p>@endif
    </div>

    <h2>KARTU UJIAN {{ $period->type->short() }}</h2>
    <p class="center muted" style="margin-top: -6px">{{ $period->semester->label() }}</p>

    <div class="card">
        <div class="photo">
            @if ($photo)<img src="{{ $photo }}" alt="">@else<br><br><span class="muted">Foto</span>@endif
        </div>
        <table class="identity">
            <tr><td>Nama</td><td>: {{ $student->user->name }}</td></tr>
            <tr><td>NIM</td><td>: {{ $student->nim }}</td></tr>
            <tr><td>Program studi</td><td>: {{ $student->studyProgram->name }}</td></tr>
            <tr><td>Kelas</td><td>: {{ $className ?? '—' }}</td></tr>
        </table>
        <div style="clear: both"></div>

        <table class="grades">
            <thead>
                <tr><th>Mata kuliah</th><th>Waktu</th><th class="center">Ruang</th><th class="center">Kursi</th></tr>
            </thead>
            <tbody>
                @foreach ($subjects as $subject)
                    <tr>
                        <td>{{ $subject['session']->courseOffering->subject_name }}</td>
                        <td>{{ $subject['session']->held_on->translatedFormat('D, j M') }} {{ substr($subject['session']->starts_at, 0, 5) }}–{{ substr($subject['session']->ends_at, 0, 5) }}</td>
                        <td class="center">{{ $subject['seat']->room->name }}</td>
                        <td class="center"><strong>{{ $subject['seat']->seat_number }}</strong></td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if ($dispensation)
            <div class="note">
                Dispensasi berdasarkan surat rektor no. {{ $dispensation->letter_number }}
                tanggal {{ $dispensation->letter_date->translatedFormat('j F Y') }}@if ($dispensation->note) — {{ $dispensation->note }}@endif.
            </div>
        @endif
    </div>

    <p class="muted" style="margin-top: 8px">Bawa kartu ini setiap ujian. Duduk sesuai nomor kursi.</p>

    <div class="sign">
        {{ now()->translatedFormat('j F Y') }}<br>Bagian Akademik<br><br><br><br>____________________
    </div>
</body>
</html>
