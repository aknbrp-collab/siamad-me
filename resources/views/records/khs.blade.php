<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>KHS {{ $student->nim }}</title>
    @include('records.style')
</head>
<body>
    <div class="header">
        <h1>{{ $institution['name'] }}</h1>
        @if ($institution['address'])<p>{{ $institution['address'] }}</p>@endif
    </div>

    <h2>KARTU HASIL STUDI (KHS)</h2>

    <table class="identity">
        <tr><td>Nama</td><td>: {{ $student->user->name }}</td><td>Semester</td><td>: {{ $semester->label() }}</td></tr>
        <tr><td>NIM</td><td>: {{ $student->nim }}</td><td>Program studi</td><td>: {{ $student->studyProgram->name }}</td></tr>
    </table>

    <table class="grades">
        <thead>
            <tr><th>No</th><th>Kode</th><th>Mata kuliah</th><th class="center">SKS</th><th class="center">Nilai</th><th class="center">Huruf</th><th class="center">Bobot</th><th class="num">SKS × Bobot</th></tr>
        </thead>
        <tbody>
            @forelse ($khs['rows'] as $i => $row)
                <tr>
                    <td class="center">{{ $i + 1 }}</td>
                    <td>{{ $row['code'] }}</td>
                    <td>{{ $row['name'] }}</td>
                    <td class="center">{{ $row['credits'] }}</td>
                    @if ($row['final'])
                        <td class="center">{{ $row['score'] }}</td>
                        <td class="center">{{ $row['letter'] }}</td>
                        <td class="center">{{ $row['point'] }}</td>
                        <td class="num">{{ number_format($row['credits'] * (float) $row['point'], 2) }}</td>
                    @else
                        <td colspan="4" class="center muted">belum final</td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="8" class="center muted">Tidak ada mata kuliah.</td></tr>
            @endforelse
        </tbody>
    </table>

    <table class="summary">
        <tr><td>Jumlah SKS (nilai final)</td><td>: {{ $khs['credits'] }}</td></tr>
        <tr><td>Indeks Prestasi Semester (IPS)</td><td>: <strong>{{ $khs['ip'] !== null ? number_format($khs['ip'], 2) : '—' }}</strong></td></tr>
    </table>

    <div class="sign">
        {{ now()->translatedFormat('j F Y') }}<br>Bagian Akademik<br><br><br><br>____________________
    </div>
</body>
</html>
