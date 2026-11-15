<?php
/**
 * Pembersih konten WordPress sebelum masuk manifest.
 *
 * Konten post WordPress membawa banyak sisa yang tidak berarti di Ringan CMS:
 * penanda blok Gutenberg (`<!-- wp:paragraph -->`), shortcode (`[caption]`,
 * `[gallery]`, …), atribut hasil tema (`class`, `srcset`, `data-*`), URL
 * relatif, dan tag kosong. Semua itu dibersihkan di sini supaya HTML yang
 * diimpor sudah rapi dan URL media bisa dipetakan ke file di folder uploads.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Opsi pembersihan dari profil (default: semua aktif).
 *
 * @return array{clean_html:bool,clean_shortcodes:bool,strip_attributes:bool,absolute_urls:bool}
 */
function ringan_export_clean_options(array $profile): array
{
    $options = is_array($profile['options'] ?? null) ? $profile['options'] : [];
    $flag = static function (string $key) use ($options): bool {
        return array_key_exists($key, $options) ? !empty($options[$key]) : true;
    };

    return [
        'clean_html' => $flag('clean_html'),
        'clean_shortcodes' => $flag('clean_shortcodes'),
        'strip_attributes' => $flag('strip_attributes'),
        'absolute_urls' => $flag('absolute_urls'),
    ];
}

/**
 * Ubah URL relatif menjadi absolut agar media bisa dicocokkan ke file lokal.
 *
 * `/wp-content/uploads/2024/01/a.jpg`, `wp-content/uploads/...`, dan
 * `//example.com/...` semuanya dinormalkan. Skema non-http (mailto:, tel:,
 * #anchor, data:) dibiarkan apa adanya.
 */
function ringan_export_absolute_url(string $url): string
{
    $url = trim(html_entity_decode($url, ENT_QUOTES, 'UTF-8'));
    if ($url === '') {
        return '';
    }
    if (preg_match('~^(?:[a-z][a-z0-9+.-]*:|#)~i', $url)) {
        return $url;
    }
    if (str_starts_with($url, '//')) {
        return (is_ssl() ? 'https:' : 'http:') . $url;
    }
    if (str_starts_with($url, '/')) {
        return home_url($url);
    }
    return home_url('/' . ltrim($url, './'));
}

/**
 * Absolutkan semua atribut src/href pada HTML.
 */
function ringan_export_absolutize_html_urls(string $html): string
{
    $result = preg_replace_callback(
        '#\b(src|href)\s*=\s*("([^"]*)"|\'([^\']*)\')#i',
        static function (array $match): string {
            $quote = $match[2][0];
            $url = (string) (($match[3] ?? '') !== '' ? $match[3] : ($match[4] ?? ''));
            return $match[1] . '=' . $quote . ringan_export_absolute_url($url) . $quote;
        },
        $html
    );

    return is_string($result) ? $result : $html;
}

/**
 * Buang shortcode WordPress/plugin.
 *
 * Shortcode yang menghasilkan markup kompleks tidak bisa dirender CMS, jadi
 * dibuang. `[caption]` mempertahankan isinya (gambar + teks caption) dan
 * `[embed]` diubah menjadi tautan biasa agar tidak hilang tanpa jejak.
 */
function ringan_export_strip_shortcodes(string $html): string
{
    if ($html === '' || strpos($html, '[') === false) {
        return $html;
    }

    // [caption ...]isi[/caption] → isi
    $html = preg_replace('#\[caption\b[^\]]*\](.*?)\[/caption\]#is', '$1', $html) ?? $html;

    // [embed ...]url[/embed] → tautan
    $html = preg_replace_callback(
        '#\[embed\b[^\]]*\](.*?)\[/embed\]#is',
        static function (array $match): string {
            $url = trim((string) $match[1]);
            if ($url === '' || !preg_match('#^https?://#i', $url)) {
                return '';
            }
            $safe = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            return '<p><a href="' . $safe . '">' . $safe . '</a></p>';
        },
        $html
    ) ?? $html;

    // Shortcode media yang tidak punya padanan di CMS.
    $html = preg_replace(
        '#\[(?:gallery|video|audio|playlist|wpvideo|wp_caption|wp_audio_shortcode|wp_video_shortcode)\b[^\]]*\]#i',
        '',
        $html
    ) ?? $html;

    // Shortcode berpasangan yang belum dikenal: buang beserta isinya.
    $html = preg_replace('#\[([a-z][a-z0-9_-]{1,30})\b[^\]]*\].*?\[/\1\]#is', '', $html) ?? $html;

    // Shortcode tunggal yang tersisa: [nama], [nama attr="..."], [/nama].
    $html = preg_replace('#\[/?[a-z][a-z0-9_-]{1,30}\b[^\]]*\]#i', '', $html) ?? $html;

    return $html;
}

