@extends('portal.layout', ['title' => $matter->title])

@section('portal')
    @php($tz = config('app.firm_timezone'))
    @php($stages = $matter->stageOptions())
    @php($stageIndex = array_search($matter->stage, array_keys($stages), true))

    <p class="text-sm text-body/80"><a class="text-brand hover:underline" href="{{ route('portal.home') }}">← Overview</a> · {{ $matter->reference }}@if ($matter->service) · {{ $matter->service->name }}@endif · {{ $matter->status->label() }}</p>

    @if ($matter->client_summary)
        <p class="mt-4 max-w-[860px] whitespace-pre-line">{{ $matter->client_summary }}</p>
    @endif

    {{-- Progress --}}
    <section class="mt-8" aria-labelledby="progress-heading">
        <h2 id="progress-heading" class="heading-3">Progress</h2>
        <ol class="mt-4 flex flex-wrap gap-2 text-sm">
            @foreach ($stages as $key => $label)
                @php($done = $stageIndex !== false && $loop->index < $stageIndex)
                @php($current = $key === $matter->stage)
                <li @class(['rounded-full px-3 py-1 border', 'bg-brand text-white border-brand' => $current, 'bg-tint border-tint text-ink' => $done, 'border-line text-body/70' => ! $current && ! $done])
                    @if ($current) aria-current="step" @endif>
                    {{ $label }}
                </li>
            @endforeach
        </ol>
        @if ($matter->next_action && $matter->next_action_client_visible)
            <p class="mt-4"><span class="font-medium text-ink">Next step:</span> {{ $matter->next_action }}@if ($matter->next_action_due_at) (by {{ $matter->next_action_due_at->timezone($tz)->format('j M Y') }})@endif</p>
        @endif
        @if ($deadlines->isNotEmpty())
            <ul class="mt-4 space-y-1 text-sm">
                @foreach ($deadlines as $deadline)
                    <li><span class="font-medium text-ink">{{ $deadline->title }}</span> · {{ $deadline->due_at->timezone($tz)->format('j M Y') }}</li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Conversation with the firm (client audience only) --}}
    <section id="messages" class="mt-10 scroll-mt-28" aria-labelledby="messages-heading"
             data-chat data-poll-url="{{ route('portal.matters.messages.poll', $matter) }}" data-last-id="{{ $messages->last()?->id ?? 0 }}">
        <h2 id="messages-heading" class="heading-3">Messages</h2>
        <p class="mt-1 max-w-[760px] text-sm text-body/80">A private conversation between you and the firm about this matter. We aim to reply within one working day. For anything urgent, please call us.</p>

        <div class="mt-4 max-w-[860px] rounded-md border border-line bg-tint/40 p-4">
            @if ($older)
                <p class="mb-3 text-center text-sm"><a class="text-brand underline" href="{{ route('portal.matters.show', ['matter' => $matter, 'before' => $older]) }}#messages">Show earlier messages</a></p>
            @endif
            <ol class="space-y-3" data-chat-list aria-live="polite" aria-relevant="additions">
                @foreach ($messages as $message)
                    @include('portal.partials.message', ['message' => $message, 'user' => auth()->user()])
                @endforeach
            </ol>
            @if ($messages->isEmpty())
                <p class="text-sm text-body/80" data-chat-empty>No messages yet. You can write to the firm below.</p>
            @endif
        </div>

        @if ($canAct && ! $matter->isClosed())
            <form method="post" action="{{ route('portal.matters.messages.store', $matter) }}" enctype="multipart/form-data" class="mt-4 max-w-[860px] space-y-3" data-chat-form>
                @csrf
                <div>
                    <label class="form-label" for="body">Your message</label>
                    <textarea id="body" name="body" rows="3" maxlength="{{ \App\Domain\Communication\Conversations::MAX_LENGTH }}" class="form-input">{{ old('body') }}</textarea>
                    @error('body')<p class="form-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="form-label" for="chat-file">Attach a file (optional)</label>
                    <input id="chat-file" name="file" type="file" accept="{{ $accept }}" class="form-input">
                    @error('file')<p class="form-error">{{ $message }}</p>@enderror
                </div>
                <p class="form-error hidden" data-chat-error role="alert"></p>
                <button type="submit" class="btn">Send Message</button>
            </form>
        @elseif ($matter->isClosed())
            <p class="mt-4 text-sm">This matter is closed, so new messages cannot be sent here. If you need more help, please start a new request.</p>
        @endif
    </section>

    {{-- Requests from the firm --}}
    @if ($requests->isNotEmpty() || $canAct)
        <section id="requests" class="mt-10 scroll-mt-28" aria-labelledby="requests-heading">
            <h2 id="requests-heading" class="heading-3">Send Documents</h2>
            @if ($requests->isNotEmpty())
                <ul class="mt-4 space-y-2">
                    @foreach ($requests as $docRequest)
                        <li class="rounded-md border border-line p-4">
                            <p class="font-medium text-ink">{{ $docRequest->title }}@if ($docRequest->due_on) <span class="text-sm font-normal text-body/80">· needed by {{ $docRequest->due_on->format('j M Y') }}</span>@endif</p>
                            @if ($docRequest->description)<p class="mt-1 text-sm whitespace-pre-line">{{ $docRequest->description }}</p>@endif
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($canAct && ! $matter->isClosed())
                <form method="post" action="{{ route('portal.matters.upload', $matter) }}" enctype="multipart/form-data" class="mt-6 max-w-[760px] space-y-4 rounded-md bg-tint p-5">
                    @csrf
                    @if ($requests->isNotEmpty())
                        <div>
                            <label class="form-label" for="request_id">This file is for</label>
                            <select id="request_id" name="request_id" class="form-input">
                                @foreach ($requests as $docRequest)
                                    <option value="{{ $docRequest->id }}" @selected((string) old('request_id') === (string) $docRequest->id)>{{ $docRequest->title }}</option>
                                @endforeach
                                <option value="" @selected(old('request_id') === '')>Something else</option>
                            </select>
                        </div>
                    @endif
                    <div>
                        <label class="form-label" for="title">Document name</label>
                        <input id="title" name="title" value="{{ old('title') }}" maxlength="190" class="form-input">
                    </div>
                    <div>
                        <label class="form-label" for="file">File <span class="req">*</span></label>
                        <input id="file" name="file" type="file" required accept="{{ $accept }}" class="form-input">
                        <p class="mt-1 text-sm text-body/80">PDF, Word (.docx) or image, up to {{ $maxMb }} MB. Only the firm and you can see files you upload.</p>
                        @error('file')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="form-label" for="note">Note for the firm</label>
                        <textarea id="note" name="note" rows="2" maxlength="2000" class="form-input">{{ old('note') }}</textarea>
                    </div>
                    <button type="submit" class="btn">Upload</button>
                </form>
            @endif
        </section>
    @endif

    {{-- Documents --}}
    <section class="mt-10" aria-labelledby="documents-heading">
        <h2 id="documents-heading" class="heading-3">Documents</h2>
        @if ($documents->isEmpty())
            <p class="mt-2">No documents yet.</p>
        @else
            <ul class="mt-4 divide-y divide-line border-y border-line">
                @foreach ($documents as $document)
                    @php($version = $document->clientVersion())
                    <li id="document-{{ $document->id }}" class="scroll-mt-28 py-4">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <p class="font-medium text-ink">{{ $document->title }}
                                <span class="text-sm font-normal text-body/80">
                                    @if ($document->uploaded_by_client) · uploaded by you @else · version {{ $version?->version }} shared {{ $document->released_at?->timezone($tz)->format('j M Y') }} @endif
                                </span>
                            </p>
                            @if ($version)
                                <span class="flex gap-4 text-sm">
                                    @if ($version->isSafeInline())<a class="text-brand hover:underline" target="_blank" rel="noopener" href="{{ route('portal.document-file', ['version' => $version, 'inline' => 1]) }}">View</a>@endif
                                    <a class="text-brand hover:underline" href="{{ route('portal.document-file', $version) }}">Download</a>
                                </span>
                            @endif
                        </div>

                        @if ($document->is_deliverable && $document->released_version_id)
                            @if ($document->client_decision === 'approved')
                                <p class="mt-2 text-sm text-ink">You approved this version on {{ $document->client_decision_at?->timezone($tz)->format('j M Y') }}.</p>
                            @elseif ($document->client_decision === 'changes_requested')
                                <p class="mt-2 text-sm text-ink">You asked for changes on {{ $document->client_decision_at?->timezone($tz)->format('j M Y') }}. The firm will share a revised version.</p>
                            @elseif ($canAct)
                                <form method="post" action="{{ route('portal.documents.decide', $document) }}" class="mt-3 max-w-[760px] space-y-3 rounded-md bg-tint p-4">
                                    @csrf
                                    <input type="hidden" name="version_id" value="{{ $document->released_version_id }}">
                                    <p class="text-sm">Please review this draft, then approve it or tell us what should change.</p>
                                    <div>
                                        <label class="form-label" for="comment-{{ $document->id }}">Comments (required if you want changes)</label>
                                        <textarea id="comment-{{ $document->id }}" name="comment" rows="3" maxlength="5000" class="form-input">{{ old('comment') }}</textarea>
                                    </div>
                                    @error('decision_'.$document->id)<p class="form-error">{{ $message }}</p>@enderror
                                    <div class="flex flex-wrap gap-3">
                                        <button type="submit" name="decision" value="approved" class="btn">Approve Version {{ $document->releasedVersion?->version }}</button>
                                        <button type="submit" name="decision" value="changes_requested" class="btn btn-outline">Request Changes</button>
                                    </div>
                                </form>
                            @endif
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Updates --}}
    <section class="mt-10" aria-labelledby="updates-heading">
        <h2 id="updates-heading" class="heading-3">Updates</h2>
        @if ($events->isEmpty())
            <p class="mt-2">No updates yet.</p>
        @else
            <ol class="mt-4 space-y-3">
                @foreach ($events as $event)
                    <li class="text-sm">
                        <time class="text-body/70" datetime="{{ $event->created_at->toIso8601String() }}">{{ $event->created_at->timezone($tz)->format('j M Y, H:i') }}</time>
                        <span class="ml-2 text-ink">{{ $event->summary }}</span>
                    </li>
                @endforeach
            </ol>
        @endif
    </section>
@endsection
