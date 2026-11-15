<?php
/**
 * Pemetaan otomatis field CMS → sumber WordPress.
 *
 * Profil dari Ringan CMS hanya membawa tebakan sumber (`post.title`,
 * `acf.<key>`). Plugin mencocokkan ulang setiap field CMS dengan field ACF yang
 * benar-benar ada di situs ini, lalu menyimpan hasilnya ke profil draft. Hasil
 * pemetaan ditampilkan di halaman admin dan bisa diubah manual, sehingga isi
 * ZIP pasti memakai field yang benar sebelum diimpor.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sumber post.* beserta label dan padanan nama yang umum dipakai.
 *
 * @return array<string,array{label:string,kind:string,aliases:string[]}>
 */
function ringan_export_mapping_post_sources(): array
{
    return [
        'post.title' => [
            'label' => 'Judul post',
            'kind' => 'text',
            'aliases' => ['title', 'judul', 'post_title', 'nama', 'headline'],
        ],
        'post.content' => [
            'label' => 'Konten post (HTML)',
            'kind' => 'html',
            'aliases' => ['content', 'body', 'konten', 'isi', 'post_content', 'isi_artikel'],
        ],
        'post.excerpt' => [
            'label' => 'Excerpt post',
            'kind' => 'text',
            'aliases' => ['excerpt', 'ringkasan', 'summary', 'post_excerpt', 'deskripsi_singkat'],
        ],
        'post.slug' => [
            'label' => 'Slug post',
            'kind' => 'text',
            'aliases' => ['slug', 'post_name', 'url_slug'],
        ],
        'post.date' => [
            'label' => 'Tanggal publish',
            'kind' => 'date',
            'aliases' => ['date', 'tanggal', 'published_at', 'publish_date', 'post_date', 'tanggal_terbit'],
        ],
        'post.modified' => [
            'label' => 'Tanggal modifikasi',
            'kind' => 'date',
            'aliases' => ['modified', 'updated_at', 'post_modified', 'tanggal_update'],
        ],
        'post.status' => [
            'label' => 'Status WordPress',
            'kind' => 'select',
            'aliases' => ['status', 'post_status'],
        ],
        'post.author' => [
            'label' => 'Penulis',
            'kind' => 'text',
            'aliases' => ['author', 'penulis', 'creator'],
        ],
        'post.thumbnail' => [
            'label' => 'Gambar unggulan',
            'kind' => 'media',
            'aliases' => [
                'thumbnail', 'featured_image', 'featured', 'gambar_utama', 'foto_utama',
                'gambar_unggulan', 'foto_unggulan', 'cover', 'image', 'gambar', 'foto',
            ],
        ],
    ];
}

/**
 * Jenis nilai sebuah field ACF (disamakan dengan jenis field CMS).
 */
function ringan_export_mapping_acf_kind(string $acf_type): string
{
    return match ($acf_type) {
        'image', 'file' => 'media',
        'gallery' => 'gallery',
        'relationship', 'post_object', 'page_link', 'taxonomy', 'user' => 'relation',
        'wysiwyg' => 'html',
        'date_picker', 'date_time_picker', 'time_picker' => 'date',
        'number', 'range' => 'number',
        'true_false' => 'boolean',
        'select', 'radio', 'button_group', 'checkbox' => 'select',
        default => 'text',
    };
}

/**
 * Normalisasi nama field/label untuk pencocokan.
 */
function ringan_export_mapping_normalize(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? $value;
    return trim($value, '_');
}

/**
 * Apakah nilai dari sumber tertentu masuk akal untuk tipe field CMS?
 */
function ringan_export_mapping_compatible(string $cms_kind, string $source_kind): bool
{
    if ($cms_kind === $source_kind) {
        return true;
    }
    $textish = ['text', 'html', 'select'];
    return in_array($cms_kind, $textish, true) && in_array($source_kind, $textish, true);
}

/**
 * Semua sumber yang tersedia di situs ini (post.* lalu ACF).
 *
 * @return array<int,array{source:string,label:string,kind:string,group:string,name_n:string,label_n:string}>
 */
function ringan_export_mapping_candidates(array $post_types): array
{
    $candidates = [];

    foreach (ringan_export_mapping_post_sources() as $source => $meta) {
        $candidates[] = [
            'source' => $source,
            'label' => $meta['label'],
            'kind' => $meta['kind'],
            'group' => 'post',
            'name_n' => ringan_export_mapping_normalize($source),
            'label_n' => ringan_export_mapping_normalize($meta['label']),
        ];
    }

    foreach (ringan_export_acf_fields($post_types) as $field) {
        $source = 'acf.' . $field['name'];
        $candidates[] = [
            'source' => $source,
            'label' => $field['label'] !== '' ? $field['label'] . ' (' . $field['type'] . ')' : $source,
            'kind' => ringan_export_mapping_acf_kind((string) $field['type']),
            'group' => 'acf',
            'name_n' => ringan_export_mapping_normalize($field['name']),
            'label_n' => ringan_export_mapping_normalize($field['label']),
        ];
    }

    return $candidates;
}

