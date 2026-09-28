@extends('layouts.public', ['title' => 'Enquiry Received', 'noindex' => true, 'overlayHeader' => true])

@section('content')
    @include('pages.partials.banner', ['heading' => 'Enquiry *Received*'])

    {{-- Draft wording for owner approval (docs/content-gaps.md §5). --}}
    <section class="site-container py-16 md:py-20">
        <div class="max-w-[760px] space-y-4">
            <p>Thank you for contacting Maestro Touch Legal.</p>
            @if ($reference)
                <p>Your reference is <strong class="text-ink">{{ $reference }}</strong>. Please quote it if you contact us about this enquiry.</p>
            @endif
            <p>We have sent a confirmation to your email address. A member of our team will review your enquiry and reply. If the email has not arrived within a few minutes, please check your spam folder.</p>
            <p><a class="btn mt-4" href="{{ url('/') }}">Back to Home</a></p>
        </div>
    </section>
@endsection
