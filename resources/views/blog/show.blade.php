@php
    use App\Domain\Operations\Settings;
    use Mews\Purifier\Facades\Purifier;
    $tz = config('app.firm_timezone');
    $date = fn ($d) => $d?->timezone($tz)->format('F j, Y');
    $commentsOpen = ! $preview && $post->comments_visible && Settings::get('content.comments_open');
@endphp
@extends('layouts.public', [
    'title' => $post->meta_title ?: $post->title,
    'description' => $post->meta_description ?: $post->excerpt,
    'canonical' => $canonical,
    'ogImage' => $post->cover?->url(),
    'ogType' => 'article',
    'publishedTime' => $post->published_at?->toIso8601String(),
    'noindex' => $preview,
])

@section('content')
    @if ($preview)
        <div class="fixed inset-x-0 bottom-0 z-50 bg-ink px-4 py-2 text-center text-sm text-white" role="status">
            Preview — status: {{ \App\Models\Post::STATUSES[$post->status] ?? $post->status }}.
        </div>
    @endif

    <div class="bg-tint py-10 md:py-16">
        <div class="mx-auto max-w-[710px] px-2 md:px-0">
            <article class="bg-white px-6 py-8 md:px-10 md:py-12">
                <header>
                    <h1 class="text-[21px] leading-[1.3] font-semibold text-ink md:text-[30px]">{{ $post->title }}</h1>
                    <p class="mt-4 text-sm">
                        By {{ $post->byline() }}
                        @if ($post->published_at)
                            / <time datetime="{{ $post->published_at->toIso8601String() }}">{{ $date($post->published_at) }}</time>
                        @endif
                    </p>
                </header>

                @if ($post->cover)
                    <img src="{{ $post->cover->url() }}" alt="{{ $post->cover->alt_text }}" class="mt-6 h-auto w-full"
                         @if ($post->cover->width) width="{{ $post->cover->width }}" height="{{ $post->cover->height }}" @endif>
                @endif

                <div class="prose-mtl prose-post mt-8">
                    {!! Purifier::clean($post->body, 'content') !!}
                </div>

                @if ($post->tags->isNotEmpty())
                    <p class="mt-8 text-sm">
                        Tags:
                        @foreach ($post->tags as $tag)
                            <a class="text-brand hover:underline" href="{{ \App\Support\SiteUrl::to('/tag/'.$tag->slug) }}">{{ $tag->name }}</a>@if (! $loop->last), @endif
                        @endforeach
                    </p>
                @endif
            </article>

            @if ($previous || $next)
                <nav class="mt-8 flex justify-between gap-4 text-sm" aria-label="Post navigation">
                    <span>@if ($previous)<a class="text-brand hover:underline" href="{{ $previous->url() }}" rel="prev">← Previous Post</a>@endif</span>
                    <span>@if ($next)<a class="text-brand hover:underline" href="{{ $next->url() }}" rel="next">Next Post →</a>@endif</span>
                </nav>
            @endif

            @if ($related->isNotEmpty())
                <section class="mt-8 bg-white px-5 py-8 md:px-10" aria-labelledby="related-heading">
                    <h2 id="related-heading" class="text-2xl font-semibold text-ink">Related Posts</h2>
                    <div class="mt-6 grid gap-8 sm:grid-cols-2">
                        @foreach ($related as $r)
                            <div>
                                @if ($r->cover)
                                    <a href="{{ $r->url() }}" tabindex="-1" aria-hidden="true"><img src="{{ $r->cover->url() }}" alt="" loading="lazy" class="mb-3 aspect-[4/3] w-full object-cover"></a>
                                @endif
                                <h3 class="text-base font-medium text-ink"><a href="{{ $r->url() }}" class="hover:text-brand">{{ $r->title }}</a></h3>
                                <p class="mt-1 text-xs text-brand">{{ $r->categories->pluck('name')->join(', ') }}@if ($r->categories->isNotEmpty()) / @endif{{ $date($r->published_at) }}</p>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            @if ($comments->isNotEmpty())
                <section class="mt-8 bg-white px-5 py-8 md:px-10" aria-labelledby="comments-heading">
                    <h2 id="comments-heading" class="text-2xl font-medium text-ink">
                        {{ $comments->count() === 1 ? 'One thought' : $comments->count().' thoughts' }} on “{{ $post->title }}”
                    </h2>
                    <ol class="mt-6 space-y-8">
                        @foreach ($comments as $comment)
                            <li id="comment-{{ $comment->id }}">
                                <p class="text-xs font-semibold tracking-wide text-brand uppercase">{{ $comment->author_name }}</p>
                                <p class="text-xs text-brand uppercase">{{ $comment->posted_at->timezone($tz)->format('F j, Y \a\t g:i A') }}</p>
                                <div class="mt-3 text-sm">{!! Purifier::clean($comment->body, 'comment') !!}</div>
                            </li>
                        @endforeach
                    </ol>
                </section>
            @endif

            @if ($commentsOpen)
                <section class="mt-8 bg-white px-5 py-8 md:px-10" aria-labelledby="reply-heading">
                    <h2 id="reply-heading" class="text-2xl font-medium text-ink">Leave a Comment</h2>
                    @if (session('comment_status'))
                        <div class="alert alert-success mt-4" role="status">{{ session('comment_status') }}</div>
                    @endif
                    <p class="mt-2 text-sm">Your email address will not be published. Required fields are marked <span class="req">*</span></p>
                    <form method="post" action="{{ route('comments.store', $post->slug) }}" class="mt-6 space-y-4">
                        @csrf
                        <div class="hidden" aria-hidden="true">
                            <label for="website_url">Leave this empty</label>
                            <input id="website_url" name="website_url" type="text" tabindex="-1" autocomplete="off">
                        </div>
                        <div>
                            <label class="form-label" for="comment">Comment <span class="req">*</span></label>
                            <textarea id="comment" name="comment" rows="8" required maxlength="5000" class="form-input">{{ old('comment') }}</textarea>
                            @error('comment')<p class="form-error">{{ $message }}</p>@enderror
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="form-label" for="author_name">Name <span class="req">*</span></label>
                                <input id="author_name" name="author_name" required maxlength="100" value="{{ old('author_name') }}" class="form-input" autocomplete="name">
                                @error('author_name')<p class="form-error">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="form-label" for="author_email">Email <span class="req">*</span></label>
                                <input id="author_email" name="author_email" type="email" required maxlength="190" value="{{ old('author_email') }}" class="form-input" autocomplete="email">
                                @error('author_email')<p class="form-error">{{ $message }}</p>@enderror
                            </div>
                        </div>
                        <button type="submit" class="btn">Post Comment »</button>
                        <p class="form-help">Comments are reviewed before they appear.</p>
                    </form>
                </section>
            @endif
        </div>
    </div>
@endsection
