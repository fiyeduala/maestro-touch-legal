@extends('portal.layout', ['title' => 'Appointments'])

@section('portal')
    @php
    $tz = config('app.firm_timezone');
    $slotOptions = function (array $slots, ?string $selected = null) use ($tz) {
        $html = '';
        foreach ($slots as $date => $times) {
            $html .= '<optgroup label="'.e(\Illuminate\Support\Carbon::parse($date, $tz)->format('l j F')).'">';
            foreach ($times as $time) {
                $value = $time->toIso8601String();
                $html .= '<option value="'.e($value).'"'.($selected === $value ? ' selected' : '').'>'.e($time->copy()->timezone($tz)->format('D j M, g:i a')).'</option>';
            }
            $html .= '</optgroup>';
        }

        return $html;
    };
    @endphp

    @if ($meetings->isNotEmpty())
        <section aria-labelledby="meetings-heading" class="mb-10">
            <h2 id="meetings-heading" class="heading-3">Video Meetings</h2>
            <p class="mt-1 text-sm text-body/80">Meetings the firm has invited you to. Each call opens {{ (int) config('video.join_early_minutes', 15) }} minutes before the start, is private to the people invited and is not recorded.</p>
            <ul class="mt-4 space-y-4">
                @foreach ($meetings as $meeting)
                    <li class="rounded-md border border-line p-5">
                        <p class="font-medium text-ink">Video meeting · {{ $meeting->starts_at->timezone($tz)->format('l j F Y, g:i a') }} WAT</p>
                        <p class="mt-1 text-sm text-body/80">{{ $meeting->reference }} · {{ $meeting->starts_at->diffInMinutes($meeting->ends_at) }} minutes · with {{ $meeting->organiser->name }}@if ($meeting->matter) · {{ $meeting->matter->reference }}@endif</p>
                        <p class="mt-3"><a class="btn" href="{{ route('meet.meeting', $meeting) }}" target="_blank" rel="noopener">Join the video meeting</a></p>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <section aria-labelledby="upcoming-heading">
        <h2 id="upcoming-heading" class="heading-3">Upcoming</h2>
        @if ($upcoming->isEmpty())
            <p class="mt-2">You have no upcoming consultations.</p>
        @else
            <ul class="mt-4 space-y-4">
                @foreach ($upcoming as $booking)
                    <li class="rounded-md border border-line p-5">
                        <p class="font-medium text-ink">{{ $booking->type->name }} · {{ $booking->starts_at->timezone($tz)->format('l j F Y, g:i a') }} WAT</p>
                        <p class="mt-1 text-sm text-body/80">{{ $booking->reference }} · {{ $booking->status === 'requested' ? 'Waiting for the firm to confirm' : 'Confirmed' }}@if ($booking->matter) · {{ $booking->matter->reference }}@endif</p>
                        @if ($booking->status === 'confirmed' && $booking->video)
                            <p class="mt-2"><a class="btn" href="{{ route('meet.consultation', $booking) }}" target="_blank" rel="noopener">Join the video call</a></p>
                            <p class="mt-1 text-sm text-body/80">A video call on this website. It opens {{ (int) config('video.join_early_minutes', 15) }} minutes before the start and is not recorded.</p>
                        @elseif ($booking->status === 'confirmed' && $booking->meeting_url)
                            <p class="mt-2"><a class="text-brand underline" href="{{ $booking->meeting_url }}" target="_blank" rel="noopener noreferrer">Join the consultation</a></p>
                        @endif
                        @if ($booking->client_agenda)
                            <p class="mt-2 text-sm"><span class="font-medium text-ink">What you want to discuss:</span> <span class="whitespace-pre-line">{{ $booking->client_agenda }}</span></p>
                        @endif

                        @can('actAsClient', $booking)
                            @if ($booking->starts_at->isAfter(now()->addHours($cutoffHours)))
                                <details class="mt-3">
                                    <summary class="cursor-pointer text-sm text-brand">Change or cancel</summary>
                                    <form method="post" action="{{ route('portal.appointments.reschedule', $booking) }}" class="mt-3 flex flex-wrap items-end gap-3">
                                        @csrf
                                        <div>
                                            <label class="form-label" for="slot-{{ $booking->id }}">New time</label>
                                            <select id="slot-{{ $booking->id }}" name="slot" class="form-input" required>
                                                <option value="">Choose a time</option>
                                                {!! $slotOptions(app(\App\Domain\Consultations\Consultations::class)->slots($booking->type, null, $booking->id)) !!}
                                            </select>
                                        </div>
                                        <button type="submit" class="btn btn-outline">Move</button>
                                    </form>
                                    <form method="post" action="{{ route('portal.appointments.cancel', $booking) }}" class="mt-3 flex flex-wrap items-end gap-3"
                                          onsubmit="return confirm('Cancel this consultation?')">
                                        @csrf
                                        <div class="grow">
                                            <label class="form-label" for="reason-{{ $booking->id }}">Reason (optional)</label>
                                            <input id="reason-{{ $booking->id }}" name="reason" maxlength="2000" class="form-input">
                                        </div>
                                        <button type="submit" class="btn btn-outline">Cancel Consultation</button>
                                    </form>
                                    @error('reschedule_'.$booking->id)<p class="form-error">{{ $message }}</p>@enderror
                                </details>
                            @else
                                <p class="mt-2 text-sm text-body/80">To change this consultation now, please message or call the firm.</p>
                            @endif
                        @endcan
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    @if ($canBook && $clients->isNotEmpty() && $type)
        <section class="mt-10" aria-labelledby="book-heading">
            <h2 id="book-heading" class="heading-3">Request a Consultation</h2>
            <p class="mt-1 max-w-[760px] text-sm text-body/80">Times are shown in West Africa Time (WAT). The firm will confirm your request by email. A consultation does not by itself mean the firm has agreed to act for you.</p>

            @if ($types->count() > 1)
                <form method="get" action="{{ route('portal.appointments') }}" class="mt-4 flex flex-wrap items-end gap-3">
                    <div>
                        <label class="form-label" for="type">Type of consultation</label>
                        <select id="type" name="type" class="form-input" onchange="this.form.submit()">
                            @foreach ($types as $option)
                                <option value="{{ $option->id }}" @selected($option->id === $type->id)>{{ $option->name }} ({{ $option->duration_minutes }} min, {{ $option->priceLabel() }})</option>
                            @endforeach
                        </select>
                    </div>
                    <noscript><button type="submit" class="btn btn-outline">Show Times</button></noscript>
                </form>
            @endif

            <form method="post" action="{{ route('portal.appointments.store') }}" class="mt-4 max-w-[760px] space-y-4 rounded-md bg-tint p-5">
                @csrf
                <input type="hidden" name="type_id" value="{{ $type->id }}">
                <p class="text-sm"><span class="font-medium text-ink">{{ $type->name }}</span> · {{ $type->duration_minutes }} minutes · {{ $type->priceLabel() }}@if ($type->description)<br>{{ $type->description }}@endif</p>
                @if ($clients->count() > 1)
                    <div>
                        <label class="form-label" for="client_id">Booking for <span class="req">*</span></label>
                        <select id="client_id" name="client_id" class="form-input" required>
                            @foreach ($clients as $client)
                                <option value="{{ $client->id }}" @selected((string) old('client_id') === (string) $client->id)>{{ $client->display_name }}</option>
                            @endforeach
                        </select>
                    </div>
                @else
                    <input type="hidden" name="client_id" value="{{ $clients->first()->id }}">
                @endif
                @if ($matters->isNotEmpty())
                    <div>
                        <label class="form-label" for="matter_id">About a matter (optional)</label>
                        <select id="matter_id" name="matter_id" class="form-input">
                            <option value="">Not about a particular matter</option>
                            @foreach ($matters as $matter)
                                <option value="{{ $matter->id }}" @selected((string) old('matter_id') === (string) $matter->id)>{{ $matter->reference }} · {{ $matter->title }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div>
                    <label class="form-label" for="slot">Time <span class="req">*</span></label>
                    @if ($slots)
                        <select id="slot" name="slot" class="form-input" required>
                            <option value="">Choose a time</option>
                            {!! $slotOptions($slots, old('slot')) !!}
                        </select>
                    @else
                        <p class="text-sm">There are no free times in the coming weeks. Please message the firm and we will find a time with you.</p>
                    @endif
                    @error('slot')<p class="form-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="form-label" for="agenda">What would you like to discuss? (optional)</label>
                    <textarea id="agenda" name="agenda" rows="3" maxlength="2000" class="form-input">{{ old('agenda') }}</textarea>
                </div>
                @if ($slots)<button type="submit" class="btn">Request Consultation</button>@endif
            </form>
        </section>
    @endif

    @if ($past->isNotEmpty())
        <section class="mt-10" aria-labelledby="past-heading">
            <h2 id="past-heading" class="heading-3">Past and Cancelled</h2>
            <ul class="mt-4 divide-y divide-line border-y border-line text-sm">
                @foreach ($past as $booking)
                    <li class="py-3">{{ $booking->type->name }} · {{ $booking->starts_at->timezone($tz)->format('j M Y, g:i a') }} WAT · {{ $booking->status === 'requested' || $booking->status === 'confirmed' ? 'Past' : $booking->statusLabel() }}</li>
                @endforeach
            </ul>
        </section>
    @endif
@endsection
