# Panduan Admin (Bagian Akademik)

Admin mengelola data induk, data orang, semester, dan memverifikasi pembayaran.

## Persiapan awal (sekali)

Isi berurutan dari menu **Akademik**:
1. **Program studi** — kode (mis. `MA`) dan nama.
2. **Kelas** — satu kelas per **tingkat semester** (Semester I, II, III …) dan **Putra** atau **Putri**,
   mis. *Semester I Putra* dan *Semester I Putri*. Mahasantri hanya dapat ditempatkan di kelas yang sesuai
   jenis kelaminnya.
3. **Mata kuliah** — kode, nama, kitab, dan SKS. Mata kuliah yang tidak dipakai lagi cukup
   dinonaktifkan, jangan dihapus.
4. **Skala nilai** — rentang nilai akhir (0–100) menjadi huruf dan bobot. Salah satu rentang harus mulai
   dari 0.
5. **Tahun akademik** → **Tambah tahun akademik** (format `2026/2027`). Semester ganjil dan genap dibuat
   otomatis dengan status *Rencana*.

## Data orang

Menu **Data orang**:
- **Mahasantri** — **Tambah mahasantri**. NIM menjadi ID login; kata sandi sementara tampil sekali
  (serahkan atau unduh).
- **Muhadhir** — **Tambah muhadhir**. NIDM menjadi ID login.
- **Wali santri** — **Tambah wali santri**, lalu hubungkan dengan satu atau beberapa mahasantri. Kode
  login (WS-…) dibuat otomatis. Wali hanya melihat mahasantri yang terhubung.
- **Impor** — buat banyak akun mahasantri atau muhadhir, atau **mata kuliah**, sekaligus: **Unduh
  templat** → isi di Excel → unggah → **Periksa berkas** (baris bermasalah ditampilkan) → **Impor**.
  Untuk akun, lembar kredensial berisi ID login dan kata sandi sementara diunduh otomatis; lembar ini
  tidak disimpan di mana pun: simpan dengan aman lalu bagikan. Mata kuliah diimpor dengan kode, nama,
  kode program studi, SKS, dan kitab; kode yang sudah ada ditolak, tidak ditimpa.
- **Data tahun sebelumnya** — saat Superadmin mengaktifkan **mode pemeliharaan** (pita kuning di atas
  halaman), semester yang ditutup dapat diubah, dan **Tambah tahun akademik** menawarkan pilihan
  **Tahun akademik sebelumnya (input data)** yang kedua semesternya langsung ditutup. Semua perubahan
  tercatat di log audit.

## Data tahun sebelumnya (dari arsip)

Menu **Data orang → Data tahun sebelumnya**, hanya selama mode pemeliharaan aktif. Pilih semester yang
sudah ditutup, lalu:
1. **Penempatan kelas**: unduh templat, isi `nim` dan `class` (nama kelas persis seperti di halaman
   Kelas), unggah, **Periksa berkas**, lalu **Impor**. Mahasantri yang sudah lulus atau keluar tetap
   bisa ditempatkan. Kelas harus sesuai jenis kelamin; mahasantri yang sudah punya kelas di semester itu
   dilaporkan, tidak dipindah.
2. **Nilai akhir**: isi `nim`, `subject_code`, `score` (0–100) dan/atau `letter` (huruf dari arsip,
   bobotnya mengikuti skala nilai), serta `teacher_nidm` bila kelas kuliah mata kuliah itu belum ada di
   SIAKAD (cukup sekali per mata kuliah dan kelas dalam satu berkas). Nilai disimpan sebagai nilai
   akhir sehingga KHS, transkrip, dan IPK langsung lengkap. Nilai yang sudah final tidak ditimpa.

Baris yang bermasalah ditampilkan beserta alasannya dan dilewati; perbaiki lalu impor ulang berkasnya.

**Koreksi satu nilai.** Selama mode pemeliharaan aktif, KHS semester yang ditutup di **Rekam akademik**
mahasantri menampilkan tombol pensil per mata kuliah: isi nilai angka atau huruf dan **alasannya**.
Nilai lama tetap tercatat di log audit.

Di halaman setiap orang ada kartu **Akun login**: **Atur ulang kata sandi** (membuat kata sandi
sementara baru) dan **Nonaktifkan login**. Jangan menghapus orang; nonaktifkan atau ubah statusnya
(cuti, lulus, keluar).

**Dokumen** — KTP, KK, dan ijazah yang diunggah mahasantri/muhadhir. Periksa lalu **Verifikasi**, atau
**Tolak** dengan catatan (pemilik melihat catatan dan dapat mengunggah ulang).

## Jadwal

Jadwal dibuat dari **kelas kuliah**: mata kuliah × kelas × muhadhir × **hari, jam mulai–selesai, ruang**
(halaman semester → tab **Kelas kuliah**). SIAKAD menolak jadwal yang **bentrok**: muhadhir, kelas, atau
ruang yang sama pada jam yang tumpang tindih di hari yang sama; pesannya menyebut kelas kuliah yang
bentrok. Saat menyalin kelas kuliah dari semester lain, yang bentrok tidak disalin dan dilaporkan.

Menu **Akademik → Jadwal** menampilkan jadwal mingguan per **kelas**, **muhadhir**, atau **ruang**, dan
dapat dicetak (**Cetak**).

## Ujian (UTS dan UAS)

Menu **Akademik → Ujian**.

1. **Ruang ujian** (tombol di kanan atas): nama, kapasitas, dan **Putra** atau **Putri**. Mahasantri
   hanya ditempatkan di ruang sesuai jenis kelaminnya.
