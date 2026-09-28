@extends('layouts.public', [
    'title' => 'Join Our Legal Team',
    'description' => 'Apply to work with Maestro Touch Legal as a lawyer, affiliate firm or member of our support team.',
    'overlayHeader' => true,
])

@section('content')
    @include('pages.partials.banner', ['heading' => 'Join Our *Legal Team*'])

    <section class="site-container py-16 md:py-20">
        <div class="max-w-[860px]">
            {{-- Draft introduction for owner approval (docs/content-gaps.md). --}}
            <p>Tell us about your experience and the work you would like to do with us. We review every application and will reply by email. Fields marked <span class="req">*</span> are required.</p>

            <div class="mt-8">
                @include('partials.form-status', ['summary' => true])

                <form method="post" action="{{ route('careers.store') }}" enctype="multipart/form-data" class="space-y-6" novalidate>
                    @csrf
                    <div class="hidden" aria-hidden="true">
                        <label for="company_website">Leave this empty</label>
                        <input id="company_website" name="company_website" type="text" tabindex="-1" autocomplete="off">
                    </div>

                    <fieldset class="space-y-6">
                        <legend class="heading-3 mb-4">About You</legend>
                        <div>
                            <label class="form-label" for="full_name">Full Name <span class="req">*</span></label>
                            <input id="full_name" name="full_name" value="{{ old('full_name') }}" required autocomplete="name" class="form-input">
                            @error('full_name')<p class="form-error">{{ $message }}</p>@enderror
                        </div>
                        <div class="grid gap-6 md:grid-cols-2">
                            <div>
                                <label class="form-label" for="email">Email Address <span class="req">*</span></label>
                                <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email" class="form-input">
                                @error('email')<p class="form-error">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="form-label" for="phone">Phone Number <span class="req">*</span></label>
                                <input id="phone" name="phone" type="tel" value="{{ old('phone') }}" required autocomplete="tel" class="form-input">
                                @error('phone')<p class="form-error">{{ $message }}</p>@enderror
                            </div>
                        </div>
                        <div>
                            <label class="form-label" for="location">Location (City, Country) <span class="req">*</span></label>
                            <input id="location" name="location" value="{{ old('location') }}" required class="form-input">
                            @error('location')<p class="form-error">{{ $message }}</p>@enderror
                        </div>
                    </fieldset>

                    <fieldset class="space-y-6 pt-4">
                        <legend class="heading-3 mb-4">Your Experience</legend>
                        <div class="grid gap-6 md:grid-cols-2">
                            <div>
                                <label class="form-label" for="professional_category">I am applying as <span class="req">*</span></label>
                                <select id="professional_category" name="professional_category" required class="form-input">
                                    <option value="">Select…</option>
                                    @foreach ($categories as $value => $label)
                                        <option value="{{ $value }}" @selected(old('professional_category') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('professional_category')<p class="form-error">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="form-label" for="years_experience">Years of Experience <span class="req">*</span></label>
                                <input id="years_experience" name="years_experience" type="number" min="0" max="60" value="{{ old('years_experience') }}" required class="form-input">
                                @error('years_experience')<p class="form-error">{{ $message }}</p>@enderror
                            </div>
                        </div>

                        <div>
                            <p class="form-label" id="areas-label">Practice Areas <span class="req">*</span></p>
                            <div class="grid gap-2 sm:grid-cols-2" role="group" aria-labelledby="areas-label">
                                @foreach ($areas as $value => $label)
                                    <label class="flex items-start gap-2">
                                        <input class="mt-1.5" type="checkbox" name="practice_areas[]" value="{{ $value }}" @checked(in_array($value, old('practice_areas', []), true))>
                                        <span>{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                            @error('practice_areas')<p class="form-error">{{ $message }}</p>@enderror
                            @error('practice_areas.*')<p class="form-error">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label class="form-label" for="qualifications">Qualifications <span class="req">*</span></label>
                            <textarea id="qualifications" name="qualifications" rows="4" required maxlength="3000" class="form-input">{{ old('qualifications') }}</textarea>
                            <p class="form-help">Degrees, call to the Bar, certifications and year obtained.</p>
                            @error('qualifications')<p class="form-error">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="form-label" for="professional_registration">Professional Registration</label>
                            <textarea id="professional_registration" name="professional_registration" rows="2" maxlength="1000" class="form-input">{{ old('professional_registration') }}</textarea>
                            <p class="form-help">For example, your Supreme Court enrolment number or other professional body membership.</p>
                            @error('professional_registration')<p class="form-error">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="form-label" for="statement">Why would you like to join us?</label>
                            <textarea id="statement" name="statement" rows="5" maxlength="5000" class="form-input">{{ old('statement') }}</textarea>
                            @error('statement')<p class="form-error">{{ $message }}</p>@enderror
                        </div>
                    </fieldset>

                    <fieldset class="space-y-6 pt-4">
                        <legend class="heading-3 mb-4">Documents</legend>
                        <div>
                            <label class="form-label" for="cv">CV / Résumé <span class="req">*</span></label>
                            <input id="cv" name="cv" type="file" required accept=".pdf,.doc,.docx" class="form-input">
                            <p class="form-help">PDF or Word, up to 10 MB.</p>
                            @error('cv')<p class="form-error">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="form-label" for="supporting">Supporting Documents</label>
                            <input id="supporting" name="supporting[]" type="file" multiple accept=".pdf,.doc,.docx" class="form-input">
                            <p class="form-help">Optional. Up to 3 files (PDF or Word, 10 MB each), such as a call-to-Bar certificate or cover letter.</p>
                            @error('supporting')<p class="form-error">{{ $message }}</p>@enderror
                            @error('supporting.*')<p class="form-error">{{ $message }}</p>@enderror
                        </div>
                    </fieldset>

                    <div>
                        <label class="flex items-start gap-2">
                            <input class="mt-1.5" type="checkbox" name="consent" value="1" @checked(old('consent'))>
                            <span>I agree to Maestro Touch Legal storing and processing the information and documents in this application to assess it, as described in the <a class="text-brand hover:underline" href="{{ \App\Support\SiteUrl::to('/privacy-policy/') }}" target="_blank" rel="noopener">privacy policy</a>. <span class="req">*</span></span>
                        </label>
                        @error('consent')<p class="form-error">{{ $message }}</p>@enderror
                    </div>

                    <button type="submit" class="btn">Submit Application</button>
                </form>
            </div>
        </div>
    </section>
@endsection
