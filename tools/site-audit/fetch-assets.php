<?php

/**
 * Downloads every asset in docs/asset-manifest.json into its local_path,
 * then records dimensions, checksum and retrieval status back into the manifest.
 *
 * Only https://mtouchlegal.com/wp-content/uploads/* is fetched (no redirects
 * followed off-host), responses are capped at 15 MB and must be real images
 * (or PDF) by content sniffing, not by extension.
 *
 * Usage: php tools/site-audit/fetch-assets.php
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$manifestFile = "$root/docs/asset-manifest.json";
$manifest = json_decode(file_get_contents($manifestFile), true);
$maxBytes = 15 * 1024 * 1024;
$allowedMime = ['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'image/svg+xml', 'application/pdf'];
$finfo = new finfo(FILEINFO_MIME_TYPE);

foreach ($manifest['assets'] as &$a) {
    $url = $a['original_url'];
    if (! preg_match('#^https://mtouchlegal\.com/wp-content/uploads/[A-Za-z0-9/_.\-()%]+$#', $url) || str_contains($url, '..') || ! $a['local_path']) {
        $a['retrieval_status'] = 'skipped: not an allowed source URL';

        continue;
    }
    $dest = "$root/".$a['local_path'];

    if (! is_file($dest)) {
        $ch = curl_init($url);
        $buf = '';
        // Local PHP may lack curl.cainfo; use the Mozilla bundle if present (never disable verification).
        if (! ini_get('curl.cainfo') && is_file("$root/storage/certs/cacert.pem")) {
            curl_setopt($ch, CURLOPT_CAINFO, "$root/storage/certs/cacert.pem");
        }
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_USERAGENT => 'Mozilla/5.0 MaestroSiteAudit',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$buf, $maxBytes) {
                $buf .= $chunk;

                return strlen($buf) > $maxBytes ? 0 : strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($code !== 200 || $err) {
            $a['retrieval_status'] = "failed: HTTP $code $err";

            continue;
        }
        $mime = $finfo->buffer($buf);
        if (! in_array($mime, $allowedMime, true)) {
            $a['retrieval_status'] = "rejected: unexpected content type $mime";

            continue;
        }
        @mkdir(dirname($dest), 0777, true);
        file_put_contents($dest, $buf);
    }

    $a['retrieval_status'] = 'retrieved';
    $a['bytes'] = filesize($dest);
    $a['sha256'] = hash_file('sha256', $dest);
    $a['mime_type'] = $finfo->file($dest);
    if ($size = @getimagesize($dest)) {
        $a['width'] = $size[0];
        $a['height'] = $size[1];
    }
}
unset($a);

file_put_contents($manifestFile, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
$counts = array_count_values(array_map(fn ($a) => explode(':', $a['retrieval_status'])[0], $manifest['assets']));
print_r($counts);
foreach ($manifest['assets'] as $a) {
    if ($a['retrieval_status'] !== 'retrieved') {
        echo $a['retrieval_status'], '  ', $a['original_url'], "\n";
    }
}