2. **Tambah masa ujian**: UTS atau UAS dan rentang tanggalnya (satu UTS dan satu UAS per semester).
3. Buka masa ujian → tab **Jadwal ujian** → **Tambah ujian**: mata kuliah (kelas kuliah), tanggal di
   dalam masa ujian, jam mulai–selesai, dan pengawas (boleh kosong). Ujian yang **bentrok** ditolak:
   kelas atau pengawas yang sama pada jam yang tumpang tindih di tanggal yang sama. Ruang boleh dipakai
   bersama: beberapa mata kuliah dapat diujikan di satu ruang pada jam yang sama.
4. Di kartu **Ruang dan kursi**, centang ruang putra dan putri untuk masa ujian ini → **Simpan ruang**
   (jumlah mahasantri dan kursi per putra/putri ditampilkan), lalu **Acak nomor kursi**. Setiap
   mahasantri mendapat **satu ruang dan nomor kursi yang sama untuk semua ujian** di masa ini. Nomor
   kursi dimulai dari 1 di tiap ruang; jika kursi putra/putri kurang, SIAKAD menolak dan menyebut
   jumlahnya. Mengubah ruang atau menambah/menghapus ujian menghapus nomor kursi (acak lagi).
5. **Terbitkan**. Syaratnya nomor kursi sudah diacak. Mahasantri peserta mendapat notifikasi. Setelah
   terbit, jadwal dan kursi terkunci; **Tarik terbitan** untuk mengubahnya lagi.

Tab **Peserta & kartu** menampilkan status tiap mahasantri: ruang dan kursinya, pembayaran, jumlah mata
kuliah yang masuk kartu, dan dispensasi. Syarat kartu ujian: **tagihan semester lunas**, dan hanya mata kuliah dengan
**kehadiran minimal** (Pengaturan, bawaan 75%) yang masuk kartu. Mahasantri yang belum memenuhi syarat
dapat diberi **Dispensasi** berdasarkan surat rektor (nomor, tanggal, catatan): dispensasi
menggugurkan kedua syarat dan tercantum di kartu. Tombol **Kartu** mencetak kartu ujian (PDF A5).

## Pembimbing akademik

Menu **Data orang → Pembimbing akademik**. Pilih muhadhir di kiri, lalu centang mahasantri (saring per
kelas, nama, atau **Hanya yang belum punya pembimbing**) dan klik **Tetapkan kepada …**. Setiap mahasantri
memiliki satu pembimbing pada satu waktu; menetapkan pembimbing lain menutup yang lama, dan riwayatnya
tetap tersimpan. **Akhiri** menghentikan bimbingan (mis. mahasantri lulus). Pembimbing melihat KHS,
presensi, transkrip, dan catatan kegiatan mahasantri bimbingannya, dan berdiskusi di forum bimbingan.

## Membuka semester baru

1. **Tahun akademik** → buka semester baru.
2. Tab **Kelas**: **Salin dari semester lain** untuk menyalin rombel. Setiap semester mahasantri
   otomatis naik satu tingkat ke kelas berjenis kelamin sama (Semester II Putra → Semester III Putra);
   pasangan kelas dapat diubah sebelum menyalin. Mahasantri yang jenis kelaminnya tidak sesuai dengan kelas
   tujuan dilewati dan dapat ditempatkan manual. Saat mencari mahasantri untuk sebuah kelas, hanya yang
   sejenis kelamin yang tampil; tanda *Seharusnya: Semester …* muncul jika tingkatnya tidak sesuai
   (tetap boleh ditempatkan, mis. untuk mengulang). Mahasantri lulus, keluar,
   atau cuti dilewati. Tambahkan mahasantri satu per satu lewat
   **Tambah mahasantri: ketik NIM atau nama…** di kelas yang dipilih.
3. Tab **Kelas kuliah**: **Salin kelas kuliah** dari semester lalu (mata kuliah × kelas × muhadhir ×
   jadwal), lalu sesuaikan, atau **Tambah kelas kuliah**. Peserta kuliah terisi otomatis dari rombel
   kelas.
4. Minta Admin Keuangan **Buat tagihan** semester ini.
5. Tutup semester lama (setelah nilai final), lalu **Aktifkan** semester baru. Hanya satu semester yang
   boleh aktif.

**Menutup semester** (**Tutup semester**) membuat semua rombel, kelas kuliah, presensi, dan nilai
semester itu hanya dapat dibaca. Jika masih ada nilai yang belum final, SIAKAD meminta konfirmasi. Hanya
Superadmin yang dapat membukanya kembali.

## Verifikasi pembayaran

Menu **Verifikasi → Verifikasi pembayaran**. Anda tidak dapat memverifikasi pembayaran yang Anda catat
sendiri.
- **Setoran tunai**: hitung uang yang diserahkan Admin Keuangan. Centang pembayaran hanya jika uangnya
  **tidak ada** (beri alasan), lalu **Hitung & verifikasi**. Yang tidak dicentang menjadi terverifikasi
  dan mendapat kuitansi resmi.
- **Transfer**: cocokkan dengan rekening koran, lalu **Verifikasi** atau **Tolak** dengan alasan.
- Pilih rekening tujuan (**Transfer yang diverifikasi masuk ke**, **Uang tunai masuk ke**) agar
  pembayaran masuk ke buku kas rekening yang benar; rekening bawaan sudah terpilih.

## Nilai dan pemantauan

**Pemantauan akademik** menampilkan presensi dan status nilai setiap kelas kuliah. Jika muhadhir perlu
mengoreksi nilai yang sudah final, klik **Buka kunci nilai** pada kelas kuliah itu di halaman
**Pemantauan akademik** dan isi alasannya (hanya selama semester masih terbuka). **Catatan kegiatan** untuk kegiatan pesantren dijelaskan di
[pemantau.md](pemantau.md).

**Rekam akademik** mahasantri (KHS per semester dan transkrip, beserta PDF) dibuka dari halaman
mahasantri.
