@extends('portal.layout', ['title' => 'Messages'])

@section('portal')
    @php($tz = config('app.firm_timezone'))
    <p class="max-w-[760px]">Each matter has its own private conversation with the firm. Choose a matter to read and reply.</p>

    @if ($matters->isEmpty())
        <p class="mt-6">You will be able to message the firm here once a matter is open for you. Until then, you can <a class="text-brand underline" href="{{ $enquiryUrl }}">start a new request</a>.</p>
    @else
        <ul class="mt-6 divide-y divide-line border-y border-line">
            @foreach ($matters as $matter)
                @php($count = $unread[$matter->id] ?? 0)
                <li>
                    <a href="{{ route('portal.matters.show', $matter) }}#messages" class="flex flex-wrap items-center justify-between gap-2 py-4 hover:text-brand">
                        <span>
                            <span @class(['block text-ink', 'font-semibold' => $count, 'font-medium' => ! $count])>{{ $matter->title }}</span>
                            <span class="block text-sm text-body/80">{{ $matter->reference }} · {{ $matter->status->label() }}
                                @if (isset($latest[$matter->id])) · last message {{ \Illuminate\Support\Carbon::parse($latest[$matter->id]->last_at, 'UTC')->timezone($tz)->format('j M Y, H:i') }} WAT @else · no messages yet @endif
                            </span>
                        </span>
                        @if ($count)
                            <span class="rounded-full bg-brand px-3 py-1 text-sm text-white">{{ $count }} unread</span>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>
    @endif

    <p class="mt-8 max-w-[760px] text-sm text-body/80">If there are new messages in a day, we email you a short recap after 6:00 pm (West Africa Time). You can choose what the recap includes on your <a class="text-brand underline" href="{{ route('portal.profile') }}#recaps">Profile</a> page.</p>
@endsection
