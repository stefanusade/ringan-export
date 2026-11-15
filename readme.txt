=== Ringan Export ===
Contributors: ringancms
Tags: export, acf, migration, headless, zip
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Ekspor post/CPT WordPress beserta field ACF dan media ke satu ZIP sesuai profil JSON Ringan CMS.

Plugin ini berdiri sendiri di repositori terpisah:
https://github.com/stefanusade/ringan-export

== Description ==

Plugin ini menjembatani WordPress dan Ringan CMS:

1. Ekspor post/CPT + seluruh field ACF + media (gambar/file) ke satu file ZIP.
2. ZIP memuat `manifest.json` (format `ringan-export`) yang berisi profil pemetaan
   dari Ringan CMS, daftar entry, dan indeks media.
3. Unggah ZIP tersebut di Ringan CMS melalui **Tools → Import ZIP**. CMS akan
   memvalidasi manifest, mengimpor media lewat pemeriksaan MIME, lalu membuat
   entri sesuai content type tujuan.

Fitur:

* Profil JSON dapat ditempel manual atau diambil dari endpoint
  `GET /api/v1/{slug}/profile` milik Ringan CMS (opsional dengan `X-API-Key`).
* **Pemetaan otomatis**: setiap field CMS dicocokkan dengan field ACF yang benar-
  benar ada (nama, label, dan tipe) atau sumber `post.*`. Hasilnya bisa diubah
  manual lewat dropdown, dan yang dipakai saat ekspor adalah hasil yang tampil di
  tabel pemetaan.
* Pemetaan field: `post.title`, `post.content`, `post.excerpt`, `post.slug`,
  `post.date`, `post.modified`, `post.status`, `post.author`,
  `post.thumbnail`, atau `acf.<key>`.
* **Pembersihan konten**: penanda blok Gutenberg, shortcode (`[caption]`,
  `[gallery]`, …), atribut tema (`class`, `style`, `srcset`, `data-*`), tag
  kosong, dan spasi berlebih dibuang; URL relatif diubah menjadi absolut agar
  media ikut terbawa. Bisa dimatikan per opsi di halaman plugin.
* **Pratinjau 1 post** sebelum ekspor penuh: menampilkan entry JSON hasil
  pemetaan + pembersihan beserta jumlah field yang terisi dan peringatan.
* Transformasi nilai: text, HTML/richtext, tanggal, angka, boolean, select
  (dengan `value_map`), media, galeri, relasi, dan raw.
* Setiap nilai divalidasi terhadap tipe field CMS sebelum ditulis, sehingga
  Import ZIP tidak menolak arsip karena satu nilai yang salah tipe.
* Media hanya diambil dari folder uploads WordPress (tidak ada pengambilan URL
  eksternal), dengan batas ukuran dan whitelist MIME yang sama seperti CMS.
* Ekspor bertahap (batch 20 post per request, maksimal 5000 post dan 19000 media
  per ekspor) agar aman untuk situs besar.

== Requirements ==

* WordPress 6.0+ dan PHP 8.0+.
* Ekstensi PHP `ZipArchive` (ext-zip).
* Advanced Custom Fields (opsional; tanpa ACF hanya sumber `post.*` yang tersedia).

== Installation ==

1. Salin folder `ringan-export` ke `wp-content/plugins/`.
2. Aktifkan plugin melalui menu Plugins.
3. Buka **Tools → Ringan Export**.
4. Tempel profil JSON dari Ringan CMS (**Content Types → Profil**) atau ambil
   lewat URL `/api/v1/{slug}/profile`. Pemetaan field langsung dijalankan
   otomatis — periksa tabel **Pemetaan field**, ubah bila ada yang keliru, lalu
   klik **Simpan Pemetaan**.
5. Klik **Pratinjau 1 post** untuk memastikan konten dan media sudah benar.
6. Pilih post type dan status, klik **Mulai Ekspor**, lalu unduh ZIP.
7. Di Ringan CMS buka **Tools → Import ZIP** dan unggah ZIP tersebut.

