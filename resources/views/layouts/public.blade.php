@php
    use App\Domain\Operations\Settings;
    use App\Support\Markup;
    use App\Support\SiteUrl;

    $siteTitle = Settings::get('site.title');
    $pageTitle = isset($title) && $title !== '' ? Markup::plain($title).' - '.$siteTitle : $siteTitle;
    $metaDescription = $description ?? null;
    // Public URLs end in "/" (WordPress style); url()->current() would drop it.
    $canonical = $canonical ?? SiteUrl::to(request()->path());
    $ogImage = $ogImage ?? Settings::get('site.og_image_path');
    $overlayHeader = $overlayHeader ?? false;
    $headerTone = $headerTone ?? 'white';
@endphp
<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageTitle }}</title>
    @if ($metaDescription)
        <meta name="description" content="{{ \Illuminate\Support\Str::limit(Markup::plain($metaDescription), 160) }}">
    @endif
    <meta name="robots" content="{{ ($noindex ?? false) ? 'noindex, nofollow' : 'index, follow, max-image-preview:large' }}">
    <link rel="canonical" href="{{ $canonical }}">

    <meta property="og:locale" content="en_GB">
    <meta property="og:type" content="{{ $ogType ?? 'website' }}">
    <meta property="og:title" content="{{ $pageTitle }}">
    @if ($metaDescription)
        <meta property="og:description" content="{{ \Illuminate\Support\Str::limit(Markup::plain($metaDescription), 200) }}">
    @endif
    <meta property="og:url" content="{{ $canonical }}">
    <meta property="og:site_name" content="{{ $siteTitle }}">
    @if ($ogImage)
        <meta property="og:image" content="{{ Markup::safeUrl($ogImage) }}">
    @endif
    <meta name="twitter:card" content="summary_large_image">
    @isset($publishedTime)
        <meta property="article:published_time" content="{{ $publishedTime }}">
    @endisset

    @if ($verification = Settings::get('seo.google_site_verification'))
        <meta name="google-site-verification" content="{{ $verification }}">
    @endif

    <link rel="icon" href="{{ url('/images/2025/08/blue-1-150x150.png') }}" sizes="32x32">
    <link rel="icon" href="{{ Markup::safeUrl(Settings::get('site.favicon_path')) }}" sizes="192x192">
    <link rel="apple-touch-icon" href="{{ Markup::safeUrl(Settings::get('site.favicon_path')) }}">
    <link rel="alternate" type="application/rss+xml" title="{{ $siteTitle }} &raquo; Feed" href="{{ \App\Support\SiteUrl::to('/feed/') }}">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="flex min-h-screen flex-col">
    <a href="#content" class="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50 focus:rounded focus:bg-white focus:px-4 focus:py-2 focus:text-brand">Skip to content</a>

    @include('partials.site-header', ['overlay' => $overlayHeader, 'tone' => $headerTone])

    <main id="content" class="flex-1">
        @yield('content')
    </main>

    @include('partials.site-footer')

    @include('partials.tawk')
    @stack('scripts')
</body>
</html>
