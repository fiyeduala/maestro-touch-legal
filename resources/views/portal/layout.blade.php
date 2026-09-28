@extends('layouts.public', ['title' => $title ?? 'Client Area', 'noindex' => true])

@section('content')
    <section class="site-container pt-6 pb-24">
        <div class="flex flex-wrap items-end justify-between gap-4 border-b border-line pb-4">
            <div>
                <p class="text-sm text-body/80">Client Area</p>
                <h1 class="text-[28px] leading-tight font-semibold text-ink md:text-[32px]">{{ $title ?? 'Client Area' }}</h1>
            </div>
            <nav aria-label="Client area" class="flex flex-wrap items-center gap-6 text-[15px] font-medium">
                <a href="{{ route('portal.home') }}" @class(['text-brand' => request()->routeIs('portal.home'), 'hover:text-brand'])>Overview</a>
                <a href="{{ route('portal.profile') }}" @class(['text-brand' => request()->routeIs('portal.profile'), 'hover:text-brand'])>Profile &amp; Security</a>
                <form method="post" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="hover:text-brand">Sign Out</button>
                </form>
            </nav>
        </div>

        <div class="mt-8">
            @include('partials.form-status')
            @yield('portal')
        </div>
    </section>
@endsection
