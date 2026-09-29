@extends('layouts.public', ['title' => $title ?? 'Client Area', 'noindex' => true])

@section('content')
    <section class="site-container pt-6 pb-24">
        <div class="flex flex-wrap items-end justify-between gap-4 border-b border-line pb-4" data-portal-chrome>
            <div>
                <p class="text-sm text-body/80">Client Area</p>
                <h1 class="text-[28px] leading-tight font-semibold text-ink md:text-[32px]">{{ $title ?? 'Client Area' }}</h1>
            </div>
            <nav aria-label="Client area" class="flex flex-wrap items-center gap-6 text-[15px] font-medium">
                <a href="{{ route('portal.home') }}" @class(['text-brand' => request()->routeIs('portal.home', 'portal.matters.*'), 'hover:text-brand'])>Matters</a>
                @php($portalUnread = array_sum(app(\App\Domain\Communication\Conversations::class)->clientUnread(auth()->user())))
                <a href="{{ route('portal.messages') }}" @class(['text-brand' => request()->routeIs('portal.messages'), 'hover:text-brand'])>
                    Messages
                    @if ($portalUnread) <span class="ml-1 rounded-full bg-brand px-2 py-0.5 text-xs text-white">{{ $portalUnread }}<span class="sr-only"> unread</span></span>@endif
                </a>
                <a href="{{ route('portal.invoices') }}" @class(['text-brand' => request()->routeIs('portal.invoices*', 'portal.payments.*', 'portal.funds'), 'hover:text-brand'])>Invoices</a>
                <a href="{{ route('portal.appointments') }}" @class(['text-brand' => request()->routeIs('portal.appointments'), 'hover:text-brand'])>Appointments</a>
                <a href="{{ $enquiryUrl }}" class="hover:text-brand">New Request</a>
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
