@extends('layouts.public', ['title' => 'Password Reset', 'noindex' => true])

@section('content')
    <section class="site-container pb-24">
        <h1 class="text-[32px] leading-tight font-semibold text-ink md:text-[36px]">Password Reset</h1>

        <div class="mt-6">
            @include('partials.form-status')
            <p>Lost your password? Please enter your username or email address. You will receive a link to create a new password via email.</p>

            <form method="post" action="{{ route('password.email') }}" class="mt-6 space-y-6" novalidate>
                @csrf
                <div>
                    <label class="form-label" for="email">Email Address <span class="req">*</span></label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email" class="form-input h-[52px]">
                    @error('email')<p class="form-error">{{ $message }}</p>@enderror
                </div>
                <button type="submit" class="btn">Reset Password</button>
            </form>

            <div class="mt-6 space-y-2 pl-10">
                <p>Already have an account? <a class="text-brand hover:underline" href="{{ \App\Support\SiteUrl::to('/log-in/') }}">Sign In »</a></p>
                <p>Don’t have an account? <a class="text-brand hover:underline" href="{{ \App\Support\SiteUrl::to('/register/') }}">Signup Now »</a></p>
            </div>
        </div>
    </section>
@endsection
