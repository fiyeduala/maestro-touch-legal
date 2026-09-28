@extends('layouts.public', ['title' => 'Application Received', 'noindex' => true, 'overlayHeader' => true])

@section('content')
    @include('pages.partials.banner', ['heading' => 'Application *Received*'])

    <section class="site-container py-16 md:py-20">
        <div class="max-w-[760px] space-y-4">
            <p>Thank you for applying to join Maestro Touch Legal.</p>
            @if ($reference)
                <p>Your reference is <strong class="text-ink">{{ $reference }}</strong>.</p>
            @endif
            <p>We have emailed you a private link where you can check the progress of your application. If the email has not arrived within a few minutes, please check your spam folder.</p>
            <p><a class="btn mt-4" href="{{ url('/') }}">Back to Home</a></p>
        </div>
    </section>
@endsection
