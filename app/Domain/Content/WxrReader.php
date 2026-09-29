<?php

namespace App\Domain\Content;

use RuntimeException;
use SimpleXMLElement;

/**
 * Reads a WordPress export file (Tools → Export → All content, "WXR") into the same shape as the REST capture,
 * so WordPressImporter handles both. WXR is the more complete source: it includes drafts, private posts,
 * comments awaiting moderation and commenters' email addresses, which the public REST API hides.
 * WordPress IDs are the same in both, so switching source keeps the import mappings.
 *
 * Content is stored raw in WXR; paragraphs are rebuilt the way WordPress displays them (a simplified
 * wpautop). Shortcodes are not run: they are reported so someone can check those posts by eye.
 */
class WxrReader
{
    private const NS = [
        'wp' => 'http://wordpress.org/export/1.2/',
        'content' => 'http://purl.org/rss/1.0/modules/content/',
        'excerpt' => 'http://wordpress.org/export/1.2/excerpt/',
        'dc' => 'http://purl.org/dc/elements/1.1/',
    ];

    private const BLOCK_TAGS = 'p|div|h[1-6]|ul|ol|li|blockquote|figure|figcaption|table|thead|tbody|tr|td|th|pre|hr|img|iframe|section|article|aside|header|footer|address|dl|dt|dd|form|!--';

