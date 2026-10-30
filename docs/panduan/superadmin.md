# Panduan Superadmin

Superadmin dapat membuka semua menu Admin dan Keuangan, ditambah menu **Sistem**. Panduan ini hanya
membahas tugas khusus Superadmin; untuk tugas lain lihat [admin.md](admin.md) dan
[keuangan.md](keuangan.md).

## Akun pertama

Pada instalasi baru, akun Superadmin pertama dibuat oleh pengelola server dengan perintah
`php artisan siakad:superadmin`. Semua akun lain dibuat dari dalam aplikasi.

## Pengguna & peran

**Sistem → Pengguna & peran** menampilkan semua akun login. Cari dengan ID login, nama, atau email;
saring per peran atau status.

**Membuat akun staf** (Admin, Admin Keuangan, Pemantau Akademik, Superadmin):
1. Klik **Tambah akun staf**.
2. Isi **ID login** (huruf, angka, titik, tanda hubung, atau garis bawah), **Nama lengkap**, dan email
   (opsional), lalu centang satu atau beberapa peran.
3. Klik **Buat akun**. Kata sandi sementara tampil **sekali saja**: serahkan langsung atau klik
   **Unduh**. Pengguna wajib menggantinya saat login pertama.

**Memberi peran tambahan.** Buka akun, centang peran staf di bagian **Peran**, lalu **Simpan peran**.
Contoh umum: muhadhir yang juga Pemantau Akademik. Peran berlaku sejak permintaan berikutnya dari pengguna.

Aturan yang dijaga sistem:
- Peran Muhadhir, Mahasantri, dan Wali Santri melekat pada profilnya dan tidak diubah di sini. Nama dan
  biodata orang tersebut diubah di halaman profilnya (tautan tersedia di halaman akun).
- Akun mahasantri dan wali santri tidak dapat diberi peran staf.
- Anda tidak dapat menghapus peran Superadmin atau menonaktifkan login milik Anda sendiri.
- Akun yang memiliki peran staf hanya dapat diatur ulang kata sandinya, dinonaktifkan, atau diubah
  emailnya oleh Superadmin. Admin biasa tidak bisa, agar tidak ada yang dapat mengambil alih akun
  dengan wewenang lebih tinggi.

**Petugas Pembayaran.** Di halaman akun, bagian **Petugas Pembayaran**, pilih *Petugas pembayaran
Putra* atau *Putri* lalu **Simpan**. Orang itu hanya melihat dan mencatat pembayaran tagihan mahasantri
jenis kelamin tersebut, menyetorkan uang tunai, dan tidak dapat memverifikasi. Peran ini boleh diberikan
kepada mahasantri (bukan wali santri). Pilih *Bukan petugas pembayaran* untuk mencabutnya.

**Atur ulang kata sandi / Nonaktifkan login** ada di kartu **Akun login** di setiap halaman akun. Akun
yang dinonaktifkan langsung keluar pada permintaan berikutnya.

## Identitas lembaga

**Sistem → Identitas**: nama dan nama singkat lembaga, yayasan, SK izin operasional, alamat, **kota**
(dicetak sebelum tanggal pada blok tanda tangan), telepon, email, situs web, dan nama pimpinan (Mudir).
Di bagian **Gambar**, unggah:
- **Logo aplikasi**: tampil di menu samping, halaman masuk, tab peramban, dan ikon aplikasi terpasang;
- **Logo lembaga**: di sisi kiri kop surat setiap PDF;
- **Nama dalam kaligrafi Arab**: di atas nama lembaga pada kop surat.

Gunakan PNG berlatar transparan; JPG/WebP juga diterima dan gambar besar diperkecil otomatis.
**Pratinjau kop surat** membuka contoh PDF dengan kop dan blok tanda tangan.

## Tanda tangan & stempel

**Sistem → Tanda tangan & stempel** menyimpan **daftar** penanda tangan (nama, jabatan, bagian, gambar
tanda tangan) dan **daftar** stempel, karena tiap bagian berbeda (mis. Mudir dengan stempel lembaga,
Bendahara dengan stempel keuangan). Penanda tangan/stempel yang tidak dipakai lagi dinonaktifkan, tidak
dihapus.

Di **Bawaan per dokumen**, tentukan untuk KHS, transkrip, kartu ujian, dan kwitansi: siapa yang
menandatangani, stempel mana, dan apakah gambarnya dicetak. Tanpa penanda tangan, PDF mencetak baris
tanda tangan kosong seperti sebelumnya. Mahasantri dan wali selalu mendapat bawaan ini. Staf yang
mencetak akan ditanya: **dengan** gambar tanda tangan dan stempel atau **tanpa** (untuk tanda tangan dan
stempel basah), dan penanda tangan serta stempel mana; bawaan sudah terpilih. Kwitansi yang belum
diverifikasi tidak pernah memuat gambar tanda tangan dan stempel.

