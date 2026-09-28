@extends('portal.layout', ['title' => 'Overview'])

@section('portal')
    @php($tz = config('app.firm_timezone'))
    @php($pending = collect($attention)->sum(fn ($items) => $items->count()))
    <p class="text-lg text-ink">Welcome, {{ $user->name }}.</p>

    @if ($pending)
        <section class="mt-8 rounded-md border border-brand/40 bg-tint p-6" aria-labelledby="attention-heading">
            <h2 id="attention-heading" class="heading-3">Needs Your Attention</h2>
            <ul class="mt-4 space-y-3">
                @foreach ($attention['engagements'] as $engagement)
                    <li><a class="font-medium text-brand hover:underline" href="{{ route('portal.engagements.show', $engagement) }}">Review and sign engagement terms: {{ $engagement->title }}</a> <span class="text-sm">({{ $engagement->reference }})</span></li>
                @endforeach
                @foreach ($attention['quotations'] as $quotation)
                    <li><a class="font-medium text-brand hover:underline" href="{{ route('portal.quotations.show', $quotation) }}">Review quotation {{ $quotation->reference }}</a></li>
                @endforeach
                @foreach ($attention['drafts'] as $document)
                    <li><a class="font-medium text-brand hover:underline" href="{{ route('portal.matters.show', $document->matter_id) }}#document-{{ $document->id }}">Review draft: {{ $document->title }}</a> <span class="text-sm">({{ $document->matter->reference }})</span></li>
                @endforeach
                @foreach ($attention['requests'] as $docRequest)
                    <li>
                        <a class="font-medium text-brand hover:underline" href="{{ route('portal.matters.show', $docRequest->matter_id) }}#requests">Please upload: {{ $docRequest->title }}</a>
                        <span class="text-sm">({{ $docRequest->matter->reference }}@if ($docRequest->due_on), by {{ $docRequest->due_on->format('j M Y') }}@endif)</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="mt-10" aria-labelledby="matters-heading">
        <h2 id="matters-heading" class="heading-3">Your Matters</h2>
        @if ($matters->isEmpty())
            <p class="mt-2 max-w-[760px]">Your matters, documents and updates will appear here once the firm opens a matter for you.</p>
        @else
            <ul class="mt-4 grid gap-4 md:grid-cols-2">
                @foreach ($matters as $matter)
                    <li>
                        <a href="{{ route('portal.matters.show', $matter) }}" class="block h-full rounded-md border border-line p-5 hover:border-brand">
                            <span class="block font-medium text-ink">{{ $matter->title }}</span>
                            <span class="mt-1 block text-sm text-body/80">{{ $matter->reference }} · {{ $matter->status->label() }}@unless ($matter->isClosed()) · {{ $matter->stageLabel() }}@endunless</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

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
        <a class="btn mt-4" href="{{ $enquiryUrl }}">Start an Enquiry</a>
    </div>
@endsection
