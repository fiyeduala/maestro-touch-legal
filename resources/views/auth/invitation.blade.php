@extends('layouts.public', ['title' => 'Accept Invitation', 'noindex' => true])

@php
    $isStaffInvite = collect($invitation->roles)->contains(fn ($r) => $r !== 'client');
@endphp

@section('content')
    <section class="site-container pb-24">
        <h1 class="text-[32px] leading-tight font-semibold text-ink md:text-[36px]">Accept Your Invitation</h1>

        <div class="mt-6 max-w-[760px]">
            <p>
                You have been invited to Maestro Touch Legal as <strong class="text-ink">{{ $roleLabels->join(', ', ' and ') }}</strong>
                using <strong class="text-ink">{{ $invitation->email }}</strong>.
                This link expires on {{ $invitation->expires_at->timezone(config('app.firm_timezone'))->format('j F Y, g:i A') }} (Lagos time).
            </p>

            <div class="mt-6">
                @include('partials.form-status')
                @error('invitation')<div class="alert alert-error mb-6" role="alert">{{ $message }}</div>@enderror

                @if ($signedInAsOther)
                    <div class="alert alert-info">You are signed in with a different account. Sign out, then open this link again.</div>
                    <form method="post" action="{{ route('logout') }}" class="mt-4">
                        @csrf
                        <button type="submit" class="btn">Sign Out</button>
                    </form>
                @elseif ($signedInAsInvitee)
                    <form method="post" action="{{ route('invitation.accept', $token) }}">
                        @csrf
                        <button type="submit" class="btn">Accept Invitation</button>
                    </form>
                @elseif ($accountExists)
                    <div class="alert alert-info">
                        An account already exists for this address. Please
                        <a class="text-brand underline" href="{{ $isStaffInvite ? url('/admin/login') : \App\Support\SiteUrl::to('/log-in/') }}">sign in</a>
                        first, then open this link again.
                    </div>
                @else
                    <form method="post" action="{{ route('invitation.accept', $token) }}" class="space-y-6" novalidate>
                        @csrf
                        <div>
                            <label class="form-label" for="name">Full Name <span class="req">*</span></label>
                            <input id="name" name="name" value="{{ old('name', $invitation->name) }}" required autocomplete="name" class="form-input h-[52px]">
                            @error('name')<p class="form-error">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="form-label" for="password">Choose a Password <span class="req">*</span></label>
                            <input id="password" name="password" type="password" required autocomplete="new-password" class="form-input h-[52px]">
                            <p class="form-help">At least 12 characters, with letters and numbers.</p>
                            @error('password')<p class="form-error">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="form-label" for="password_confirmation">Confirm Password <span class="req">*</span></label>
                            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="form-input h-[52px]">
                        </div>
                        @if ($isStaffInvite)
                            <p class="form-help">Staff accounts must set up 2-step verification (an authenticator app or emailed codes) the first time they sign in.</p>
                        @endif
                        <button type="submit" class="btn">Create Account</button>
                    </form>
                @endif
            </div>
        </div>
    </section>
@endsection
