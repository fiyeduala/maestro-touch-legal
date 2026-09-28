@extends('portal.layout', ['title' => 'Overview'])

@section('portal')
    <p class="text-lg text-ink">Welcome, {{ $user->name }}.</p>
    <p class="mt-2 max-w-[760px]">This is your private area for working with Maestro Touch Legal. Your matters, documents, messages and invoices will appear here once the firm opens a matter for you.</p>

    @if ($clients->isNotEmpty())
        <h2 class="heading-3 mt-10">Your Client Records</h2>
        <ul class="mt-4 grid gap-4 md:grid-cols-2">
            @foreach ($clients as $client)
                <li class="rounded-md border border-line p-5">
                    <p class="font-medium text-ink">{{ $client->display_name }}</p>
                    <p class="text-sm text-body/80">Reference {{ $client->reference }} · {{ $client->pivot->relationship === 'owner' ? 'Account holder' : 'Authorised contact' }}</p>
                </li>
            @endforeach
        </ul>
    @endif

    <div class="mt-10 rounded-md bg-tint p-6">
        <h2 class="heading-3">Need Legal Help?</h2>
        <p class="mt-2">Tell us what you need and our team will get back to you.</p>
        <a class="btn mt-4" href="{{ \App\Support\SiteUrl::to('/contact/') }}">Contact Us</a>
    </div>
@endsection
