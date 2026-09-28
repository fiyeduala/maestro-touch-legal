@extends('layouts.public', [
    'title' => $page->path === '/' ? null : ($revision->meta_title ?: $revision->title),
    'description' => $revision->meta_description,
    'canonical' => $canonical,
    'noindex' => $preview || $revision->noindex,
    'overlayHeader' => true,
])

@section('content')
    @if ($preview)
        <div class="fixed inset-x-0 bottom-0 z-50 bg-ink px-4 py-2 text-center text-sm text-white" role="status">
            Preview of revision #{{ $revision->number }} ({{ $revision->status }}). Not visible to the public until published.
        </div>
    @endif

    @include('pages.templates.'.$page->template, ['revision' => $revision])
@endsection