/**
 * Whitelist atribut per tag. Atribut lain (class, id, style, srcset, data-*,
 * event handler) dibuang karena tidak dibutuhkan CMS dan hanya menambah
 * ukuran ZIP.
 *
 * @return array<string,string[]>
 */
function ringan_export_attribute_whitelist(): array
{
    return [
        'a' => ['href', 'title', 'rel', 'target'],
        'img' => ['src', 'alt', 'title', 'width', 'height'],
        'td' => ['colspan', 'rowspan', 'scope'],
        'th' => ['colspan', 'rowspan', 'scope'],
        'ol' => ['start'],
        'blockquote' => ['cite'],
    ];
}

/**
 * Bersihkan atribut HTML sesuai whitelist dan pastikan setiap <img> punya alt.
 */
function ringan_export_strip_html_attributes(string $html): string
{
    $whitelist = ringan_export_attribute_whitelist();

    $result = preg_replace_callback(
        '#<([a-z][a-z0-9]*)\b([^>]*)>#i',
        static function (array $match) use ($whitelist): string {
            $tag = strtolower($match[1]);
            $raw = (string) $match[2];
            $self_closing = str_ends_with(rtrim($raw), '/');

            $allowed = $whitelist[$tag] ?? [];
            $attributes = [];
            if ($raw !== '' && preg_match_all(
                '#([a-z0-9_:-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))#i',
                $raw,
                $found,
                PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL
            )) {
                foreach ($found as $attribute) {
                    $name = strtolower((string) $attribute[1]);
                    if (!in_array($name, $allowed, true) || isset($attributes[$name])) {
                        continue;
                    }
                    $value = (string) ($attribute[2] ?? $attribute[3] ?? $attribute[4] ?? '');
                    $attributes[$name] = $value;
                }
            }

            if ($tag === 'img' && !isset($attributes['alt'])) {
                $attributes['alt'] = '';
            }

            $out = '<' . $tag;
            foreach ($attributes as $name => $value) {
                $out .= ' ' . $name . '="' . $value . '"';
            }
            if ($self_closing && in_array($tag, ['img', 'br', 'hr'], true)) {
                return $out . '>';
            }
            return $out . '>';
        },
        $html
    );

    return is_string($result) ? $result : $html;
}

/**
 * Ganti <iframe> dengan tautan agar embed video tidak hilang tanpa jejak.
 */
function ringan_export_replace_iframes(string $html): string
{
    if (stripos($html, '<iframe') === false) {
        return $html;
    }

    $result = preg_replace_callback(
        '#<iframe\b[^>]*\bsrc\s*=\s*("([^"]*)"|\'([^\']*)\')[^>]*>.*?</iframe>#is',
        static function (array $match): string {
            $url = trim((string) (($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? '')));
            if ($url === '' || !preg_match('#^https?://#i', $url)) {
                return '';
            }
            $safe = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            return '<p><a href="' . $safe . '">' . $safe . '</a></p>';
        },
        $html
    ) ?? $html;

    // iframe tanpa pasangan penutup / tanpa src.
    return preg_replace('#<iframe\b[^>]*>.*?</iframe>#is', '', $result) ?? $result;
}

/**
 * Buang tag yang isinya kosong (spasi, &nbsp;, atau <br>) secara berulang.
 */
function ringan_export_remove_empty_tags(string $html): string
{
    $tags = 'p|div|span|h[1-6]|li|blockquote|figure|figcaption|strong|em|b|i|u|s|section|article|td|th';
    $pattern = '#<(' . $tags . ')\b[^>]*>(?:\s|&nbsp;|<br\s*/?>)*</\1>#i';

    for ($i = 0; $i < 5; $i++) {
        $next = preg_replace($pattern, '', $html);
        if (!is_string($next) || $next === $html) {
            break;
        }
        $html = $next;
    }

    return $html;
}

