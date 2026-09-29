{{-- One client-conversation message as the client sees it. Only ever given "client" audience messages. --}}
@php($mine = $message->sender_id === $user->id)
@php($tz = config('app.firm_timezone'))
<li id="message-{{ $message->id }}" data-message-id="{{ $message->id }}" @class(['flex', 'justify-end' => $message->sender_is_client])>
    <div @class(['max-w-[85%] rounded-lg px-4 py-3 md:max-w-[70%]',
        'bg-brand/10 text-ink' => $message->sender_is_client,
        'border border-line bg-white' => ! $message->sender_is_client])>
        <p class="text-xs text-body/80">
            <span class="font-medium text-ink">{{ $mine ? 'You' : $message->senderLabel() }}</span>
            @if ($message->kind !== 'message') · {{ $message->kindLabel() }}@endif
            · <time datetime="{{ $message->created_at->toIso8601String() }}">{{ $message->created_at->timezone($tz)->format('j M, H:i') }} WAT</time>
        </p>
        @if ($message->amends_message_id)
            <p class="mt-1 text-xs text-body/80"><a class="underline" href="#message-{{ $message->amends_message_id }}">Correction to an earlier message</a></p>
        @endif
        <p class="mt-1 whitespace-pre-line break-words">{{ $message->body }}</p>
        @if ($message->document && $message->document->clientVersion())
            <p class="mt-2 text-sm"><a class="text-brand underline" href="{{ route('portal.document-file', $message->document->clientVersion()) }}">{{ $message->document->title }}</a></p>
        @elseif ($message->document_id)
            <p class="mt-2 text-sm text-body/80">A file was attached.</p>
        @endif
        @if ($mine)
            <p class="mt-1 text-right text-xs text-body/70" data-receipt>{{ isset($readReceipts[$message->id]) ? 'Seen by the firm' : 'Sent' }}</p>
        @endif
    </div>
</li>
