@extends('layouts.public', ['title' => 'Log In', 'noindex' => true])

@section('content')
    <section class="site-container pb-24">
        <h1 class="text-[32px] leading-tight font-semibold text-ink md:text-[36px]">Log In</h1>

        <div class="mt-6">
            @include('partials.form-status')

            <form method="post" action="{{ route('login') }}" class="space-y-6" novalidate>
                @csrf
                <div>
                    <label class="form-label" for="email">Email Address <span class="req">*</span></label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="username" autofocus
                           class="form-input h-[52px]" @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                    @error('email')<p id="email-error" class="form-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="form-label" for="password">Password <span class="req">*</span></label>
                    <input id="password" name="password" type="password" required autocomplete="current-password" class="form-input h-[52px]">
                    @error('password')<p class="form-error">{{ $message }}</p>@enderror
                </div>
                <label class="flex items-center gap-2">
                    <input type="checkbox" name="remember" value="1" @checked(old('remember'))> Remember me
                </label>
                <button type="submit" class="btn">Login</button>
            </form>

            <div class="mt-6 space-y-2 pl-10">
                <p>Don’t have an account? <a class="text-brand hover:underline" href="{{ url('/register/') }}">Signup Now »</a></p>
                <p><a class="text-brand hover:underline" href="{{ url('/password-reset/') }}">Lost your password?</a></p>
            </div>
            <p class="mt-8 text-sm">Firm staff sign in through the <a class="text-brand hover:underline" href="{{ url('/admin/login') }}">staff portal</a>.</p>
        </div>
    </section>
@endsection
