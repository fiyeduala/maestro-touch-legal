<?php

/**
 * Maestro Touch Legal — public site audit extractor.
 *
 * Reads the raw HTML captured in docs/source-capture/html (see fetch.sh) and
 * produces:
 *   docs/content-manifest.json  exact copy grouped by page/section/widget
 *   docs/asset-manifest.json    images/logo/favicon/fonts referenced by the site
 *   docs/source-capture/css/    Elementor/theme CSS files referenced by pages
 *
 * Captured HTML is treated purely as data. Nothing in it is executed.
 *
 * Usage: php tools/site-audit/extract.php [--no-fetch-css]
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$capture = "$root/docs/source-capture";
$fetchCss = ! in_array('--no-fetch-css', $argv, true);
$capturedAt = date('c', filemtime("$capture/html/home.html"));

// Pages that are part of the public site we mirror. Plugin/system pages
// (WooCommerce, WP Customer Area, WP 2FA, Zoom test) are inventoried in
// docs/site-inventory.md but not mirrored.
$publicPages = [
    'home' => 'https://mtouchlegal.com/',
    'about' => 'https://mtouchlegal.com/about/',
    'offering' => 'https://mtouchlegal.com/offering/',
    'contact' => 'https://mtouchlegal.com/contact/',
    'blog' => 'https://mtouchlegal.com/blog/',
    'terms-and-conditions' => 'https://mtouchlegal.com/terms-and-conditions/',
    'register' => 'https://mtouchlegal.com/register/',
    'log-in' => 'https://mtouchlegal.com/log-in/',
    'password-reset' => 'https://mtouchlegal.com/password-reset/',
];

$posts = json_decode(file_get_contents("$capture/rest/posts.json"), true);
foreach ($posts as $p) {
    $publicPages[$p['slug']] = $p['link'];
}

function clean(string $s): string
{
    return trim(preg_replace('/\s+/u', ' ', html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

function innerHtml(DOMNode $n): string
{
    $h = '';
    foreach ($n->childNodes as $c) {
        $h .= $n->ownerDocument->saveHTML($c);
    }

    return trim(preg_replace('/\s+/u', ' ', $h));
}

function loadDom(string $file): array
{
    $d = new DOMDocument;
    libxml_use_internal_errors(true);
    $d->loadHTML('<?xml encoding="UTF-8">'.file_get_contents($file));
    libxml_clear_errors();

    return [$d, new DOMXPath($d)];
}

function largestFromSrcset(string $srcset): ?string
{
    $best = null;
    $bestW = 0;
    foreach (array_filter(array_map('trim', explode(',', $srcset))) as $cand) {
        [$url, $w] = array_pad(preg_split('/\s+/', $cand), 2, '0w');
        $w = (int) $w;
        if ($w > $bestW) {
            [$best, $bestW] = [$url, $w];
        }
    }

    return $best;
}

$assets = [];
$addAsset = function (string $url, array $meta) use (&$assets) {
    $url = html_entity_decode(trim($url));
    if ($url === '' || str_starts_with($url, 'data:')) {
        return;
    }
    if (str_starts_with($url, '//')) {
        $url = "https:$url";
    }
    $key = strtok($url, '?');
    $existing = $assets[$key] ?? ['original_url' => $key, 'used_on' => [], 'kinds' => []];
    $existing['used_on'] = array_values(array_unique(array_merge($existing['used_on'], (array) ($meta['page'] ?? []))));
    $existing['kinds'] = array_values(array_unique(array_merge($existing['kinds'], (array) ($meta['kind'] ?? []))));
    foreach (['alt', 'width', 'height'] as $k) {
        if (! empty($meta[$k]) && empty($existing[$k])) {
            $existing[$k] = $meta[$k];
        }
    }
    $assets[$key] = $existing;
};

$cssFiles = [];
$manifest = [
    'generated_at' => date('c'),
    'captured_at' => $capturedAt,
    'source' => 'https://mtouchlegal.com/',
    'method' => 'Raw HTML fetched with curl; copy extracted from Elementor widgets in document order. Text is verbatim from the HTML (entities decoded, whitespace collapsed).',
    'verification_note' => 'status "extracted" means machine-extracted from HTML. It becomes "verified" only after a side-by-side visual comparison against docs/reference-screenshots.',
    'global' => null,
    'pages' => [],
];

foreach ($publicPages as $slug => $url) {
    $file = "$capture/html/$slug.html";
    if (! is_file($file)) {
        fwrite(STDERR, "missing capture for $slug\n");

        continue;
    }
    [$d, $x] = loadDom($file);

    $meta = [];
    foreach ($x->query('//meta[@name or @property]') as $m) {
        $k = $m->getAttribute('name') ?: $m->getAttribute('property');
        if (in_array($k, ['description', 'robots', 'og:title', 'og:description', 'og:image', 'og:type', 'twitter:card', 'article:published_time', 'article:modified_time'], true)) {
            $meta[$k] = $m->getAttribute('content');
        }
    }
    $canonical = $x->query('//link[@rel="canonical"]')->item(0)?->getAttribute('href');

    foreach ($x->query('//link[@rel="stylesheet"]') as $l) {
        $href = $l->getAttribute('href');
        if (str_contains($href, 'mtouchlegal.com')) {
            $cssFiles[strtok($href, '?')] = $href;
        }
    }

    // Header/footer are site-wide: capture once from the homepage.
    if ($slug === 'home') {
        $nav = [];
        foreach ($x->query('//header[@id="masthead"]//nav//a | //header[@id="masthead"]//a[contains(@class,"menu-link") or contains(@class,"ast-button")]') as $a) {
            $item = ['text' => clean($a->textContent), 'href' => $a->getAttribute('href')];
            if ($item['text'] !== '' && ! in_array($item, $nav, true)) {
                $nav[] = $item;
            }
        }
        $logo = $x->query('//header[@id="masthead"]//img')->item(0);
        $manifest['global'] = [
            'site_name' => $x->query('//meta[@property="og:site_name"]')->item(0)?->getAttribute('content'),
            'header' => [
                'logo' => $logo ? ['src' => $logo->getAttribute('src'), 'alt' => $logo->getAttribute('alt'), 'width' => $logo->getAttribute('width'), 'height' => $logo->getAttribute('height')] : null,
                'navigation' => $nav,
            ],
            'footer' => ['text' => clean($x->query('//footer[@id="colophon"]')->item(0)?->textContent ?? '')],
            'favicons' => array_map(fn ($l) => ['rel' => $l->getAttribute('rel'), 'href' => $l->getAttribute('href'), 'sizes' => $l->getAttribute('sizes')], iterator_to_array($x->query('//link[@rel="icon" or @rel="apple-touch-icon"]'))),
            'fonts_loaded' => array_values(array_unique(array_map(fn ($l) => $l->getAttribute('href'), array_filter(iterator_to_array($x->query('//link[@rel="stylesheet"]')), fn ($l) => str_contains($l->getAttribute('href'), 'fonts.googleapis'))))),
            'third_party_scripts_detected' => array_values(array_filter([
                str_contains(file_get_contents($file), 'embed.tawk.to') ? 'Tawk.to live chat' : null,
                str_contains(file_get_contents($file), 'googletagmanager') ? 'Google Tag Manager / Analytics' : null,
            ])),
        ];
        foreach ($manifest['global']['favicons'] as $f) {
            $addAsset($f['href'], ['page' => 'global', 'kind' => 'favicon']);
        }
        if ($logo) {
            $addAsset($logo->getAttribute('src'), ['page' => 'global', 'kind' => 'logo', 'alt' => $logo->getAttribute('alt'), 'width' => $logo->getAttribute('width'), 'height' => $logo->getAttribute('height')]);
        }
    }

    $contentRoot = $x->query('//*[@data-elementor-type="wp-page" or @data-elementor-type="wp-post" or @data-elementor-type="single-post"]')->item(0)
        ?? $x->query('//article')->item(0)
        ?? $x->query('//main')->item(0);

    $sections = [];
    if ($contentRoot && $contentRoot->getAttribute('data-elementor-type')) {
        // Top-level Elementor containers/sections, in document order.
        $tops = $x->query('./*[contains(@class,"e-con") or contains(@class,"elementor-section") or contains(@class,"elementor-element")]', $contentRoot);
        foreach ($tops as $i => $sec) {
            $widgets = [];
            foreach ($x->query('.//*[@data-widget_type]', $sec) as $w) {
                $type = $w->getAttribute('data-widget_type');
                $entry = ['type' => $type, 'elementor_id' => $w->getAttribute('data-id')];
                $headings = [];
                foreach ($x->query('.//h1|.//h2|.//h3|.//h4|.//h5|.//h6', $w) as $h) {
                    $headings[] = ['level' => $h->nodeName, 'text' => clean($h->textContent)];
                }
                if ($headings) {
                    $entry['headings'] = $headings;
                }
                $text = clean($w->textContent);
                if ($text !== '') {
                    $entry['text'] = $text;
                }
                if (str_starts_with($type, 'text-editor') || str_starts_with($type, 'theme-post-content')) {
                    $c = $x->query('.//*[contains(@class,"elementor-widget-container")]', $w)->item(0) ?? $w;
                    $entry['html'] = innerHtml($c);
                }
                $links = [];
                foreach ($x->query('.//a[@href]', $w) as $a) {
                    $links[] = ['text' => clean($a->textContent), 'href' => $a->getAttribute('href')];
                }
                if ($links) {
                    $entry['links'] = $links;
                }
                $imgs = [];
                foreach ($x->query('.//img', $w) as $img) {
                    $src = largestFromSrcset($img->getAttribute('srcset')) ?? $img->getAttribute('src');
                    $imgs[] = ['src' => $src, 'alt' => $img->getAttribute('alt'), 'width' => $img->getAttribute('width'), 'height' => $img->getAttribute('height')];
                    $addAsset($src, ['page' => $slug, 'kind' => 'content-image', 'alt' => $img->getAttribute('alt'), 'width' => $img->getAttribute('width'), 'height' => $img->getAttribute('height')]);
                }
                if ($imgs) {
                    $entry['images'] = $imgs;
                }
                $icons = [];
                foreach ($x->query('.//svg[@class]', $w) as $svg) {
                    $icons[] = $svg->getAttribute('class');
                }
                if ($icons) {
                    $entry['icons'] = array_values(array_unique($icons));
                }
                $widgets[] = $entry;
            }
            $sections[] = [
                'index' => $i + 1,
                'elementor_id' => $sec->getAttribute('data-id'),
                'classes' => $sec->getAttribute('class'),
                'widgets' => $widgets,
            ];
        }
    } elseif ($contentRoot) {
        // Non-Elementor content (default blog/post templates).
        $sections[] = ['index' => 1, 'elementor_id' => null, 'classes' => $contentRoot->getAttribute('class'), 'widgets' => [[
            'type' => 'theme-content',
            'headings' => array_map(fn ($h) => ['level' => $h->nodeName, 'text' => clean($h->textContent)], iterator_to_array($x->query('.//h1|.//h2|.//h3|.//h4', $contentRoot))),
            'text' => clean($contentRoot->textContent),
        ]]];
        foreach ($x->query('.//img', $contentRoot) as $img) {
            $addAsset(largestFromSrcset($img->getAttribute('srcset')) ?? $img->getAttribute('src'), ['page' => $slug, 'kind' => 'content-image', 'alt' => $img->getAttribute('alt')]);
        }
    }

    $manifest['pages'][] = [
        'slug' => $slug,
        'source_url' => $url,
        'title' => clean($x->query('//title')->item(0)?->textContent ?? ''),
        'canonical' => $canonical,
        'meta' => $meta,
        'captured_at' => $capturedAt,
        'verification_status' => 'extracted',
        'sections' => $sections,
    ];
}

// Elementor/theme CSS: holds background images, colours, typography, spacing.
@mkdir("$capture/css", 0777, true);
foreach ($cssFiles as $key => $href) {
    $name = preg_replace('#[^a-z0-9._-]+#i', '_', str_replace('https://mtouchlegal.com/', '', $key));
    $dest = "$capture/css/$name";
    if ($fetchCss && ! is_file($dest)) {
        $ctx = stream_context_create(['http' => ['timeout' => 30, 'user_agent' => 'Mozilla/5.0 MaestroSiteAudit']]);
        $css = @file_get_contents($href, false, $ctx);
        if ($css !== false) {
            file_put_contents($dest, $css);
        }
    }
    if (is_file($dest)) {
        preg_match_all('/url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/i', file_get_contents($dest), $m);
        foreach ($m[1] as $u) {
            if (preg_match('/\.(png|jpe?g|webp|gif|svg)$/i', strtok($u, '?')) && str_contains($u, 'wp-content/uploads')) {
                $addAsset($u, ['page' => "css:$name", 'kind' => 'css-background']);
            }
        }
    }
}

// Media library (REST) — fills in dimensions/alt text and lists unused items.
$media = json_decode(file_get_contents("$capture/rest/media.json"), true);
foreach ($media as $m) {
    $url = $m['source_url'];
    $meta = ['kind' => 'media-library', 'alt' => $m['alt_text'] ?? '', 'width' => $m['media_details']['width'] ?? null, 'height' => $m['media_details']['height'] ?? null];
    $addAsset($url, $meta);
    $assets[strtok($url, '?')]['wp_media_id'] = $m['id'];
    $assets[strtok($url, '?')]['mime_type'] = $m['mime_type'];
}

// Map resized variants (foo-300x200.jpg) back to their originals where known.
foreach ($assets as $k => &$a) {
    $path = parse_url($k, PHP_URL_PATH) ?? '';
    $a['local_path'] = str_contains($path, '/wp-content/uploads/')
        ? 'public/media/legacy/'.ltrim(substr($path, strpos($path, '/wp-content/uploads/') + strlen('/wp-content/uploads/')), '/')
        : null;
    $a['retrieval_status'] = $a['local_path'] && is_file("$root/".$a['local_path']) ? 'retrieved' : 'pending';
}
unset($a);

ksort($assets);
file_put_contents("$root/docs/content-manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
file_put_contents("$root/docs/asset-manifest.json", json_encode([
    'generated_at' => date('c'),
    'note' => 'local_path is where the asset is self-hosted in the Laravel app. retrieval_status reflects the file actually existing on disk.',
    'assets' => array_values($assets),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

printf("pages: %d, assets: %d, css files: %d\n", count($manifest['pages']), count($assets), count($cssFiles));
