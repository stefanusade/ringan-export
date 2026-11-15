# Ringan Export

Plugin WordPress untuk mengekspor post/CPT beserta **seluruh field ACF** dan
**media** (gambar/file) ke satu file ZIP, mengikuti profil JSON dari
[Ringan CMS](https://github.com/stefanusade/ringan-cms). ZIP hasil ekspor diunggah
kembali di Ringan CMS lewat **Tools → Import ZIP** — tanpa server-to-server, tanpa
akses database WordPress dari sisi CMS.

## Fitur

- **Satu file ZIP** berisi `manifest.json` (profil + entry + indeks media) dan
  `media/<hash>.<ext>`.
- **Profil dari CMS**: tempel JSON dari **Content Types → Profil** di Ringan CMS,
  atau ambil langsung dari `GET /api/v1/{slug}/profile` (opsional dengan
  `X-API-Key`).
- **Pemetaan field**: `post.title`, `post.content`, `post.excerpt`, `post.slug`,
  `post.date`, `post.modified`, `post.status`, `post.author`,
  `post.thumbnail`, atau `acf.<key>`.
- **Transformasi nilai**: text, HTML/richtext, tanggal, angka, boolean, select
  (dengan `value_map`), media, galeri, relasi, dan raw.
- **Media hanya dari folder uploads** WordPress — tidak ada pengambilan URL
  eksternal — dengan whitelist MIME dan batas ukuran yang sama seperti CMS.
- **Ekspor bertahap** (20 post per request, maksimal 5000 post dan 19000 media)
  sehingga aman untuk situs besar; entry ditulis ke file sementara lalu
  digabung menjadi ZIP pada tahap akhir.

## Kebutuhan

- WordPress 6.0+ dan PHP 8.0+
- Ekstensi PHP `ZipArchive` (ext-zip)
- [Advanced Custom Fields](https://www.advancedcustomfields.com/) — opsional;
  tanpa ACF hanya sumber `post.*` yang tersedia

## Instalasi

1. Salin folder `ringan-export` ke `wp-content/plugins/` (atau unggah ZIP repo ini
   lewat **Plugins → Add New → Upload Plugin**).
2. Aktifkan plugin di menu **Plugins**.
3. Buka **Tools → Ringan Export**.

## Cara pakai

1. Di Ringan CMS: buka **Content Types → Profil** pada content type tujuan, lalu
   salin atau unduh JSON profilnya (pastikan content type dan field-nya sudah
   dibuat).
2. Di WordPress: buka **Tools → Ringan Export**, tempel JSON tersebut (atau isi
   URL `https://cms.example.com/api/v1/<slug>/profile` + `X-API-Key` bila perlu),
   lalu klik **Simpan & Validasi Profil**.
3. Pilih post type dan status yang akan diekspor, klik **Mulai Ekspor**, tunggu
   sampai selesai, lalu unduh ZIP.
4. Di Ringan CMS: buka **Tools → Import ZIP**, unggah ZIP tersebut, periksa
   pratinjau, dan jalankan import.

## Format ZIP

```
manifest.json          # format "ringan-export", profil, entries[], media{}
media/<hash>.<ext>     # hanya media yang dipakai entry
```

- `source_id` = ID post WordPress, dipakai CMS untuk anti-duplikat
  (lewati/perbarui) lewat tabel `import_refs`.
- Media di konten/richtext dikirim sebagai placeholder
  `{{ringan_media:media/<hash>.<ext>}}` dan diganti URL oleh CMS setelah file
  tersimpan.
- Relasi ACF diekspor sebagai `{"__relation_source_ids":[...]}` dan dicocokkan
  CMS lewat `import_refs`; relasi yang belum diimpor dilewati dengan peringatan.

## Batasan

- Repeater/flexible content ACF belum dipetakan otomatis karena Ringan CMS belum
  memiliki tipe field tersebut.
- Ekspor membutuhkan `ZipArchive`; bila ekstensi tidak aktif, halaman plugin
  menampilkan peringatan dan ekspor dinonaktifkan.
- File sementara di direktori temp WordPress dihapus saat sesi dibatalkan atau
  otomatis setelah 24 jam. ZIP tetap bisa diunduh berulang selama sesi masih ada
  (job disimpan 6 jam); bila file sementaranya hilang atau tidak lengkap, ZIP
  dibangun ulang otomatis saat diunduh.
- ZIP dibangun ke file `.part` lalu dipindahkan setelah verifikasi lolos, dan
  unduhan hanya menyajikan arsip yang memuat `manifest.json` — ZIP separuh jadi
  tidak akan terunduh seolah lengkap.
- Unduhan ZIP memakai streaming dengan `Content-Length` yang pasti. Bila hosting
  memaksa kompresi output (`zlib.output_compression`), plugin mematikannya saat
  streaming — kalau tetap 0 byte, periksa panel **Diagnostik server** di halaman
  plugin.

## Keamanan

- Halaman admin hanya untuk kapabilitas `manage_options`.
- Semua form dilindungi nonce (`ringan_export_profile`, `ringan_export_ajax`,
  `ringan_export_download_<token>`).
- Token job berupa 32 hex acak; job diikat ke user pembuatnya.
- Pengambilan profil memakai `wp_remote_get()` dengan `reject_unsafe_urls`.
- Media divalidasi dengan `finfo` dan whitelist MIME; nama file di CMS
  di-randomize saat import.

## Lisensi

GPL-2.0-or-later — lihat [LICENSE](LICENSE).
