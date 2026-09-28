@extends('layouts.public', ['title' => 'Verify Your Email', 'noindex' => true])

@section('content')
    <section class="site-container pb-24">
        <h1 class="text-[32px] leading-tight font-semibold text-ink md:text-[36px]">Verify Your Email Address</h1>

        <div class="mt-6 max-w-[760px]">
            @include('partials.form-status')
            <p>Before you use the client area, please click the link we emailed to <strong class="text-ink">{{ auth()->user()->email }}</strong>. If it has not arrived after a few minutes, check your spam folder or send it again.</p>

            <div class="mt-6 flex flex-wrap items-center gap-6">
                <form method="post" action="{{ route('verification.send') }}">
                    @csrf
                    <button type="submit" class="btn">Send the Link Again</button>
                </form>
                <form method="post" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="text-brand hover:underline">Sign out</button>
                </form>
            </div>
        </div>
    </section>
@endsection
