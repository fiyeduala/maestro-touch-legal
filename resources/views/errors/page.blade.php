{{-- Shared 4xx page. Uses the full site layout so visitors keep the navigation. --}}
@extends('layouts.public', ['title' => $heading, 'noindex' => true, 'overlayHeader' => true])

@section('content')
    <section class="page-banner pt-[150px] pb-16 md:pb-[100px]">
        <div class="site-container">
            <p class="text-sm font-semibold tracking-wide text-brand">Error {{ $code }}</p>
            <h1 class="heading-1 mt-2">{{ $heading }}</h1>
            <p class="mt-4 max-w-[640px] text-lg">{{ $text }}</p>
            <div class="mt-8 flex flex-wrap gap-4">
                <a class="btn" href="{{ url('/') }}">Back to Home</a>
                @if ($code === 404)
                    <form method="get" action="{{ url('/') }}" role="search" class="flex">
                        <label class="sr-only" for="error-search">Search</label>
                        <input id="error-search" type="search" name="s" placeholder="Search…" class="form-input h-[50px] w-56 bg-white">
                    </form>
                @endif
            </div>
        </div>
    </section>
@endsection
