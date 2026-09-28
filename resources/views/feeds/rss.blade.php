{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}
@php use App\Domain\Operations\Settings; use App\Support\SiteUrl; @endphp
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/elements/1.1/">
<channel>
    <title>{{ Settings::get('site.title') }}</title>
    <atom:link href="{{ SiteUrl::to('/feed') }}" rel="self" type="application/rss+xml" />
    <link>{{ SiteUrl::to('/') }}</link>
    <description>{{ Settings::get('site.tagline') }}</description>
    <language>en-GB</language>
    @if ($posts->isNotEmpty())
        <lastBuildDate>{{ $posts->first()->published_at->toRssString() }}</lastBuildDate>
    @endif
    @foreach ($posts as $post)
        <item>
            <title>{{ $post->title }}</title>
            <link>{{ SiteUrl::to('/'.$post->slug) }}</link>
            <guid isPermaLink="true">{{ SiteUrl::to('/'.$post->slug) }}</guid>
            <dc:creator>{{ $post->byline() }}</dc:creator>
            <pubDate>{{ $post->published_at->toRssString() }}</pubDate>
            @foreach ($post->categories as $category)
                <category>{{ $category->name }}</category>
            @endforeach
            <description>{{ \Illuminate\Support\Str::words(strip_tags($post->excerpt ?: $post->body), 55, ' […]') }}</description>
        </item>
    @endforeach
</channel>
</rss>
