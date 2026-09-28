{{-- Draft wording for owner approval (docs/content-gaps.md §5). --}}
<div>
    <label class="form-label" for="summary">How can we help? <span class="req">*</span></label>
    <textarea id="summary" name="summary" rows="6" required maxlength="5000" class="form-input">{{ old('summary') }}</textarea>
    <p class="mt-1 text-sm text-body/80">A brief outline is enough. Please include the names of any other people or companies involved so we can check we are free to act.</p>
    @error('summary')<p class="form-error">{{ $message }}</p>@enderror
</div>
@if ($preferredTimes ?? false)
    <div>
        <label class="form-label" for="preferred_times">Best times to reach you</label>
        <input id="preferred_times" name="preferred_times" value="{{ old('preferred_times') }}" maxlength="500" class="form-input">
        @error('preferred_times')<p class="form-error">{{ $message }}</p>@enderror
    </div>
@endif
<div class="rounded-lg bg-tint p-4 text-sm">
    <p>Sending this enquiry does not make Maestro Touch Legal your lawyers. We act for you only after we have confirmed we can take the matter on and you have agreed our terms of engagement.</p>
</div>
<div>
    <label class="flex items-start gap-2">
        <input class="mt-1.5" type="checkbox" name="consent" value="1" @checked(old('consent'))>
        <span>I agree to Maestro Touch Legal using these details to review and respond to my enquiry. <span class="req">*</span></span>
    </label>
    @error('consent')<p class="form-error">{{ $message }}</p>@enderror
</div>
