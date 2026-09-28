<?php

/*
 * HTML sanitisation profiles (mews/purifier / HTMLPurifier).
 *
 * - content: blog posts, imported WordPress HTML and legal pages. No scripts, iframes,
 *   inline event handlers, forms or style attributes; links limited to http(s)/mailto/tel.
 * - comment: public comments. Paragraphs, emphasis and links only.
 */

return [
    'encoding' => 'UTF-8',
    'finalize' => true,
    'ignoreNonStrings' => false,
    'cachePath' => storage_path('app/purifier'),
    'cacheFileMode' => 0755,
    'settings' => [
        'default' => [
            'HTML.Doctype' => 'HTML 4.01 Transitional',
            'HTML.Allowed' => 'p,br,b,strong,i,em,u,a[href|title],ul,ol,li',
            'URI.AllowedSchemes' => ['http' => true, 'https' => true, 'mailto' => true],
            'AutoFormat.RemoveEmpty' => true,
        ],
        'content' => [
            'HTML.Doctype' => 'HTML 4.01 Transitional',
            'HTML.Allowed' => implode(',', [
                'p', 'br', 'hr', 'b', 'strong', 'i', 'em', 'u', 's', 'sub', 'sup', 'mark', 'small', 'code', 'pre',
                'blockquote[cite]', 'cite', 'q', 'abbr[title]',
                'h2[id]', 'h3[id]', 'h4[id]', 'h5', 'h6',
                'ul', 'ol[start]', 'li',
                'a[href|title|target]',
                'img[src|alt|width|height|title]',
                'figure', 'figcaption',
                'table', 'thead', 'tbody', 'tfoot', 'tr', 'th[colspan|rowspan|scope]', 'td[colspan|rowspan]', 'caption',
                'span', 'div',
            ]),
            'Attr.EnableID' => true,
            'Attr.IDPrefix' => 'c-',
            'Attr.AllowedFrameTargets' => ['_blank'],
            'HTML.TargetNoopener' => true,
            'HTML.TargetNoreferrer' => true,
            'URI.AllowedSchemes' => ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true],
            'AutoFormat.RemoveEmpty' => true,
            'AutoFormat.RemoveEmpty.RemoveNbsp' => false,
        ],
        'comment' => [
            'HTML.Doctype' => 'HTML 4.01 Transitional',
            'HTML.Allowed' => 'p,br,b,strong,i,em,a[href]',
            'HTML.Nofollow' => true,
            'URI.AllowedSchemes' => ['http' => true, 'https' => true],
            'AutoFormat.AutoParagraph' => true,
            'AutoFormat.Linkify' => false,
            'AutoFormat.RemoveEmpty' => true,
        ],
        'custom_definition' => [
            'id' => 'mtl-html5',
            'rev' => 1,
            'debug' => false,
            'elements' => [
                ['figure', 'Block', 'Optional: (figcaption, Flow) | (Flow, figcaption) | Flow', 'Common'],
                ['figcaption', 'Inline', 'Flow', 'Common'],
                ['s', 'Inline', 'Inline', 'Common'],
                ['sub', 'Inline', 'Inline', 'Common'],
                ['sup', 'Inline', 'Inline', 'Common'],
                ['mark', 'Inline', 'Inline', 'Common'],
            ],
            'attributes' => [],
        ],
        'custom_attributes' => [
            ['a', 'target', 'Enum#_blank'],
        ],
        'custom_elements' => [
            ['u', 'Inline', 'Inline', 'Common'],
        ],
    ],
];
