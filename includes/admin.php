<?php
/**
 * Halaman admin plugin: pilih profil, pilih post type/status, jalankan ekspor.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const RINGAN_EXPORT_PAGE_SLUG = 'ringan-export';

function ringan_export_capability(): string
{
    return 'manage_options';
}

function ringan_export_draft_key(int $user_id): string
{
    return 'ringan_export_draft_' . $user_id;
}

/**
 * @return array{profile:array,raw:string,errors:string[],warnings:string[],mapping:array}
 */
function ringan_export_draft_get(int $user_id): array
{
    $draft = get_transient(ringan_export_draft_key($user_id));
    if (!is_array($draft)) {
        return ['profile' => [], 'raw' => '', 'errors' => [], 'warnings' => [], 'mapping' => []];
    }
    return [
        'profile' => is_array($draft['profile'] ?? null) ? $draft['profile'] : [],
        'raw' => (string) ($draft['raw'] ?? ''),
        'errors' => array_values((array) ($draft['errors'] ?? [])),
        'warnings' => array_values((array) ($draft['warnings'] ?? [])),
        'mapping' => is_array($draft['mapping'] ?? null) ? $draft['mapping'] : [],
    ];
}

function ringan_export_draft_save(int $user_id, array $draft): void
{
    set_transient(ringan_export_draft_key($user_id), $draft, DAY_IN_SECONDS);
}

function ringan_export_draft_clear(int $user_id): void
{
    delete_transient(ringan_export_draft_key($user_id));
}

/* =========================================================================
 * Menu & aset
 * ========================================================================= */

function ringan_export_register_menu(): void
{
    add_management_page(
        __('Ringan Export', 'ringan-export'),
        __('Ringan Export', 'ringan-export'),
        ringan_export_capability(),
        RINGAN_EXPORT_PAGE_SLUG,
        'ringan_export_render_page'
    );
}

function ringan_export_enqueue_assets(string $hook): void
{
    if ($hook !== 'tools_page_' . RINGAN_EXPORT_PAGE_SLUG) {
        return;
    }
    wp_enqueue_style(
        'ringan-export-admin',
        RINGAN_EXPORT_URL . 'assets/admin.css',
        [],
        RINGAN_EXPORT_VERSION
    );
    wp_enqueue_script(
        'ringan-export-admin',
        RINGAN_EXPORT_URL . 'assets/admin.js',
        [],
        RINGAN_EXPORT_VERSION,
        true
    );
    $draft = ringan_export_draft_get(get_current_user_id());
    $profile = $draft['profile'];

    wp_localize_script('ringan-export-admin', 'RinganExport', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('ringan_export_ajax'),
        'batchSize' => RINGAN_EXPORT_BATCH_SIZE,
        'previewPostTypes' => array_values((array) ($profile['source']['post_types'] ?? [])),
        'previewStatuses' => array_values((array) ($profile['source']['post_statuses'] ?? [])),
        'strings' => [
            'preparing' => __('Menyiapkan daftar post…', 'ringan-export'),
            'processing' => __('Memproses %1$d dari %2$d post…', 'ringan-export'),
            'finalizing' => __('Menyusun ZIP + media…', 'ringan-export'),
            'done' => __('Ekspor selesai. Unduh ZIP lalu unggah di Ringan CMS (Tools → Import ZIP).', 'ringan-export'),
            'failed' => __('Ekspor gagal.', 'ringan-export'),
            'stalled' => __('Ekspor berhenti karena tidak ada kemajuan. Coba kurangi jumlah post atau periksa log server.', 'ringan-export'),
            'emptyZip' => __('Server melaporkan ZIP kosong (0 byte). Jalankan ekspor ulang dan periksa log server.', 'ringan-export'),
            'confirmCancel' => __('Batalkan sesi ekspor dan hapus file sementara?', 'ringan-export'),
            'previewLoading' => __('Membuat pratinjau…', 'ringan-export'),
            'previewFailed' => __('Gagal membuat pratinjau.', 'ringan-export'),
            'previewEmpty' => __('Tidak ada post untuk dipratinjau.', 'ringan-export'),
            'previewFields' => __('Field terisi', 'ringan-export'),
        ],
    ]);
}

/* =========================================================================
 * Handler form profil
 * ========================================================================= */

/**
 * Terapkan pemetaan otomatis ke profil.
 *
 * @param array<string,array{source:string,status:string,reason:string}> $previous Pemetaan sebelumnya (pilihan manual dipertahankan).
 * @return array{profile:array,mapping:array,warnings:string[]}
 */
function ringan_export_profile_automap(array $profile, array $previous = []): array
{
    $post_types = (array) ($profile['source']['post_types'] ?? []);
    $map = ringan_export_auto_map($profile, $post_types, $previous);
    $mapped = ringan_export_mapping_apply($profile, $map);

    $normalized = ringan_export_profile_normalize($mapped);
    if ($normalized['ok']) {
        $mapped = $normalized['profile'];
    }

    return [
        'profile' => $mapped,
        'mapping' => (array) ($map['fields'] ?? []),
        'warnings' => (array) ($map['warnings'] ?? []),
    ];
}

