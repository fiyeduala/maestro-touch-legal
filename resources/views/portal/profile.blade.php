@extends('portal.layout', ['title' => 'Profile & Security'])

@section('portal')
    @php $tz = config('app.firm_timezone'); @endphp

    <div class="grid gap-12 lg:grid-cols-2">
        <section aria-labelledby="details-heading">
            <h2 id="details-heading" class="heading-3">Your Details</h2>
            <form method="post" action="{{ route('portal.profile.update') }}" class="mt-4 space-y-6" novalidate>
                @csrf
                @method('put')
                <div>
                    <label class="form-label" for="name">Full Name <span class="req">*</span></label>
                    <input id="name" name="name" value="{{ old('name', $user->name) }}" required autocomplete="name" class="form-input">
                    @error('name')<p class="form-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="form-label" for="email">Email Address</label>
                    <input id="email" type="email" value="{{ $user->email }}" disabled class="form-input opacity-70">
                    <p class="form-help">To change your email address, please contact the firm.</p>
                </div>
                <div>
                    <label class="form-label" for="phone">Phone Number</label>
                    <input id="phone" name="phone" type="tel" value="{{ old('phone', $user->phone) }}" autocomplete="tel" class="form-input">
                    @error('phone')<p class="form-error">{{ $message }}</p>@enderror
                </div>
                <button type="submit" class="btn py-3">Save Details</button>
            </form>
        </section>

        <section aria-labelledby="password-heading">
            <h2 id="password-heading" class="heading-3">Change Password</h2>
            <form method="post" action="{{ route('portal.password.update') }}" class="mt-4 space-y-6" novalidate>
                @csrf
                @method('put')
                <div>
                    <label class="form-label" for="current_password">Current Password <span class="req">*</span></label>
                    <input id="current_password" name="current_password" type="password" required autocomplete="current-password" class="form-input">
                    @error('current_password')<p class="form-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="form-label" for="password">New Password <span class="req">*</span></label>
                    <input id="password" name="password" type="password" required autocomplete="new-password" class="form-input">
                    <p class="form-help">At least 12 characters, with letters and numbers. Changing it signs out your other devices.</p>
                    @error('password')<p class="form-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="form-label" for="password_confirmation">Confirm New Password <span class="req">*</span></label>
                    <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="form-input">
                </div>
                <button type="submit" class="btn py-3">Change Password</button>
            </form>
        </section>
    </div>

    @if ($clients->isNotEmpty())
        <section id="recaps" aria-labelledby="recaps-heading" class="mt-14 scroll-mt-28">
            <h2 id="recaps-heading" class="heading-3">Daily Message Recap</h2>
            <p class="mt-2 max-w-[760px]">When there are new messages in a day, we email you a recap after 6:00 pm (West Africa Time). Files are never attached, and some sensitive matters are always summarised.</p>
            <form method="post" action="{{ route('portal.profile.recaps') }}" class="mt-4 max-w-[760px] space-y-4">
                @csrf
                @method('put')
                @foreach ($clients as $client)
                    <fieldset class="rounded-md border border-line p-4">
                        <legend class="px-1 font-medium text-ink">{{ $client->display_name }}</legend>
                        <label class="mt-1 flex items-start gap-2">
                            <input type="radio" name="recaps[{{ $client->id }}]" value="full" @checked(($client->pivot->digest_mode ?? 'full') === 'full')>
                            <span>Include the messages</span>
                        </label>
                        <label class="mt-1 flex items-start gap-2">
                            <input type="radio" name="recaps[{{ $client->id }}]" value="summary" @checked($client->pivot->digest_mode === 'summary')>
                            <span>Only tell me how many new messages there are (read them after signing in)</span>
                        </label>
                    </fieldset>
                @endforeach
                <button type="submit" class="btn py-3">Save Preference</button>
            </form>
        </section>
    @endif

    <section aria-labelledby="sessions-heading" class="mt-14">
        <h2 id="sessions-heading" class="heading-3">Where You’re Signed In</h2>
        <ul class="mt-4 divide-y divide-line rounded-md border border-line">
            @foreach ($sessions as $session)
                <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-3">
                    <div>
                        <p class="text-sm text-ink">{{ $session->agent ?: 'Unknown browser' }}</p>
                        <p class="text-xs text-body/80">{{ $session->ip ?: 'Unknown IP' }} · last active {{ $session->last_active->timezone($tz)->format('j M Y, g:i A') }}</p>
                    </div>
                    @if ($session->current)
                        <span class="rounded bg-tint px-2 py-1 text-xs font-medium text-brand">This device</span>
                    @endif
                </li>
            @endforeach
        </ul>

        @if ($sessions->where('current', false)->isNotEmpty())
            <form method="post" action="{{ route('portal.sessions.destroy') }}" class="mt-6 flex flex-wrap items-end gap-4" novalidate>
                @csrf
                @method('delete')
                <div class="w-full max-w-sm">
                    <label class="form-label" for="sessions_password">Confirm with your password</label>
                    <input id="sessions_password" name="current_password" type="password" required autocomplete="current-password" class="form-input">
                    @error('current_password', 'sessions')<p class="form-error">{{ $message }}</p>@enderror
                </div>
                <button type="submit" class="btn-outline">Sign Out Other Devices</button>
            </form>
        @endif
    </section>
@endsection
