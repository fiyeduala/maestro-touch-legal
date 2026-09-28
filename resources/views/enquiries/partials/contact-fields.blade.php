<fieldset class="space-y-6">
    <legend class="heading-3 mb-4">Your Details</legend>
    <div class="grid gap-6 md:grid-cols-2">
        <div>
            <label class="form-label" for="contact_name">Full Name <span class="req">*</span></label>
            <input id="contact_name" name="contact_name" value="{{ old('contact_name', auth()->user()?->isClient() ? auth()->user()->name : '') }}" required maxlength="160" autocomplete="name" class="form-input">
            @error('contact_name')<p class="form-error">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="form-label" for="contact_email">Email Address <span class="req">*</span></label>
            <input id="contact_email" name="contact_email" type="email" value="{{ old('contact_email', auth()->user()?->isClient() ? auth()->user()->email : '') }}" required maxlength="190" autocomplete="email" class="form-input">
            @error('contact_email')<p class="form-error">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="form-label" for="contact_phone">Phone Number</label>
            <input id="contact_phone" name="contact_phone" type="tel" value="{{ old('contact_phone') }}" maxlength="40" autocomplete="tel" class="form-input">
            @error('contact_phone')<p class="form-error">{{ $message }}</p>@enderror
        </div>
        @if ($organisation ?? false)
            <div>
                <label class="form-label" for="country">Country</label>
                <input id="country" name="country" value="{{ old('country') }}" maxlength="80" autocomplete="country-name" class="form-input">
                @error('country')<p class="form-error">{{ $message }}</p>@enderror
            </div>
            <div class="md:col-span-2">
                <label class="form-label" for="organisation_name">Company or Organisation (if applicable)</label>
                <input id="organisation_name" name="organisation_name" value="{{ old('organisation_name') }}" maxlength="190" autocomplete="organization" class="form-input">
                @error('organisation_name')<p class="form-error">{{ $message }}</p>@enderror
            </div>
        @endif
    </div>
</fieldset>