/**
 * Petakan otomatis setiap field CMS ke sumber WordPress terbaik.
 *
 * Sumber yang sudah ada di profil dipertahankan bila memang ada di situs ini.
 * Sumber `acf.*` yang tidak ditemukan dicocokkan ulang berdasarkan nama, label,
 * dan jenis field.
 *
 * @param array<string,array{source:string,status:string,reason:string}> $previous
 * @return array{fields:array<string,array{source:string,status:string,reason:string}>,warnings:string[],candidates:array}
 */
function ringan_export_auto_map(array $profile, array $post_types, array $previous = []): array
{
    $candidates = ringan_export_mapping_candidates($post_types);
    $by_source = [];
    foreach ($candidates as $candidate) {
        $by_source[$candidate['source']] = $candidate;
    }
    $post_sources = ringan_export_mapping_post_sources();
    $acf_active = ringan_export_acf_active();

    $fields = [];
    $warnings = [];
    $taken = [];

    foreach ((array) ($profile['fields'] ?? []) as $field) {
        $key = (string) ($field['key'] ?? '');
        if ($key === '') {
            continue;
        }
        $type = (string) ($field['type'] ?? 'text');
        $kind = ringan_export_kind($type, 'auto');
        $current = (string) ($field['source'] ?? 'none');
        $key_n = ringan_export_mapping_normalize($key);
        $label_n = ringan_export_mapping_normalize((string) ($field['label'] ?? $key));
        $keep_current = null;

        // 0. Pilihan manual user tidak boleh ditimpa saat pemetaan dijalankan ulang.
        if (($previous[$key]['status'] ?? '') === 'manual') {
            $manual = (string) ($previous[$key]['source'] ?? $current);
            $fields[$key] = [
                'source' => $manual !== '' ? $manual : 'none',
                'status' => 'manual',
                'reason' => 'Dipilih manual.',
            ];
            if ($manual !== '' && $manual !== 'none') {
                $taken[$manual] = $key;
            }
            continue;
        }

        if ($current !== 'none' && isset($by_source[$current])) {
            // 1. Sumber eksplisit yang ada di situs ini dipertahankan.
            $candidate = $by_source[$current];
            $compatible = ringan_export_mapping_compatible($kind, $candidate['kind']);
            $fields[$key] = [
                'source' => $current,
                'status' => 'kept',
                'reason' => $compatible
                    ? 'Sumber sudah ada di situs ini.'
                    : sprintf(
                        'Sumber %s berisi %s, field CMS bertipe %s — periksa hasilnya.',
                        $current,
                        $candidate['kind'],
                        $kind
                    ),
            ];
            if (!$compatible) {
                $warnings[] = sprintf('Field "%s": %s', $key, $fields[$key]['reason']);
            }
            $taken[$current] = $key;
            continue;
        }

        if ($current !== 'none') {
            if (str_starts_with($current, 'acf.') && !$acf_active) {
                // ACF tidak aktif: field tidak bisa diverifikasi, tapi nilainya
                // masih terbaca dari post meta. Jangan diubah diam-diam.
                $fields[$key] = [
                    'source' => $current,
                    'status' => 'kept',
                    'reason' => 'ACF tidak aktif; nilai dibaca dari post meta.',
                ];
                $taken[$current] = $key;
                continue;
            }
            // Sumber tidak ditemukan: cari padanan yang benar-benar yakin,
            // selain itu sumber lama dipertahankan agar tidak ada data hilang.
            $keep_current = $current;
            $warnings[] = sprintf(
                'Field "%s": sumber %s tidak terdeteksi di situs ini; dicari padanan yang lebih tepat.',
                $key,
                $current
            );
        }

        // 2. Cari kandidat terbaik.
        $best = null;
        $best_score = 0;
        foreach ($candidates as $candidate) {
            if (isset($taken[$candidate['source']])) {
                continue;
            }

            $score = 0;
            if ($candidate['group'] === 'acf' && $candidate['name_n'] === $key_n) {
                $score = 100;
            } elseif ($candidate['group'] === 'post'
                && in_array($key_n, $post_sources[$candidate['source']]['aliases'], true)) {
                $score = 100;
            } elseif ($label_n !== '' && $candidate['label_n'] === $label_n) {
                $score = 92;
            } elseif ($label_n !== '' && $candidate['name_n'] === $label_n) {
                $score = 88;
            } elseif ($candidate['group'] === 'post'
                && $label_n !== ''
                && in_array($label_n, $post_sources[$candidate['source']]['aliases'], true)) {
                $score = 96;
            } elseif (strlen($key_n) >= 3 && $candidate['name_n'] !== ''
                && (str_contains($candidate['name_n'], $key_n) || str_contains($key_n, $candidate['name_n']))) {
                $score = 62;
            } elseif (strlen($label_n) >= 3 && $candidate['label_n'] !== ''
                && str_contains($candidate['label_n'], $label_n)) {
                $score = 58;
            }
            if ($score <= 0) {
                continue;
            }

            if (ringan_export_mapping_compatible($kind, $candidate['kind'])) {
                $score += $candidate['kind'] === $kind ? 12 : 6;
            } else {
                $score -= 45;
            }

            if ($score > $best_score) {
                $best_score = $score;
                $best = $candidate;
            }
        }

        // Sumber lama yang tidak terdeteksi hanya diganti bila padanannya yakin.
        $threshold = $keep_current !== null ? 90 : 55;

        if ($best !== null && $best_score >= $threshold) {
            $fields[$key] = [
                'source' => $best['source'],
                'status' => $best_score >= 90 ? 'auto' : 'guess',
                'reason' => $best_score >= 90
                    ? sprintf('Cocok dengan %s (%s).', $best['source'], $best['label'])
                    : sprintf('Perkiraan: %s (%s) — periksa hasilnya.', $best['source'], $best['label']),
            ];
            $taken[$best['source']] = $key;
            continue;
        }

        if ($keep_current !== null) {
            $fields[$key] = [
                'source' => $keep_current,
                'status' => 'missing',
                'reason' => 'Sumber tidak terdeteksi; nilai dibaca dari post meta. Ganti bila perlu.',
            ];
            $taken[$keep_current] = $key;
            continue;
        }

        $fields[$key] = [
            'source' => 'none',
            'status' => 'none',
            'reason' => 'Tidak ada field WordPress/ACF yang cocok; pilih sumbernya manual.',
        ];
    }

    return [
        'fields' => $fields,
        'warnings' => $warnings,
        'candidates' => $candidates,
    ];
}