/**
 * Lindungi isi <pre>/<code> dari perapatan spasi.
 *
 * @return array{html:string,blocks:string[]}
 */
function ringan_export_protect_pre(string $html): array
{
    $blocks = [];
    $protected = preg_replace_callback(
        '#<(pre|code)\b[^>]*>.*?</\1>#is',
        static function (array $match) use (&$blocks): string {
            $index = count($blocks);
            $blocks[$index] = $match[0];
            return "\x1A" . $index . "\x1A";
        },
        $html
    );

    return ['html' => is_string($protected) ? $protected : $html, 'blocks' => $blocks];
}

function ringan_export_restore_pre(string $html, array $blocks): string
{
    if ($blocks === []) {
        return $html;
    }
    return (string) preg_replace_callback(
        '/\x1A(\d+)\x1A/',
        static fn(array $match): string => (string) ($blocks[(int) $match[1]] ?? ''),
        $html
    );
}

/**
 * Bersihkan HTML konten post agar siap diimpor Ringan CMS.
 *
 * @param array{clean_html?:bool,clean_shortcodes?:bool,strip_attributes?:bool,absolute_urls?:bool} $options
 */
function ringan_export_clean_html(string $html, array $options = []): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }

    $options = array_merge([
        'clean_html' => true,
        'clean_shortcodes' => true,
        'strip_attributes' => true,
        'absolute_urls' => true,
    ], $options);

    // 1. Elemen yang tidak diizinkan CMS (dan berbahaya).
    $html = preg_replace('#<(script|style|noscript|template)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
    $html = preg_replace('#<(script|style|noscript|template)\b[^>]*/?>#i', '', $html) ?? $html;

    // 2. Komentar HTML — termasuk penanda blok Gutenberg.
    $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;

    // 3. Embed yang tidak bisa dirender.
    $html = ringan_export_replace_iframes($html);

    // 4. Shortcode.
    if ($options['clean_shortcodes']) {
        $html = ringan_export_strip_shortcodes($html);
    }

    // 5. URL relatif → absolut (sebelum media dipetakan).
    if ($options['absolute_urls']) {
        $html = ringan_export_absolutize_html_urls($html);
    }

    // 6. Atribut: hanya yang berguna.
    if ($options['strip_attributes']) {
        $html = ringan_export_strip_html_attributes($html);
    }

    if (!$options['clean_html']) {
        return trim($html);
    }

    // 7. Tag kosong & spasi berlebih (isi <pre>/<code> dilindungi).
    $protected = ringan_export_protect_pre($html);
    $html = $protected['html'];
    $html = ringan_export_remove_empty_tags($html);
    // Spasi non-breaking yang menempel di tepi tag tidak ada gunanya di CMS.
    $html = preg_replace('#(?:\s|&nbsp;|\x{00A0})*(?:&nbsp;|\x{00A0})(?:\s|&nbsp;|\x{00A0})*(?=</)#iu', '', $html) ?? $html;
    $html = preg_replace('#(?<=>)(?:\s|&nbsp;|\x{00A0})*(?:&nbsp;|\x{00A0})(?:\s|&nbsp;|\x{00A0})*#iu', '', $html) ?? $html;
    $html = preg_replace('#(?:&nbsp;|\x{00A0}){2,}#iu', ' ', $html) ?? $html;
    $html = preg_replace('#<br\s*/?>#i', '<br>', $html) ?? $html;
    $html = preg_replace('#(?:<br>\s*){3,}#i', '<br><br>', $html) ?? $html;
    $html = preg_replace('/>\s+</', '><', $html) ?? $html;
    $html = preg_replace('/[ \t]{2,}/', ' ', $html) ?? $html;
    $html = ringan_export_restore_pre($html, $protected['blocks']);

    return trim($html);
}

/**
 * Bersihkan teks biasa: buang shortcode, tag, komentar, dan spasi berlebih.
 */
function ringan_export_clean_text(string $text): string
{
    $text = preg_replace('/<!--.*?-->/s', '', $text) ?? $text;
    $text = ringan_export_strip_shortcodes($text);
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

    return trim($text);
}
