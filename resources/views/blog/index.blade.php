@php
    use Illuminate\Support\Str;
    /** @var \Illuminate\Pagination\LengthAwarePaginator $posts */
@endphp
@extends('layouts.public', [
    'title' => $heading,
    'canonical' => $canonical,
    'noindex' => $noindex,
])

@section('content')
    <div class="bg-tint">
        <section class="site-container pt-[120px] pb-[120px] md:pt-[140px] md:pb-[160px]">
            <h1 class="heading-1">{{ $heading }}</h1>
        </section>

        <div class="site-container pb-24">
            @if ($posts->isEmpty())
                <div class="bg-white p-8">
                    <p>It seems we can’t find what you’re looking for. Perhaps searching can help.</p>
                    <form action="{{ url('/') }}" method="get" role="search" class="mt-4 flex max-w-md gap-2">
                        <label for="s" class="sr-only">Search for:</label>
                        <input id="s" type="search" name="s" value="{{ request('s') }}" class="form-input" placeholder="Search …">
                        <button class="btn" type="submit">Search</button>
                    </form>
                </div>
            @else
                <div class="grid gap-8 md:grid-cols-2 lg:grid-cols-3">
                    @foreach ($posts as $post)
                        <article class="flex flex-col bg-white shadow-[0_2px_10px_rgba(15,23,42,0.04)]">
                            @if ($post->cover)
                                <a href="{{ $post->url() }}" tabindex="-1" aria-hidden="true">
                                    <img src="{{ $post->cover->url() }}" alt="{{ $post->cover->alt_text }}" loading="lazy"
                                         @if ($post->cover->width) width="{{ $post->cover->width }}" height="{{ $post->cover->height }}" @endif
                                         class="aspect-[4/3] w-full object-cover">
                                </a>
                            @endif
                            <div class="flex flex-1 flex-col px-6 pt-6 pb-10">
                                <h2 class="text-[26px] leading-[1.25] font-normal text-ink">
                                    <a href="{{ $post->url() }}" class="hover:text-brand">{{ $post->title }}</a>
                                </h2>
                                <p class="mt-4 text-[15px] text-brand">
                                    <time datetime="{{ $post->published_at->toIso8601String() }}">{{ $post->published_at->timezone(config('app.firm_timezone'))->format('F j, Y') }}</time>
                                </p>
                                <p class="mt-4 text-[15px] leading-[1.65]">{{ Str::words(strip_tags($post->excerpt ?: $post->body), 25, '') }}</p>
                                <a href="{{ $post->url() }}" class="mt-6 text-[15px] text-brand hover:underline">
                                    Read More »<span class="sr-only"> about {{ $post->title }}</span>
                                </a>
                            </div>
                        </article>
                    @endforeach
                </div>

                @if ($posts->hasPages())
                    <nav class="mt-12 flex flex-wrap items-center justify-center gap-2" aria-label="Posts pagination">
                        @if (! $posts->onFirstPage())
                            <a class="px-3 py-2 text-brand hover:underline" href="{{ $pageUrl($posts->currentPage() - 1) }}" rel="prev">← Previous</a>
                        @endif
                        @for ($n = 1; $n <= $posts->lastPage(); $n++)
                            @if ($n === $posts->currentPage())
                                <span class="rounded bg-brand px-3 py-2 text-white" aria-current="page">{{ $n }}</span>
                            @else
                                <a class="rounded px-3 py-2 text-brand hover:bg-white" href="{{ $pageUrl($n) }}">{{ $n }}</a>
                            @endif
                        @endfor
                        @if ($posts->hasMorePages())
                            <a class="px-3 py-2 text-brand hover:underline" href="{{ $pageUrl($posts->currentPage() + 1) }}" rel="next">Next →</a>
                        @endif
                    </nav>
                @endif
            @endif
        </div>
    </div>
@endsection