    /** @return array{categories: list<array>, tags: list<array>, media: list<array>, posts: list<array>, comments: list<array>, users: list<array>, notes: list<string>} */
    public static function read(string $file): array
    {
        if (! is_file($file)) {
            throw new RuntimeException("WordPress export file not found: {$file}");
        }

        $previous = libxml_use_internal_errors(true);
        // LIBXML_NONET: never fetch anything the file points to. Entity loading is off by default in PHP 8.
        $xml = simplexml_load_file($file, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_PARSEHUGE);
        libxml_use_internal_errors($previous);
        if (! $xml || ! isset($xml->channel)) {
            throw new RuntimeException('This does not look like a WordPress export (WXR) file.');
        }

        $channel = $xml->channel;
        $version = (string) $channel->children(self::NS['wp'])->wxr_version;
        $data = ['categories' => [], 'tags' => [], 'media' => [], 'posts' => [], 'comments' => [], 'users' => [], 'notes' => []];
        if ($version !== '' && version_compare($version, '1.1', '<')) {
            $data['notes'][] = "Old WXR version {$version}; check the results carefully.";
        }

        $wp = $channel->children(self::NS['wp']);
        $logins = [];
        foreach ($wp->author as $author) {
            $id = (string) $author->author_id;
            $logins[(string) $author->author_login] = $id;
            $data['users'][] = ['id' => $id, 'name' => (string) $author->author_display_name, 'slug' => (string) $author->author_login];
        }
        $categoryIds = [];
        foreach ($wp->category as $term) {
            $categoryIds[(string) $term->category_nicename] = (string) $term->term_id;
            $data['categories'][] = ['id' => (string) $term->term_id, 'name' => (string) $term->cat_name,
                'slug' => (string) $term->category_nicename, 'description' => (string) $term->category_description];
        }
        $tagIds = [];
        foreach ($wp->tag as $term) {
            $tagIds[(string) $term->tag_slug] = (string) $term->term_id;
            $data['tags'][] = ['id' => (string) $term->term_id, 'name' => (string) $term->tag_name, 'slug' => (string) $term->tag_slug];
        }

        foreach ($channel->item as $item) {
            $w = $item->children(self::NS['wp']);
            $type = (string) $w->post_type;
            $id = (string) $w->post_id;
            $meta = [];
            foreach ($w->postmeta as $pm) {
                $meta[(string) $pm->meta_key] = (string) $pm->meta_value;
            }

            if ($type === 'attachment') {
                $url = (string) $w->attachment_url;
                $data['media'][] = [
                    'id' => $id,
                    'source_url' => $url,
                    'mime_type' => self::mime($url),
                    'alt_text' => $meta['_wp_attachment_image_alt'] ?? '',
                    'caption' => ['rendered' => (string) $item->children(self::NS['excerpt'])->encoded],
                    'link' => (string) $item->link,
                ];

                continue;
            }
            if ($type !== 'post') {
                continue; // Pages are rebuilt as fixed templates; menus, blocks and revisions are not imported.
            }

            $categories = $tags = [];
            foreach ($item->category as $term) {
                $domain = (string) $term['domain'];
                $slug = (string) $term['nicename'];
                if ($domain === 'category') {
                    $categories[] = $categoryIds[$slug] ?? self::addTerm($data['categories'], $categoryIds, $slug, (string) $term, true);
                } elseif ($domain === 'post_tag') {
                    $tags[] = $tagIds[$slug] ?? self::addTerm($data['tags'], $tagIds, $slug, (string) $term, false);
                }
            }

            $raw = (string) $item->children(self::NS['content'])->encoded;
            if (preg_match_all('/\[([a-z_][a-z0-9_-]*)[\s\]]/i', $raw, $m)) {
                $data['notes'][] = "Post {$id} uses shortcodes [".implode('], [', array_unique($m[1])).'] which are not run here; check it by eye.';
            }
            $status = (string) $w->status;
            $login = (string) $item->children(self::NS['dc'])->creator;
            $data['posts'][] = [
                'id' => $id,
                'slug' => (string) $w->post_name !== '' ? urldecode((string) $w->post_name) : "post-{$id}",
                'status' => $status,
                'link' => (string) $item->link,
                'title' => ['rendered' => (string) $item->title],
                'content' => ['rendered' => self::autop($raw)],
                'excerpt' => ['rendered' => self::autop((string) $item->children(self::NS['excerpt'])->encoded)],
                'date_gmt' => self::date((string) $w->post_date_gmt),
                'modified_gmt' => self::date((string) ($w->post_modified_gmt ?? '')),
                'author' => $logins[$login] ?? $login,
                'featured_media' => $meta['_thumbnail_id'] ?? 0,
                'categories' => $categories,
                'tags' => $tags,
            ];

            foreach ($w->comment as $comment) {
                $commentType = (string) $comment->comment_type;
                if ($commentType !== '' && $commentType !== 'comment') {
                    continue; // pingbacks and trackbacks
                }
                $data['comments'][] = [
                    'id' => (string) $comment->comment_id,
                    'post' => $id,
                    'parent' => (string) $comment->comment_parent,
                    'author_name' => (string) $comment->comment_author,
                    'author_email' => (string) $comment->comment_author_email,
                    'content' => ['rendered' => self::autop((string) $comment->comment_content)],
                    'status' => match ((string) $comment->comment_approved) {
                        '1' => 'approved', 'spam' => 'spam', 'trash' => 'trash', default => 'hold',
                    },
                    'date_gmt' => self::date((string) $comment->comment_date_gmt),
                ];
            }
        }

        return $data;
    }

    /** Simplified wpautop: blank-line-separated text becomes paragraphs; existing block HTML is left alone. */
    public static function autop(string $text): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($text === '') {
            return '';
        }
        $chunks = preg_split('/\n\s*\n/', $text);

        return implode("\n", array_map(function (string $chunk) {
            $chunk = trim($chunk);

            return preg_match('#^<(?:'.self::BLOCK_TAGS.')[\s>/]#i', $chunk.' ')
                ? $chunk
                : '<p>'.preg_replace('/\n/', "<br>\n", $chunk).'</p>';
        }, $chunks));
    }

    private static function addTerm(array &$list, array &$ids, string $slug, string $name, bool $withDescription): string
    {
        $id = 'slug:'.$slug;
        $ids[$slug] = $id;
        $list[] = ['id' => $id, 'name' => $name, 'slug' => $slug] + ($withDescription ? ['description' => ''] : []);

        return $id;
    }

    private static function date(string $value): ?string
    {
        return $value === '' || str_starts_with($value, '0000') ? null : str_replace(' ', 'T', $value);
    }

    private static function mime(string $url): string
    {
        return match (strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }
}
