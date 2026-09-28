@php
    use App\Domain\Recruitment\ApplicationStatus;
    $tz = config('app.firm_timezone');
@endphp
@extends('layouts.public', ['title' => 'Your Application', 'noindex' => true])

@section('content')
    <section class="site-container pt-6 pb-24">
        <h1 class="text-[32px] leading-tight font-semibold text-ink md:text-[36px]">Your Application</h1>

        <div class="mt-6 max-w-[860px]">
            @include('partials.form-status')
            @error('withdraw')<div class="alert alert-error mb-6" role="alert">{{ $message }}</div>@enderror

            <dl class="grid gap-x-8 gap-y-3 rounded-md border border-line p-6 sm:grid-cols-[max-content_1fr]">
                <dt class="font-medium text-ink">Reference</dt>
                <dd>{{ $application->reference }}</dd>
                <dt class="font-medium text-ink">Name</dt>
                <dd>{{ $application->full_name }}</dd>
                <dt class="font-medium text-ink">Submitted</dt>
                <dd>{{ $application->created_at->timezone($tz)->format('j F Y') }}</dd>
                <dt class="font-medium text-ink">Status</dt>
                <dd><strong class="text-brand">{{ $application->status->label() }}</strong></dd>
            </dl>

            @if ($application->applicantVisibleEvents->isNotEmpty())
                <h2 class="heading-3 mt-10">Updates</h2>
                <ol class="mt-4 space-y-4">
                    @foreach ($application->applicantVisibleEvents as $event)
                        <li class="rounded-md bg-tint px-5 py-4">
                            <p class="text-sm text-body/80">{{ $event->created_at->timezone($tz)->format('j F Y, g:i A') }}</p>
                            @if ($event->to_status)
                                <p class="font-medium text-ink">{{ ApplicationStatus::tryFrom($event->to_status)?->label() ?? $event->to_status }}</p>
                            @endif
                            @if ($event->body)
                                <p class="mt-1 whitespace-pre-line">{{ $event->body }}</p>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @endif

            @if ($application->status === ApplicationStatus::MoreInfoRequested)
                <h2 class="heading-3 mt-10">Send the Information Requested</h2>
                <form method="post" action="{{ route('careers.application.respond', $token) }}" enctype="multipart/form-data" class="mt-4 space-y-6" novalidate>
                    @csrf
                    <div>
                        <label class="form-label" for="message">Your Reply <span class="req">*</span></label>
                        <textarea id="message" name="message" rows="5" required maxlength="5000" class="form-input">{{ old('message') }}</textarea>
                        @error('message')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="form-label" for="files">Attachments</label>
                        <input id="files" name="files[]" type="file" multiple accept=".pdf,.doc,.docx" class="form-input">
                        <p class="form-help">Optional. Up to 3 files (PDF or Word, 10 MB each).</p>
                        @error('files')<p class="form-error">{{ $message }}</p>@enderror
                        @error('files.*')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                    <button type="submit" class="btn">Send Reply</button>
                </form>
            @endif

            @if ($application->status->canTransitionTo(ApplicationStatus::Withdrawn))
                <form method="post" action="{{ route('careers.application.withdraw', $token) }}" class="mt-12 border-t border-line pt-6"
                      onsubmit="return confirm('Withdraw your application? This cannot be undone.');">
                    @csrf
                    <p class="text-sm">No longer interested? You can withdraw your application at any time before a decision is made.</p>
                    <button type="submit" class="btn-outline mt-3">Withdraw Application</button>
                </form>
            @endif

            <p class="mt-10 text-sm text-body/80">Keep this link private. Anyone with it can see and update your application.</p>
        </div>
    </section>
@endsection
