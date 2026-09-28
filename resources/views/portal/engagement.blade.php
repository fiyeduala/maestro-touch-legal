@extends('portal.layout', ['title' => $engagement->title])

@section('portal')
    <p class="text-sm text-body/80"><a class="text-brand hover:underline" href="{{ route('portal.home') }}">← Overview</a> · {{ $engagement->reference }} · Version {{ $version?->version }} · {{ $engagement->status->label() }}</p>

    <section class="mt-6 max-w-[860px]">
        {{-- Body was sanitised by Engagements::clean when saved. --}}
        <article class="prose-mtl rounded-md border border-line p-6">{!! $version?->body !!}</article>

        @if ($canRespond && $version)
            <form method="post" action="{{ route('portal.engagements.respond', $engagement) }}" class="mt-8 space-y-4 rounded-md bg-tint p-5">
                @csrf
                <input type="hidden" name="version_id" value="{{ $version->id }}">
                <p>{{ $acceptStatement }}</p>
                <div>
                    <label class="form-label" for="signed_name">Type your full name to sign</label>
                    <input id="signed_name" name="signed_name" value="{{ old('signed_name') }}" maxlength="160" autocomplete="name" class="form-input">
                    @error('signed_name')<p class="form-error">{{ $message }}</p>@enderror
                </div>
                <label class="flex items-start gap-2">
                    <input class="mt-1.5" type="checkbox" name="confirm" value="1" @checked(old('confirm'))>
                    <span>I have read these terms and agree to them.</span>
                </label>
                @error('confirm')<p class="form-error">{{ $message }}</p>@enderror
                <div>
                    <label class="form-label" for="comment">Comment (optional)</label>
                    <textarea id="comment" name="comment" rows="2" maxlength="2000" class="form-input">{{ old('comment') }}</textarea>
                </div>
                @error('decision')<p class="form-error">{{ $message }}</p>@enderror
                <div class="flex flex-wrap gap-3">
                    <button type="submit" name="decision" value="accepted" class="btn">Sign and Accept</button>
                    <button type="submit" name="decision" value="declined" class="btn btn-outline">Decline</button>
                </div>
            </form>
        @elseif ($engagement->status === \App\Domain\Engagement\OfferStatus::Accepted)
            <p class="mt-6">You accepted these terms. The firm will confirm when your matter is open.</p>
        @endif
    </section>
@endsection
