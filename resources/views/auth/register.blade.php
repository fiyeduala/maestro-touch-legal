@extends('layouts.public', ['title' => 'Register', 'noindex' => true])

@section('content')
    <section class="site-container pb-24">
        <h1 class="text-[32px] leading-tight font-semibold text-ink md:text-[36px]">Register</h1>

        <div class="mt-6">
            @include('partials.form-status')

            <form method="post" action="{{ route('register') }}" class="space-y-6" novalidate>
                @csrf
                <div class="hidden" aria-hidden="true">
                    <label for="company_website">Leave this empty</label>
                    <input id="company_website" name="company_website" type="text" tabindex="-1" autocomplete="off">
                </div>

                <div class="grid gap-6 md:grid-cols-2">
                    <div>
                        <label class="form-label" for="first_name">First Name <span class="req">*</span></label>
                        <input id="first_name" name="first_name" value="{{ old('first_name') }}" required autocomplete="given-name" class="form-input h-[52px]">
                        @error('first_name')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="form-label" for="last_name">Last Name <span class="req">*</span></label>
                        <input id="last_name" name="last_name" value="{{ old('last_name') }}" required autocomplete="family-name" class="form-input h-[52px]">
                        @error('last_name')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div>
                    <label class="form-label" for="email">Email Address <span class="req">*</span></label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email" class="form-input h-[52px]">
                    @error('email')<p class="form-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="form-label" for="phone">Phone Number</label>
                    <input id="phone" name="phone" type="tel" value="{{ old('phone') }}" autocomplete="tel" class="form-input h-[52px]">
                    @error('phone')<p class="form-error">{{ $message }}</p>@enderror
                </div>
                <div class="grid gap-6 md:grid-cols-2">
                    <div>
                        <label class="form-label" for="password">Password <span class="req">*</span></label>
                        <input id="password" name="password" type="password" required autocomplete="new-password" class="form-input h-[52px]" aria-describedby="password-help">
                        <p id="password-help" class="form-help">At least 12 characters, with letters and numbers.</p>
                        @error('password')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="form-label" for="password_confirmation">Confirm Password <span class="req">*</span></label>
                        <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="form-input h-[52px]">
                    </div>
                </div>

                <div>
                    <label class="flex items-start gap-2">
                        <input class="mt-1.5" type="checkbox" name="accept_terms" value="1" @checked(old('accept_terms'))>
                        <span>By registering to this website you agree to the <a class="text-brand hover:underline" href="{{ \App\Support\SiteUrl::to('/terms-and-conditions/') }}" target="_blank" rel="noopener">terms &amp; conditions</a>. <span class="req">*</span></span>
                    </label>
                    @error('accept_terms')<p class="form-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="flex items-start gap-2">
                        <input class="mt-1.5" type="checkbox" name="accept_privacy" value="1" @checked(old('accept_privacy'))>
                        <span>I have read and accept the <a class="text-brand hover:underline" href="{{ \App\Support\SiteUrl::to('/privacy-policy/') }}" target="_blank" rel="noopener">privacy policy</a> and allow “Maestro Touch Legal” to collect and store the data I submit through this form. <span class="req">*</span></span>
                    </label>
                    @error('accept_privacy')<p class="form-error">{{ $message }}</p>@enderror
                </div>

                <button type="submit" class="btn">Register</button>
            </form>

            <div class="mt-6 space-y-2 pl-10">
                <p>Already have an account? <a class="text-brand hover:underline" href="{{ \App\Support\SiteUrl::to('/log-in/') }}">Sign In »</a></p>
                <p><a class="text-brand hover:underline" href="{{ \App\Support\SiteUrl::to('/password-reset/') }}">Lost your password?</a></p>
            </div>
        </div>
    </section>
@endsection