/**
 * Terapkan hasil pemetaan ke profil (hanya field yang sumbernya valid).
 */
function ringan_export_mapping_apply(array $profile, array $map): array
{
    $sources = [];
    foreach ((array) ($map['fields'] ?? []) as $key => $info) {
        $source = (string) ($info['source'] ?? 'none');
        if ($source === 'none' || $source === '') {
            continue;
        }
        if (isset(ringan_export_mapping_post_sources()[$source])
            || preg_match('/^acf\.[A-Za-z0-9_-]{1,100}$/', $source)) {
            $sources[(string) $key] = $source;
        }
    }

    foreach ((array) ($profile['fields'] ?? []) as $index => $field) {
        $key = (string) ($field['key'] ?? '');
        if ($key === '' || !isset($sources[$key])) {
            continue;
        }
        $profile['fields'][$index]['source'] = $sources[$key];
    }

    return $profile;
}

/**
 * Opsi <select> pemetaan, dikelompokkan per asal.
 *
 * @return array{post:array<string,string>,acf:array<string,string>}
 */
function ringan_export_mapping_options(array $post_types): array
{
    $options = ['post' => [], 'acf' => []];
    foreach (ringan_export_mapping_candidates($post_types) as $candidate) {
        $options[$candidate['group']][$candidate['source']] = $candidate['label'];
    }
    return $options;
}

/**
 * Ringkasan pemetaan untuk ditampilkan di halaman admin.
 *
 * @return array{mapped:int,total:int,unmapped:string[],guess:string[],kept:int}
 */
function ringan_export_mapping_summary(array $profile, array $map): array
{
    $summary = ['mapped' => 0, 'total' => 0, 'unmapped' => [], 'guess' => [], 'kept' => 0];
    foreach ((array) ($profile['fields'] ?? []) as $field) {
        $key = (string) ($field['key'] ?? '');
        if ($key === '') {
            continue;
        }
        $summary['total']++;
        $info = (array) ($map['fields'][$key] ?? []);
        $source = (string) ($info['source'] ?? 'none');
        if ($source === 'none') {
            $summary['unmapped'][] = $key;
            continue;
        }
        $summary['mapped']++;
        if (($info['status'] ?? '') === 'guess') {
            $summary['guess'][] = $key;
        }
        if (($info['status'] ?? '') === 'kept') {
            $summary['kept']++;
        }
    }
    return $summary;
}