function ringan_export_handle_save_profile(): void
{
    if (!current_user_can(ringan_export_capability())) {
        wp_die(esc_html__('Anda tidak memiliki akses.', 'ringan-export'), 403);
    }
    check_admin_referer('ringan_export_profile');

    $user_id = get_current_user_id();
    $raw = isset($_POST['profile_json']) ? (string) wp_unslash($_POST['profile_json']) : '';
    $result = ringan_export_profile_parse($raw);
    if (!$result['ok']) {
        ringan_export_draft_save($user_id, [
            'profile' => [],
            'raw' => $raw,
            'errors' => $result['errors'],
            'warnings' => [],
            'mapping' => [],
        ]);
        wp_safe_redirect(add_query_arg('ringan_export_notice', 'profile_invalid', ringan_export_admin_url()));
        exit;
    }

    $automap = ringan_export_profile_automap($result['profile']);
    ringan_export_draft_save($user_id, [
        'profile' => $automap['profile'],
        'raw' => (string) wp_json_encode($automap['profile'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'errors' => [],
        'warnings' => $automap['warnings'],
        'mapping' => $automap['mapping'],
    ]);
    wp_safe_redirect(add_query_arg('ringan_export_notice', 'profile_saved', ringan_export_admin_url()));
    exit;
}

function ringan_export_handle_fetch_profile(): void
{
    if (!current_user_can(ringan_export_capability())) {
        wp_die(esc_html__('Anda tidak memiliki akses.', 'ringan-export'), 403);
    }
    check_admin_referer('ringan_export_profile');

    $user_id = get_current_user_id();
    $url = isset($_POST['profile_url']) ? esc_url_raw(wp_unslash((string) $_POST['profile_url'])) : '';
    $api_key = isset($_POST['api_key']) ? sanitize_text_field(wp_unslash((string) $_POST['api_key'])) : '';

    $result = ringan_export_profile_fetch($url, $api_key);
    if (!$result['ok']) {
        ringan_export_draft_save($user_id, [
            'profile' => [],
            'raw' => '',
            'errors' => $result['errors'],
            'warnings' => [],
            'mapping' => [],
        ]);
        wp_safe_redirect(add_query_arg('ringan_export_notice', 'fetch_failed', ringan_export_admin_url()));
        exit;
    }

    $automap = ringan_export_profile_automap($result['profile']);
    ringan_export_draft_save($user_id, [
        'profile' => $automap['profile'],
        'raw' => (string) wp_json_encode($automap['profile'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'errors' => [],
        'warnings' => $automap['warnings'],
        'mapping' => $automap['mapping'],
    ]);
    wp_safe_redirect(add_query_arg('ringan_export_notice', 'profile_fetched', ringan_export_admin_url()));
    exit;
}

/**
 * Jalankan ulang pemetaan otomatis untuk profil yang sedang dimuat.
 */
function ringan_export_handle_auto_map(): void
{
    if (!current_user_can(ringan_export_capability())) {
        wp_die(esc_html__('Anda tidak memiliki akses.', 'ringan-export'), 403);
    }
    check_admin_referer('ringan_export_mapping');

    $user_id = get_current_user_id();
    $draft = ringan_export_draft_get($user_id);
    if ($draft['profile'] === []) {
        wp_safe_redirect(add_query_arg('ringan_export_notice', 'mapping_failed', ringan_export_admin_url()));
        exit;
    }

    $automap = ringan_export_profile_automap($draft['profile'], $draft['mapping']);
    ringan_export_draft_save($user_id, [
        'profile' => $automap['profile'],
        'raw' => (string) wp_json_encode($automap['profile'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'errors' => [],
        'warnings' => $automap['warnings'],
        'mapping' => $automap['mapping'],
    ]);
    wp_safe_redirect(add_query_arg('ringan_export_notice', 'mapping_updated', ringan_export_admin_url()));
    exit;
}

/**
 * Simpan pemetaan field pilihan user + opsi pembersihan konten.
 */
function ringan_export_handle_save_mapping(): void
{
    if (!current_user_can(ringan_export_capability())) {
        wp_die(esc_html__('Anda tidak memiliki akses.', 'ringan-export'), 403);
    }
    check_admin_referer('ringan_export_mapping');

    $user_id = get_current_user_id();
    $draft = ringan_export_draft_get($user_id);
    if ($draft['profile'] === []) {
        wp_safe_redirect(add_query_arg('ringan_export_notice', 'mapping_failed', ringan_export_admin_url()));
        exit;
    }

    $post_sources = ringan_export_mapping_post_sources();
    $input = isset($_POST['mapping']) && is_array($_POST['mapping']) ? (array) wp_unslash($_POST['mapping']) : [];
    $profile = $draft['profile'];
    $mapping = $draft['mapping'];

    foreach ((array) ($profile['fields'] ?? []) as $index => $field) {
        $key = (string) ($field['key'] ?? '');
        if ($key === '' || !array_key_exists($key, $input)) {
            continue;
        }
        $source = trim(sanitize_text_field((string) $input[$key]));
        if ($source === '') {
            $source = 'none';
        }
        if ($source !== 'none'
            && !isset($post_sources[$source])
            && !preg_match('/^acf\.[A-Za-z0-9_-]{1,100}$/', $source)) {
            continue;
        }
        $profile['fields'][$index]['source'] = $source;
        $previous = (string) ($mapping[$key]['source'] ?? '');
        if ($previous !== $source) {
            $mapping[$key] = [
                'source' => $source,
                'status' => 'manual',
                'reason' => 'Dipilih manual.',
            ];
        }
    }

    $clean_keys = ['clean_html', 'clean_shortcodes', 'strip_attributes', 'absolute_urls'];
    foreach ($clean_keys as $option) {
        $profile['options'][$option] = !empty($_POST[$option]);
    }

    $normalized = ringan_export_profile_normalize($profile);
    if (!$normalized['ok']) {
        ringan_export_draft_save($user_id, [
            'profile' => $draft['profile'],
            'raw' => $draft['raw'],
            'errors' => $normalized['errors'],
            'warnings' => $draft['warnings'],
            'mapping' => $draft['mapping'],
        ]);
        wp_safe_redirect(add_query_arg('ringan_export_notice', 'profile_invalid', ringan_export_admin_url()));
        exit;
    }

    ringan_export_draft_save($user_id, [
        'profile' => $normalized['profile'],
        'raw' => (string) wp_json_encode($normalized['profile'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'errors' => [],
        'warnings' => $draft['warnings'],
        'mapping' => $mapping,
    ]);
    wp_safe_redirect(add_query_arg('ringan_export_notice', 'mapping_saved', ringan_export_admin_url()));
    exit;
}

function ringan_export_admin_url(): string
{
    return admin_url('tools.php?page=' . RINGAN_EXPORT_PAGE_SLUG);
}

/* =========================================================================
 * AJAX ekspor
 * ========================================================================= */

function ringan_export_ajax_verify(): int
{
    check_ajax_referer('ringan_export_ajax', 'nonce');
    if (!current_user_can(ringan_export_capability())) {
        wp_send_json_error(['message' => __('Anda tidak memiliki akses.', 'ringan-export')], 403);
    }
    return get_current_user_id();
}

function ringan_export_ajax_prepare(): void
{
    $user_id = ringan_export_ajax_verify();

    $post_types = isset($_POST['post_types']) ? (array) wp_unslash($_POST['post_types']) : [];
    $post_types = array_values(array_filter(array_map('sanitize_key', array_map('strval', $post_types))));
    $statuses = isset($_POST['post_statuses']) ? (array) wp_unslash($_POST['post_statuses']) : [];
    $statuses = array_values(array_filter(array_map('sanitize_key', array_map('strval', $statuses))));

    $draft = ringan_export_draft_get($user_id);
    if ($draft['profile'] === []) {
        wp_send_json_error(['message' => __('Profil belum dimuat/valid. Tempel atau ambil profil dari CMS terlebih dahulu.', 'ringan-export')]);
    }
    $validated = ringan_export_profile_normalize($draft['profile']);
    if (!$validated['ok']) {
        wp_send_json_error(['message' => implode(' ', array_slice($validated['errors'], 0, 5))]);
    }

    $result = ringan_export_prepare($validated['profile'], $post_types, $statuses, $user_id);
    if (!$result['ok']) {
        wp_send_json_error(['message' => (string) ($result['error'] ?? 'Gagal menyiapkan ekspor.')]);
    }

    wp_send_json_success([
        'token' => (string) $result['token'],
        'total' => (int) $result['total'],
    ]);
}

function ringan_export_ajax_batch(): void
{
    $user_id = ringan_export_ajax_verify();
    $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash((string) $_POST['token'])) : '';
    $result = ringan_export_batch($token, $user_id);
    if (!$result['ok']) {
        wp_send_json_error(['message' => (string) ($result['error'] ?? 'Batch gagal.')]);
    }
    wp_send_json_success($result);
}

function ringan_export_ajax_finalize(): void
{
    $user_id = ringan_export_ajax_verify();
    $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash((string) $_POST['token'])) : '';
    $result = ringan_export_finalize($token, $user_id);
    if (!$result['ok']) {
        wp_send_json_error(['message' => (string) ($result['error'] ?? 'Finalisasi gagal.')]);
    }
    wp_send_json_success([
        'download_url' => (string) $result['download_url'],
        'size' => (int) $result['size'],
        'stats' => (array) $result['stats'],
        'size_label' => size_format((int) $result['size']),
    ]);
}

function ringan_export_ajax_cancel(): void
{
    $user_id = ringan_export_ajax_verify();
    $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash((string) $_POST['token'])) : '';
    ringan_export_cancel($token, $user_id);
    wp_send_json_success(['cancelled' => true]);
}

/**
 * Pratinjau 1 post dengan profil yang sudah dipetakan (tanpa menulis ZIP).
 */
function ringan_export_ajax_preview(): void
{
    $user_id = ringan_export_ajax_verify();
    $draft = ringan_export_draft_get($user_id);
    if ($draft['profile'] === []) {
        wp_send_json_error(['message' => __('Profil belum dimuat/valid. Muat profil dari CMS terlebih dahulu.', 'ringan-export')]);
    }
    $validated = ringan_export_profile_normalize($draft['profile']);
    if (!$validated['ok']) {
        wp_send_json_error(['message' => implode(' ', array_slice($validated['errors'], 0, 5))]);
    }
    $profile = $validated['profile'];

    $post_types = isset($_POST['post_types']) ? (array) wp_unslash($_POST['post_types']) : [];
    $post_types = array_values(array_filter(array_map('sanitize_key', array_map('strval', $post_types))));
    if ($post_types === []) {
        $post_types = array_values(array_map('strval', (array) ($profile['source']['post_types'] ?? [])));
    }

    $statuses = isset($_POST['post_statuses']) ? (array) wp_unslash($_POST['post_statuses']) : [];
    $statuses = array_values(array_filter(array_map('sanitize_key', array_map('strval', $statuses))));
    if ($statuses === []) {
        $statuses = array_values(array_map('strval', (array) ($profile['source']['post_statuses'] ?? [])));
    }

    $result = ringan_export_preview($profile, $post_types, $statuses, 1);
    if (!$result['ok']) {
        wp_send_json_error(['message' => (string) ($result['error'] ?? __('Gagal membuat pratinjau.', 'ringan-export'))]);
    }

    wp_send_json_success([
        'entry' => $result['entries'][0] ?? null,
        'warnings' => (array) ($result['warnings'] ?? []),
        'fields' => (array) ($result['fields'] ?? []),
        'media' => (int) ($result['media'] ?? 0),
    ]);
}

/* =========================================================================
 * Render halaman
 * ========================================================================= */

function ringan_export_render_page(): void
{
    if (!current_user_can(ringan_export_capability())) {
        return;
    }

    $user_id = get_current_user_id();
    $draft = ringan_export_draft_get($user_id);
    $profile = $draft['profile'];
    $notice = isset($_GET['ringan_export_notice']) ? sanitize_key((string) $_GET['ringan_export_notice']) : '';

    $notices = [
        'profile_saved' => [__('Profil berhasil dimuat dan divalidasi.', 'ringan-export'), 'success'],
        'profile_fetched' => [__('Profil berhasil diambil dari Ringan CMS.', 'ringan-export'), 'success'],
        'profile_invalid' => [__('Profil tidak valid. Periksa pesan error di bawah.', 'ringan-export'), 'error'],
        'fetch_failed' => [__('Gagal mengambil profil dari CMS.', 'ringan-export'), 'error'],
        'profile_cleared' => [__('Profil draft dikosongkan.', 'ringan-export'), 'success'],
        'mapping_saved' => [__('Pemetaan field dan opsi pembersihan disimpan.', 'ringan-export'), 'success'],
        'mapping_updated' => [__('Pemetaan otomatis diterapkan ulang ke profil.', 'ringan-export'), 'success'],
        'mapping_failed' => [__('Pemetaan tidak dapat disimpan. Muat profil terlebih dahulu.', 'ringan-export'), 'error'],
    ];

    $post_types = [];
    foreach (get_post_types(['public' => true], 'objects') as $type) {
        if ($type->name === 'attachment') {
            continue;
        }
        $post_types[$type->name] = $type->labels->singular_name !== ''
            ? $type->labels->singular_name . ' (' . $type->name . ')'
            : $type->name;
    }

    $status_options = ['publish', 'draft', 'private', 'pending', 'future'];
    $profile_post_types = (array) ($profile['source']['post_types'] ?? []);
    $profile_statuses = (array) ($profile['source']['post_statuses'] ?? []);
    $acf_fields = ringan_export_acf_fields($profile_post_types !== [] ? $profile_post_types : array_keys($post_types));

    $mapping = $draft['mapping'];
    if ($mapping === [] && $profile !== []) {
        // Draft lama (sebelum ada pemetaan otomatis): tampilkan hasil hitung
        // ulang tanpa mengubah profil.
        $auto = ringan_export_auto_map($profile, $profile_post_types);
        $mapping = (array) ($auto['fields'] ?? []);
    }
    $mapping_summary = ringan_export_mapping_summary($profile, ['fields' => $mapping]);
    $source_options = $profile !== []
        ? ringan_export_mapping_options($profile_post_types)
        : ['post' => [], 'acf' => []];
    $clean_options = ringan_export_clean_options($profile);
    $mapping_status_labels = [
        'auto' => __('otomatis', 'ringan-export'),
        'guess' => __('perkiraan', 'ringan-export'),
        'kept' => __('tetap', 'ringan-export'),
        'manual' => __('manual', 'ringan-export'),
        'missing' => __('tidak terdeteksi', 'ringan-export'),
        'none' => __('belum dipetakan', 'ringan-export'),
    ];
    ?>
    <div class="wrap ringan-export">
        <h1><?php esc_html_e('Ringan Export', 'ringan-export'); ?></h1>
        <p class="description">
            <?php esc_html_e('Ekspor post/CPT beserta field ACF dan media ke satu file ZIP, lalu unggah ZIP tersebut di Ringan CMS melalui Tools → Import ZIP.', 'ringan-export'); ?>
        </p>

        <?php if ($notice !== '' && isset($notices[$notice])): ?>
            <div class="notice notice-<?php echo esc_attr($notices[$notice][1]); ?> is-dismissible">
                <p><?php echo esc_html($notices[$notice][0]); ?></p>
            </div>
        <?php endif; ?>

        <?php if (!class_exists('ZipArchive')): ?>
            <div class="notice notice-error">
                <p><?php esc_html_e('Ekstensi PHP ZipArchive (ext-zip) tidak aktif. Ekspor tidak dapat dijalankan.', 'ringan-export'); ?></p>
            </div>
        <?php endif; ?>

        <?php if (!ringan_export_acf_active()): ?>
            <div class="notice notice-warning">
                <p><?php esc_html_e('Plugin ACF tidak terdeteksi. Sumber acf.* akan dibaca dari post meta mentah; field gambar/relasi mungkin perlu transform manual.', 'ringan-export'); ?></p>
            </div>
        <?php endif; ?>

        <?php if ($draft['errors'] !== []): ?>
            <div class="notice notice-error">
                <p><strong><?php esc_html_e('Profil tidak valid:', 'ringan-export'); ?></strong></p>
                <ul class="ringan-export-errors">
                    <?php foreach (array_slice($draft['errors'], 0, 20) as $error): ?>
                        <li><?php echo esc_html((string) $error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="ringan-export-grid">
            <div class="card">
                <h2><?php esc_html_e('1. Profil dari Ringan CMS', 'ringan-export'); ?></h2>
                <p class="description">
                    <?php esc_html_e('Di Ringan CMS buka Content Types → Profil, unduh/salin JSON-nya. Tempel di bawah atau ambil langsung dari endpoint /api/v1/{slug}/profile.', 'ringan-export'); ?>
                </p>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="ringan_export_fetch_profile">
                    <?php wp_nonce_field('ringan_export_profile'); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="ringan-profile-url"><?php esc_html_e('URL profil (opsional)', 'ringan-export'); ?></label></th>
                            <td>
                                <input type="url" class="regular-text" id="ringan-profile-url" name="profile_url"
                                       placeholder="https://cms.example.com/api/v1/artikel/profile">
                                <p class="description"><?php esc_html_e('Contoh: https://cms.example.com/api/v1/artikel/profile', 'ringan-export'); ?></p>
                                <input type="text" class="regular-text" name="api_key" autocomplete="off"
                                       placeholder="<?php esc_attr_e('X-API-Key (bila endpoint butuh autentikasi)', 'ringan-export'); ?>">
                            </td>
                        </tr>
                    </table>
                    <p>
                        <button type="submit" class="button"><?php esc_html_e('Ambil Profil dari CMS', 'ringan-export'); ?></button>
                    </p>
                </form>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="ringan_export_save_profile">
                    <?php wp_nonce_field('ringan_export_profile'); ?>
                    <textarea name="profile_json" rows="14" class="large-text code" spellcheck="false"
                              placeholder="{ &quot;format&quot;: &quot;ringan-export-profile&quot;, ... }"><?php echo esc_textarea($draft['raw']); ?></textarea>
                    <p>
                        <button type="submit" class="button button-primary"><?php esc_html_e('Simpan & Validasi Profil', 'ringan-export'); ?></button>
                        <?php if ($profile !== []): ?>
                            <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ringan_export_clear_profile'), 'ringan_export_profile')); ?>">
                                <?php esc_html_e('Kosongkan', 'ringan-export'); ?>
                            </a>
                        <?php endif; ?>
                    </p>
                </form>
            </div>

            <div class="card">
                <h2><?php esc_html_e('2. Ringkasan profil', 'ringan-export'); ?></h2>
                <?php if ($profile === []): ?>
                    <p class="description"><?php esc_html_e('Belum ada profil yang dimuat.', 'ringan-export'); ?></p>
                <?php else: ?>
                    <p>
                        <strong><?php echo esc_html((string) ($profile['content_type']['label'] ?? $profile['content_type']['slug'] ?? '')); ?></strong>
                        <code><?php echo esc_html((string) ($profile['content_type']['slug'] ?? '')); ?></code>
                    </p>
                    <p class="description">
                        <?php
                        printf(
                            /* translators: 1: post types, 2: statuses */
                            esc_html__('Post type: %1$s · Status: %2$s', 'ringan-export'),
                            esc_html(implode(', ', array_map('strval', $profile_post_types))),
                            esc_html(implode(', ', array_map('strval', $profile_statuses)))
                        );
                        ?>
                    </p>
                    <table class="widefat striped ringan-export-fields">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Field CMS', 'ringan-export'); ?></th>
                                <th><?php esc_html_e('Tipe', 'ringan-export'); ?></th>
                                <th><?php esc_html_e('Sumber WordPress', 'ringan-export'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ((array) ($profile['fields'] ?? []) as $field): ?>
                            <?php
                            $field_key = (string) ($field['key'] ?? '');
                            $source = (string) ($field['source'] ?? 'none');
                            $acf_name = str_starts_with($source, 'acf.') ? substr($source, 4) : '';
                            $acf_missing = $acf_name !== '' && $acf_fields !== [] && !isset($acf_fields[$acf_name]);
                            ?>
                            <tr>
                                <td><code><?php echo esc_html($field_key); ?></code></td>
                                <td><?php echo esc_html((string) ($field['type'] ?? '')); ?></td>
                                <td>
                                    <code><?php echo esc_html($source); ?></code>
                                    <?php if ($acf_missing): ?>
                                        <span class="ringan-export-warn"><?php esc_html_e('field ACF tidak ditemukan', 'ringan-export'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($profile !== []): ?>
            <div class="card" id="ringan-export-mapping">
                <h2><?php esc_html_e('3. Pemetaan field & pembersihan konten', 'ringan-export'); ?></h2>
                <p class="description">
                    <?php
                    printf(
                        /* translators: 1: mapped fields, 2: total fields */
                        esc_html__('%1$d dari %2$d field CMS sudah dipetakan ke sumber WordPress. Pemetaan ini yang dipakai saat ekspor, jadi isi ZIP langsung cocok dengan field di CMS.', 'ringan-export'),
                        (int) $mapping_summary['mapped'],
                        (int) $mapping_summary['total']
                    );
                    ?>
                </p>

                <?php if ($mapping_summary['unmapped'] !== []): ?>
                    <div class="notice notice-warning inline">
                        <p>
                            <?php
                            printf(
                                /* translators: %s: field keys */
                                esc_html__('Field belum punya sumber dan tidak akan diekspor: %s', 'ringan-export'),
                                esc_html(implode(', ', array_map('strval', $mapping_summary['unmapped'])))
                            );
                            ?>
                        </p>
                    </div>
                <?php endif; ?>

                <?php if ($mapping_summary['guess'] !== []): ?>
                    <div class="notice notice-info inline">
                        <p>
                            <?php
                            printf(
                                /* translators: %s: field keys */
                                esc_html__('Pemetaan berikut masih perkiraan, mohon dicek: %s', 'ringan-export'),
                                esc_html(implode(', ', array_map('strval', $mapping_summary['guess'])))
                            );
                            ?>
                        </p>
                    </div>
                <?php endif; ?>

                <?php if ($draft['warnings'] !== []): ?>
                    <details class="ringan-export-mapping-warnings">
                        <summary>
                            <?php
                            printf(
                                /* translators: %d: warning count */
                                esc_html__('Catatan pemetaan (%d)', 'ringan-export'),
                                count($draft['warnings'])
                            );
                            ?>
                        </summary>
                        <ul class="ringan-export-errors">
                            <?php foreach ($draft['warnings'] as $warning): ?>
                                <li><?php echo esc_html((string) $warning); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endif; ?>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="ringan_export_save_mapping">
                    <?php wp_nonce_field('ringan_export_mapping'); ?>
                    <table class="widefat striped ringan-export-mapping-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Field CMS', 'ringan-export'); ?></th>
                                <th><?php esc_html_e('Tipe', 'ringan-export'); ?></th>
                                <th><?php esc_html_e('Sumber WordPress', 'ringan-export'); ?></th>
                                <th><?php esc_html_e('Status', 'ringan-export'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ((array) ($profile['fields'] ?? []) as $field): ?>
                            <?php
                            $field_key = (string) ($field['key'] ?? '');
                            $field_source = (string) ($field['source'] ?? 'none');
                            $info = (array) ($mapping[$field_key] ?? []);
                            $status = (string) ($info['status'] ?? ($field_source === 'none' ? 'none' : 'kept'));
                            $reason = (string) ($info['reason'] ?? '');
                            $known = $field_source === 'none'
                                || isset($source_options['post'][$field_source])
                                || isset($source_options['acf'][$field_source]);
                            ?>
                            <tr>
                                <td>
                                    <code><?php echo esc_html($field_key); ?></code>
                                    <div class="ringan-export-mapping-label"><?php echo esc_html((string) ($field['label'] ?? $field_key)); ?></div>
                                </td>
                                <td><?php echo esc_html((string) ($field['type'] ?? '')); ?></td>
                                <td>
                                    <select name="mapping[<?php echo esc_attr($field_key); ?>]">
                                        <option value="none" <?php selected($field_source, 'none'); ?>>
                                            <?php esc_html_e('— tidak dipakai —', 'ringan-export'); ?>
                                        </option>
                                        <optgroup label="<?php esc_attr_e('Post WordPress', 'ringan-export'); ?>">
                                            <?php foreach ($source_options['post'] as $value => $label): ?>
                                                <option value="<?php echo esc_attr((string) $value); ?>" <?php selected($field_source, (string) $value); ?>>
                                                    <?php echo esc_html((string) $label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                        <optgroup label="<?php esc_attr_e('Field ACF', 'ringan-export'); ?>">
                                            <?php if ($source_options['acf'] === []): ?>
                                                <option value="" disabled><?php esc_html_e('ACF tidak aktif / tidak ada field', 'ringan-export'); ?></option>
                                            <?php endif; ?>
                                            <?php foreach ($source_options['acf'] as $value => $label): ?>
                                                <option value="<?php echo esc_attr((string) $value); ?>" <?php selected($field_source, (string) $value); ?>>
                                                    <?php echo esc_html((string) $label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                        <?php if (!$known && $field_source !== 'none'): ?>
                                            <option value="<?php echo esc_attr($field_source); ?>" selected>
                                                <?php echo esc_html($field_source . ' ' . __('(tidak terdeteksi)', 'ringan-export')); ?>
                                            </option>
                                        <?php endif; ?>
                                    </select>
                                    <?php if ($reason !== ''): ?>
                                        <div class="ringan-export-mapping-reason"><?php echo esc_html($reason); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="ringan-export-status is-<?php echo esc_attr($status); ?>">
                                        <?php echo esc_html($mapping_status_labels[$status] ?? $status); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                    <fieldset>
                        <legend><?php esc_html_e('Pembersihan konten saat ekspor', 'ringan-export'); ?></legend>
                        <label class="ringan-export-check">
                            <input type="checkbox" name="clean_html" value="1" <?php checked($clean_options['clean_html']); ?>>
                            <?php esc_html_e('Rapikan HTML (buang blok Gutenberg, tag kosong, spasi berlebih)', 'ringan-export'); ?>
                        </label>
                        <label class="ringan-export-check">
                            <input type="checkbox" name="clean_shortcodes" value="1" <?php checked($clean_options['clean_shortcodes']); ?>>
                            <?php esc_html_e('Buang shortcode ([caption], [gallery], …)', 'ringan-export'); ?>
                        </label>
                        <label class="ringan-export-check">
                            <input type="checkbox" name="strip_attributes" value="1" <?php checked($clean_options['strip_attributes']); ?>>
                            <?php esc_html_e('Buang atribut tema (class, style, srcset, data-*)', 'ringan-export'); ?>
                        </label>
                        <label class="ringan-export-check">
                            <input type="checkbox" name="absolute_urls" value="1" <?php checked($clean_options['absolute_urls']); ?>>
                            <?php esc_html_e('Ubah URL relatif menjadi absolut agar media ikut terbawa', 'ringan-export'); ?>
                        </label>
                    </fieldset>

                    <p>
                        <button type="submit" class="button button-primary">
                            <?php esc_html_e('Simpan Pemetaan', 'ringan-export'); ?>
                        </button>
                    </p>
                </form>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ringan-export-inline-form">
                    <input type="hidden" name="action" value="ringan_export_auto_map">
                    <?php wp_nonce_field('ringan_export_mapping'); ?>
                    <button type="submit" class="button">
                        <?php esc_html_e('Jalankan Pemetaan Otomatis', 'ringan-export'); ?>
                    </button>
                </form>

                <p>
                    <button type="button" class="button" id="ringan-export-preview">
                        <?php esc_html_e('Pratinjau 1 post', 'ringan-export'); ?>
                    </button>
                    <span class="description">
                        <?php esc_html_e('Melihat hasil pemetaan + pembersihan pada post pertama sebelum ekspor penuh.', 'ringan-export'); ?>
                    </span>
                </p>
                <pre id="ringan-export-preview-out" class="ringan-export-preview" hidden></pre>
            </div>
        <?php endif; ?>

        <?php if ($profile !== []): ?>
            <div class="card">
                <h2><?php esc_html_e('4. Jalankan ekspor', 'ringan-export'); ?></h2>
                <form id="ringan-export-form">
                    <fieldset>
                        <legend><?php esc_html_e('Post type', 'ringan-export'); ?></legend>
                        <?php foreach ($post_types as $type_name => $type_label): ?>
                            <label class="ringan-export-check">
                                <input type="checkbox" name="post_types[]" value="<?php echo esc_attr($type_name); ?>"
                                    <?php checked(in_array($type_name, $profile_post_types, true)); ?>>
                                <?php echo esc_html($type_label); ?>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>
                    <fieldset>
                        <legend><?php esc_html_e('Status post', 'ringan-export'); ?></legend>
                        <?php foreach ($status_options as $status): ?>
                            <label class="ringan-export-check">
                                <input type="checkbox" name="post_statuses[]" value="<?php echo esc_attr($status); ?>"
                                    <?php checked(in_array($status, $profile_statuses, true)); ?>>
                                <?php echo esc_html($status); ?>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>

                    <p>
                        <button type="submit" class="button button-primary" id="ringan-export-start">
                            <?php esc_html_e('Mulai Ekspor', 'ringan-export'); ?>
                        </button>
                        <button type="button" class="button" id="ringan-export-cancel" hidden>
                            <?php esc_html_e('Batalkan', 'ringan-export'); ?>
                        </button>
                    </p>
                </form>

                <div id="ringan-export-progress" hidden>
                    <progress id="ringan-export-bar" max="1" value="0"></progress>
                    <p id="ringan-export-status" class="description"></p>
                    <div id="ringan-export-log" class="ringan-export-log" aria-live="polite"></div>
                    <p id="ringan-export-download" hidden>
                        <a class="button button-primary" id="ringan-export-link" href="#">
                            <?php esc_html_e('Unduh ZIP', 'ringan-export'); ?>
                        </a>
                        <span id="ringan-export-size" class="description"></span>
                    </p>
                </div>

                <p class="description">
                    <?php
                    printf(
                        /* translators: %d: batch size */
                        esc_html__('Ekspor berjalan bertahap (%d post per permintaan) agar aman untuk situs besar. Jangan tutup halaman ini sampai selesai.', 'ringan-export'),
                        (int) RINGAN_EXPORT_BATCH_SIZE
                    );
                    ?>
                </p>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2><?php esc_html_e('Yang diekspor', 'ringan-export'); ?></h2>
            <ul class="ringan-export-list">
                <li><?php esc_html_e('Judul, konten, excerpt, slug, tanggal, status, author, dan featured image.', 'ringan-export'); ?></li>
                <li><?php esc_html_e('Field ACF sesuai tabel pemetaan (termasuk gambar, file, galeri, relasi, dan tanggal).', 'ringan-export'); ?></li>
                <li><?php esc_html_e('Konten dibersihkan dari blok Gutenberg, shortcode, atribut tema, dan URL relatif sebelum masuk ZIP.', 'ringan-export'); ?></li>
                <li><?php esc_html_e('Setiap nilai dicek terhadap tipe field CMS agar Import ZIP tidak menolak arsip.', 'ringan-export'); ?></li>
                <li><?php esc_html_e('Media dari folder uploads (gambar/file yang dipakai field maupun konten) dengan validasi tipe & ukuran.', 'ringan-export'); ?></li>
                <li><?php esc_html_e('Kategori/tag yang dipetakan di profil; entri tanpa padanan taksonomi dilewati dengan peringatan.', 'ringan-export'); ?></li>
            </ul>
            <p class="description">
                <?php esc_html_e('Repeater/flexible content ACF belum dipetakan otomatis karena CMS belum memiliki tipe field tersebut.', 'ringan-export'); ?>
            </p>
        </div>

        <details class="ringan-export-diagnostics">
            <summary><?php esc_html_e('Diagnostik server', 'ringan-export'); ?></summary>
            <table class="widefat striped">
                <tbody>
                <?php foreach (ringan_export_diagnostics() as $label => $value): ?>
                    <tr>
                        <th scope="row"><?php echo esc_html($label); ?></th>
                        <td><code><?php echo esc_html($value); ?></code></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description">
                <?php esc_html_e('Bila ZIP terunduh 0 byte, pastikan zlib.output_compression Off dan folder temporary dapat ditulis. Detail kegagalan unduhan dicatat di log PHP dengan awalan [ringan-export].', 'ringan-export'); ?>
            </p>
        </details>
    </div>
    <?php
}

/**
 * Ringkasan setelan server yang memengaruhi ekspor/unduhan ZIP.
 *
 * @return array<string,string>
 */
function ringan_export_diagnostics(): array
{
    $temp_dir = rtrim((string) get_temp_dir(), '/\\');
    $writable = $temp_dir !== '' && is_dir($temp_dir) && wp_is_writable($temp_dir);
    $zlib = (string) ini_get('zlib.output_compression');
    if ($zlib === '') {
        $zlib = '0';
    }

    return [
        __('PHP', 'ringan-export') => PHP_VERSION,
        __('WordPress', 'ringan-export') => (string) get_bloginfo('version'),
        __('ZipArchive (ext-zip)', 'ringan-export') => class_exists('ZipArchive') ? __('aktif', 'ringan-export') : __('TIDAK AKTIF', 'ringan-export'),
        __('Folder temporary', 'ringan-export') => $temp_dir . ($writable ? ' (' . __('dapat ditulis', 'ringan-export') . ')' : ' (' . __('TIDAK dapat ditulis', 'ringan-export') . ')'),
        __('zlib.output_compression', 'ringan-export') => $zlib === '0' || strtolower($zlib) === 'off' ? $zlib : $zlib . ' (' . __('bisa membuat unduhan ZIP 0 byte', 'ringan-export') . ')',
        __('output_buffering', 'ringan-export') => (string) (ini_get('output_buffering') ?: '0'),
        __('memory_limit', 'ringan-export') => (string) ini_get('memory_limit'),
        __('max_execution_time', 'ringan-export') => (string) ini_get('max_execution_time'),
        __('Batas post per ekspor', 'ringan-export') => (string) RINGAN_EXPORT_MAX_POSTS,
        __('Batas media per ekspor', 'ringan-export') => (string) RINGAN_EXPORT_MAX_MEDIA_FILES,
    ];
}

/**
 * Hapus profil draft (dipanggil dari tautan "Kosongkan").
 */
function ringan_export_handle_clear_profile(): void
{
    if (!current_user_can(ringan_export_capability())) {
        wp_die(esc_html__('Anda tidak memiliki akses.', 'ringan-export'), 403);
    }
    check_admin_referer('ringan_export_profile');
    ringan_export_draft_clear(get_current_user_id());
    wp_safe_redirect(add_query_arg('ringan_export_notice', 'profile_cleared', ringan_export_admin_url()));
    exit;
}
add_action('admin_post_ringan_export_clear_profile', 'ringan_export_handle_clear_profile');