{{-- Cloudflare Turnstile, shown only once both keys are in .env (D48); and any form-level spam-check message. --}}
@if ($turnstileKey = \App\Support\FormGuard::turnstileSiteKey())
    <div class="cf-turnstile" data-sitekey="{{ $turnstileKey }}"></div>
    @once
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endonce
@endif
@error('form')<p class="form-error" role="alert">{{ $message }}</p>@enderror