== Notes ==

* Pemetaan otomatis memakai nama, label, dan tipe field. Sumber `acf.*` yang
  tidak terdeteksi tidak dihapus diam-diam: nilainya tetap dibaca dari post meta
  dan ditandai **tidak terdeteksi** agar bisa diganti manual.
* Field yang statusnya **perkiraan** sebaiknya dicek lewat pratinjau sebelum
  ekspor penuh.
* Field repeater/flexible content ACF belum dipetakan otomatis karena Ringan CMS
  belum memiliki tipe field tersebut.
* Relasi ACF diekspor sebagai ID post sumber; CMS mencocokkannya lewat tabel
  `import_refs` (relasi yang belum diimpor akan dilewati dengan peringatan).
* Impor ulang aman: CMS memakai `source_id` (ID post WordPress) untuk mencegah
  duplikasi.

== Changelog ==

= 1.1.0 =
* **Pemetaan otomatis field CMS → WordPress/ACF.** Setiap field profil dicocokkan
  berdasarkan nama, label, dan tipe; sumber `acf.*` yang tidak ada di situs
  dikoreksi ke field ACF yang benar atau ke `post.*` (mis. `judul` → `post.title`,
  `konten` → `post.content`, `gambar_utama` → `post.thumbnail`).
* **Tabel pemetaan di halaman admin** dengan status (otomatis, perkiraan, tetap,
  manual, tidak terdeteksi), alasan pemetaan, dan dropdown untuk mengubah sumber
  per field. Tombol **Jalankan Pemetaan Otomatis** mengulang prosesnya.
* **Pembersihan konten otomatis** saat ekspor: komentar blok Gutenberg,
  shortcode, `class`/`style`/`srcset`/`data-*`, tag kosong, `&nbsp;` berlebih,
  dan iframe (diubah menjadi tautan). URL relatif diubah menjadi absolut supaya
  gambar di konten ikut menjadi media di ZIP. Semua bisa dimatikan lewat opsi.
* **Pratinjau 1 post** (entry JSON hasil pemetaan + pembersihan, jumlah field
  terisi, peringatan) sebelum ekspor penuh.
* **Validasi tipe nilai** sebelum menulis entry, sehingga Import ZIP tidak
  menolak seluruh arsip karena satu nilai yang salah tipe.
* Statistik per field (terisi/kosong) dilaporkan setelah ekspor selesai.
* Opsi profil baru diteruskan: `clean_html`, `clean_shortcodes`,
  `strip_attributes`, `absolute_urls`, dan `duplicates`.

= 1.0.1 =
* Perbaikan: ZIP terunduh 0 byte. Unduhan kini membersihkan output buffer dan
  mematikan `zlib.output_compression` sebelum streaming, serta mengirim isi file
  per potongan (bukan `readfile()`), sehingga `Content-Length` selalu cocok
  dengan byte yang dikirim.
* ZIP tidak lagi dihapus tepat setelah diunduh: unduhan bisa diulang selama sesi
  masih ada (job disimpan 6 jam), dan bila file sementara hilang atau tidak
  lengkap ZIP dibangun ulang otomatis dari data job.
* ZIP dibangun ke file `.part` lalu dipindahkan setelah lolos verifikasi, dan
  unduhan menolak arsip yang tidak memuat `manifest.json` — jadi ZIP separuh
  jadi tidak mungkin lagi terunduh seolah lengkap.
* Jika ZIP benar-benar kosong/tidak terbaca, halaman menampilkan pesan error
  yang jelas (bukan tautan unduhan 0 byte) dan detailnya dicatat di log PHP
  dengan awalan `[ringan-export]`.
* Panel **Diagnostik server** di halaman plugin: versi PHP/WordPress, status
  `ZipArchive`, folder temporary, `zlib.output_compression`, dan batas ekspor.

= 1.0.0 =
* Rilis awal: ekspor post/CPT + ACF + media ke ZIP sesuai profil Ringan CMS.