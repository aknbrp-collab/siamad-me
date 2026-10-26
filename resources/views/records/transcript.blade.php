<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Transkrip {{ $student->nim }}</title>
    @include('records.style')
</head>
<body>
    <div class="header">
        <h1>{{ $institution['name'] }}</h1>
        @if ($institution['address'])<p>{{ $institution['address'] }}</p>@endif
    </div>

    <h2>TRANSKRIP NILAI</h2>

    <table class="identity">
        <tr><td>Nama</td><td>: {{ $student->user->name }}</td><td>Program studi</td><td>: {{ $student->studyProgram->name }}</td></tr>
        <tr><td>NIM</td><td>: {{ $student->nim }}</td><td>Angkatan</td><td>: {{ $student->entry_year }}</td></tr>
    </table>

    @forelse ($transcript['semesters'] as $entry)
        <h3>{{ $entry['semester']->label() }}</h3>
        <table class="grades">
            <thead>
                <tr><th>Kode</th><th>Mata kuliah</th><th class="center">SKS</th><th class="center">Huruf</th><th class="center">Bobot</th></tr>
            </thead>
            <tbody>
                @foreach ($entry['rows'] as $row)
                    <tr>
                        <td>{{ $row['code'] }}</td>
                        <td>{{ $row['name'] }}</td>
                        <td class="center">{{ $row['credits'] }}</td>
                        <td class="center">{{ $row['letter'] }}</td>
                        <td class="center">{{ $row['point'] }}</td>
                    </tr>
                @endforeach
                <tr><td colspan="2" class="num">IPS</td><td class="center">{{ $entry['credits'] }}</td><td colspan="2" class="center"><strong>{{ number_format((float) $entry['ip'], 2) }}</strong></td></tr>
            </tbody>
        </table>
    @empty
        <p class="muted">Belum ada nilai final.</p>
    @endforelse

    <table class="summary">
        <tr><td>Total SKS</td><td>: {{ $transcript['credits'] }}</td></tr>
        <tr><td>Indeks Prestasi Kumulatif (IPK)</td><td>: <strong>{{ $transcript['ipk'] !== null ? number_format($transcript['ipk'], 2) : '—' }}</strong></td></tr>
    </table>

    <div class="sign">
        {{ now()->translatedFormat('j F Y') }}<br>Bagian Akademik<br><br><br><br>____________________
    </div>
</body>
</html>