## Jenis surat dan penomoran

**Sistem → Jenis surat.**
- **Penomoran surat**: ketik format nomor dan sisipkan bagiannya dengan tombol: `{nomor}` (nomor urut),
  `{kode}` (kode jenis surat), `{bulan-romawi}`, dan `{tahun}` (Masehi); pemisah dan teks tetap (mis.
  `/MA/`) diketik sendiri. Atur jumlah digit nomor urut dan **mulai tahun ini dari nomor** untuk
  melanjutkan register kertas. **Nomor berikutnya** menampilkan contohnya, mis. `001/MA/SKA/X/2026`.
  Satu nomor urut dipakai bersama semua jenis surat dan kembali ke 1 setiap Januari.
- **Jenis surat**: nama, kode, tentang siapa (tidak tertentu / mahasantri / muhadhir), dapat dimohon
  mahasantri atau tidak, **templat** dengan kode isian (daftar lengkap di **Kode isian**, mis.
  `{mahasantri.nama}`, `{mahasantri.nim}`, `{tahun_akademik}`, `{keperluan}`), serta penanda tangan dan
  stempel bawaan. Lima jenis sudah tersedia: Surat Keterangan Aktif Kuliah, Surat Tugas, Surat Undangan,
  Surat Rekomendasi, dan Surat Keterangan Lulus; sesuaikan templatnya.

## Templat Word surat

Di **Jenis surat** setiap jenis menunjukkan **Templat Word** atau **Tata letak PDF bawaan**. Templat Word
diunggah di halaman **Templat** (bagian *Persuratan*). Agar surat dari templat Word menjadi PDF, pasang
**LibreOffice** di server (SIAKAD mencarinya di tempat pemasangan biasa, atau atur `SIAKAD_LIBREOFFICE`
di `.env` ke lokasi `soffice`).

## Pengaturan sistem

**Sistem → Pengaturan**: **rekening untuk transfer** (ditampilkan kepada mahasantri dan wali saat konfirmasi transfer),
catatan kaki kuitansi, **Maksimal cicilan per tagihan** (bawaan 3), **Cicilan minimal (Rp)** (bawaan
200.000), dan **Kehadiran minimal (%)** (bawaan 75). Perubahan berlaku untuk tagihan dan perhitungan
berikutnya; data lama tidak berubah.

## Mode pemeliharaan (input data tahun sebelumnya)

Semester yang sudah ditutup hanya dapat dibaca. Untuk memasukkan data tahun akademik sebelumnya dari
arsip (kelas, nilai, pembayaran), buka **Sistem → Mode pemeliharaan**, tulis **alasannya**, lalu
**Aktifkan**. Selama aktif:
- Admin, Admin Keuangan, dan Superadmin dapat mengubah semester yang ditutup dan menambah **tahun
  akademik sebelumnya** yang kedua semesternya langsung berstatus ditutup;
- muhadhir, pemantau, mahasantri, dan wali tetap tidak bisa mengubah semester yang ditutup;
- setiap halaman staf menampilkan pita kuning berbahasa Inggris: *Maintenance mode: previous-year data
  entry is open. Turned on by … on … — alasan*;
- setiap perubahan tercatat di log audit bersama sesi pemeliharaannya.

Klik **Matikan** setelah selesai; semua kembali terkunci. Halaman ini juga menampilkan riwayat sesi
(siapa, kapan, alasan, kapan dimatikan) dan daftar perubahan pada setiap sesi.

## Wewenang khusus

- **Membuka kembali semester** yang sudah ditutup (**Tahun akademik** → semester → **Buka kembali**),
  wajib dengan alasan. Tutup lagi setelah koreksi selesai.
- **Membatalkan pembayaran terverifikasi** (halaman tagihan → **Batalkan pembayaran terverifikasi**),
  misalnya transfer yang ternyata dibatalkan bank. Kuitansinya menjadi *BATAL* dan tagihan dihitung ulang.
- **Mengubah batas cicilan** satu tagihan (halaman tagihan → **Ubah batas cicilan**), wajib dengan alasan.

## Log audit

**Sistem → Log audit** mencatat setiap perubahan data: siapa, data apa, nilai lama → baru, kapan, dan
dari alamat IP mana. Gunakan untuk menelusuri jika ada data yang dipertanyakan.

## Cadangan data

Cadangan database dan berkas dibuat otomatis setiap hari pukul 02.00 oleh server. Pengaturan,
penyimpanan di luar server, dan cara memulihkan dijelaskan untuk pengelola server di
[`../OPERATIONS.md`](../OPERATIONS.md).
