<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Conversation recap</title>
</head>
<body style="margin:0;padding:0;background:#f4f6f9;font-family:Poppins,Arial,Helvetica,sans-serif;color:#364151;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f9;">
    <tr><td align="center" style="padding:24px 12px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border-radius:6px;">
            <tr><td style="padding:28px 28px 8px;">
                <p style="margin:0;font-size:13px;color:#007BF8;font-weight:600;">Maestro Touch Legal</p>
                <h1 style="margin:8px 0 0;font-size:20px;color:#0F172A;">{{ $firm ? 'Client conversations recap' : 'Your conversation recap' }}</h1>
                <p style="margin:8px 0 0;font-size:14px;">
                    Messages from {{ $digest->window_start->timezone($tz)->format('j M Y, g:i a') }}
                    to {{ $digest->window_end->timezone($tz)->format('j M Y, g:i a') }} (West Africa Time, WAT).
                </p>
            </td></tr>

            @foreach ($sections as $section)
                @php($matter = $section['matter'])
                <tr><td style="padding:20px 28px 0;">
                    <h2 style="margin:0;font-size:16px;color:#0F172A;border-top:1px solid #e5e7eb;padding-top:16px;">
                        @if ($firm){{ $section['client']?->display_name }}: @endif{{ $matter->title }}
                        <span style="font-weight:400;font-size:13px;color:#6b7280;">({{ $matter->reference }})</span>
                    </h2>

                    @if ($section['summary'])
                        @php($fromClient = $section['messages']->where('sender_is_client', true)->count())
                        @php($files = $section['messages']->whereNotNull('document_id')->count())
                        <p style="margin:10px 0 0;font-size:14px;">
                            {{ $section['messages']->count() }} new {{ Str::plural('message', $section['messages']->count()) }}:
                            {{ $fromClient }} from {{ $firm ? 'the client' : 'you' }}, {{ $section['messages']->count() - $fromClient }} from the firm.
                            @if ($files) {{ $files }} {{ Str::plural('file', $files) }} shared. @endif
                        </p>
                        <p style="margin:6px 0 0;font-size:13px;color:#6b7280;">For privacy, this matter's messages are summarised. Sign in to read them.</p>
                    @else
                        @foreach ($section['messages'] as $message)
                            <div style="margin:12px 0 0;padding:10px 12px;border-left:3px solid {{ $message->sender_is_client ? '#9ca3af' : '#007BF8' }};background:#f9fafb;">
                                <p style="margin:0;font-size:13px;color:#0F172A;font-weight:600;">
                                    {{ $message->senderLabel() }}
                                    <span style="font-weight:400;color:#6b7280;">· {{ $message->created_at->timezone($tz)->format('j M, g:i a') }}</span>
                                    @if ($message->kindLabel())<span style="font-weight:400;color:#6b7280;"> · {{ $message->kindLabel() }}</span>@endif
                                </p>
                                <p style="margin:6px 0 0;font-size:14px;white-space:pre-line;">{{ $message->body }}</p>
                                @if ($message->document_id)
                                    <p style="margin:6px 0 0;font-size:13px;color:#6b7280;">A file was shared. Sign in to view it.</p>
                                @endif
                            </div>
                        @endforeach
                    @endif

                    <p style="margin:12px 0 0;font-size:14px;">
                        <a href="{{ $firm ? url('/admin/matters/'.$matter->id.'/conversation') : url('/portal/matters/'.$matter->id).'#messages' }}" style="color:#007BF8;">Open this conversation</a>
                    </p>
                </td></tr>
            @endforeach

            <tr><td style="padding:24px 28px 28px;">
                <p style="margin:0;font-size:12px;color:#6b7280;border-top:1px solid #e5e7eb;padding-top:16px;">
                    @if ($firm)
                        Sent to you as an authorised firm recipient. Internal notes are never included in these emails.
                    @else
                        You receive this recap because there were new messages in your client conversation today.
                        Files are never attached; sign in to your portal to view them. You can switch to summary-only
                        recaps (counts, without message text) in your portal profile.
                    @endif
                </p>
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
