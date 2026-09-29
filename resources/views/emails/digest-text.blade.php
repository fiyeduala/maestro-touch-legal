{!! $firm ? 'Client conversations recap' : 'Your conversation recap' !!} - Maestro Touch Legal
Messages from {!! $digest->window_start->timezone($tz)->format('j M Y, g:i a') !!} to {!! $digest->window_end->timezone($tz)->format('j M Y, g:i a') !!} (West Africa Time, WAT).
@foreach ($sections as $section)

== @if ($firm){!! $section['client']?->display_name !!}: @endif{!! $section['matter']->title !!} ({!! $section['matter']->reference !!}) ==
@if ($section['summary'])
@php($fromClient = $section['messages']->where('sender_is_client', true)->count())
{!! $section['messages']->count() !!} new {!! Str::plural('message', $section['messages']->count()) !!}: {!! $fromClient !!} from {!! $firm ? 'the client' : 'you' !!}, {!! $section['messages']->count() - $fromClient !!} from the firm. Sign in to read them.
@else
@foreach ($section['messages'] as $message)

{!! $message->senderLabel() !!}, {!! $message->created_at->timezone($tz)->format('j M, g:i a') !!}@if ($message->kindLabel()) ({!! $message->kindLabel() !!})@endif:
{!! $message->body !!}
@if ($message->document_id)
[A file was shared. Sign in to view it.]
@endif
@endforeach
@endif
Open: {!! $firm ? url('/admin/matters/'.$section['matter']->id.'/conversation') : url('/portal/matters/'.$section['matter']->id).'#messages' !!}
@endforeach

@if ($firm)
Sent to you as an authorised firm recipient. Internal notes are never included in these emails.
@else
Files are never attached; sign in to your portal to view them. You can switch to summary-only recaps in your portal profile.
@endif
