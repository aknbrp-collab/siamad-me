# Panduan Admin (Bagian Akademik)

Admin mengelola data induk, data orang, semester, dan memverifikasi pembayaran.

## Persiapan awal (sekali)

Isi berurutan dari menu **Akademik**:
1. **Program studi** — kode (mis. `MA`) dan nama.
2. **Kelas** — nama kelas dan tingkat (marhalah), mis. *Marhalah 1 A*, tingkat 1.
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
- **Impor** — buat banyak akun mahasantri atau muhadhir sekaligus: **Unduh templat** → isi di Excel →
  unggah → **Periksa berkas** (baris bermasalah ditampilkan) → **Impor**. Setelah impor, lembar
  kredensial berisi ID login dan kata sandi sementara diunduh otomatis. Lembar ini tidak disimpan di
  mana pun: simpan dengan aman lalu bagikan.

Di halaman setiap orang ada kartu **Akun login**: **Atur ulang kata sandi** (membuat kata sandi
sementara baru) dan **Nonaktifkan login**. Jangan menghapus orang; nonaktifkan atau ubah statusnya
(cuti, lulus, keluar).

**Dokumen** — KTP, KK, dan ijazah yang diunggah mahasantri/muhadhir. Periksa lalu **Verifikasi**, atau
**Tolak** dengan catatan (pemilik melihat catatan dan dapat mengunggah ulang).

## Membuka semester baru

1. **Tahun akademik** → buka semester baru.
2. Tab **Kelas**: **Salin dari semester lain** untuk menyalin rombel. Saat membuka semester ganjil,
   mahasantri otomatis dinaikkan satu tingkat (dapat diubah sebelum menyalin). Mahasantri lulus, keluar,
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

## Nilai dan pemantauan

**Pemantauan akademik** menampilkan presensi dan status nilai setiap kelas kuliah. Jika muhadhir perlu
mengoreksi nilai yang sudah final, klik **Buka kunci nilai** pada kelas kuliah itu di halaman
**Pemantauan akademik** dan isi alasannya (hanya selama semester masih terbuka). **Catatan kegiatan** untuk kegiatan pesantren dijelaskan di
[pemantau.md](pemantau.md).

**Rekam akademik** mahasantri (KHS per semester dan transkrip, beserta PDF) dibuka dari halaman
mahasantri.
