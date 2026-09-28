@extends('layouts.public', ['title' => 'Set a New Password', 'noindex' => true])

@section('content')
    <section class="site-container pb-24">
        <h1 class="text-[32px] leading-tight font-semibold text-ink md:text-[36px]">Set a New Password</h1>

        <form method="post" action="{{ route('password.store', $token) }}" class="mt-6 space-y-6" novalidate>
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <div>
                <label class="form-label" for="email">Email Address <span class="req">*</span></label>
                <input id="email" name="email" type="email" value="{{ old('email', $email) }}" required autocomplete="username" class="form-input h-[52px]">
                @error('email')<p class="form-error">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="form-label" for="password">New Password <span class="req">*</span></label>
                <input id="password" name="password" type="password" required autocomplete="new-password" class="form-input h-[52px]">
                <p class="form-help">At least 12 characters, with letters and numbers.</p>
                @error('password')<p class="form-error">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="form-label" for="password_confirmation">Confirm New Password <span class="req">*</span></label>
                <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="form-input h-[52px]">
            </div>
            <button type="submit" class="btn">Save Password</button>
        </form>
    </section>
@endsection
