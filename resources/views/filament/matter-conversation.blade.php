<x-filament-panels::page>
    {{-- Filament's stylesheet does not include the site's utility classes, so layout-critical styles are inline. --}}
    <div wire:poll.visible.20s style="display:grid;gap:1.5rem;grid-template-columns:repeat(auto-fit,minmax(min(100%,26rem),1fr));align-items:start">
        @foreach (['client', 'internal'] as $pane)
            @php
                $internal = $pane === 'internal';
                $messages = $internal ? $internalMessages : $clientMessages;
                $hasOlder = $internal ? $internalHasOlder : $clientHasOlder;
                $bodyField = $internal ? 'internalBody' : 'clientBody';
                $fileField = $internal ? 'internalFile' : 'clientFile';
            @endphp
            <section aria-labelledby="pane-{{ $pane }}"
                     style="border-radius:.75rem;padding:1rem;{{ $internal ? 'background:#fffbeb;border:2px solid #f59e0b' : 'border:1px solid rgba(127,127,127,.3)' }}">
                <h2 id="pane-{{ $pane }}" style="font-weight:600;font-size:1.05rem;{{ $internal ? 'color:#92400e' : '' }}">
                    @if ($internal)
                        INTERNAL — never shown to the client
                    @else
                        Client conversation
                    @endif
                </h2>
                <p style="font-size:.8rem;opacity:.75;margin-top:.15rem;{{ $internal ? 'color:#78350f' : '' }}">
                    @if ($internal)
                        Staff-only notes. Not included in client emails, recaps or exports.
                    @else
                        Seen by the client's portal contacts and the matter team. Messages cannot be edited or deleted; add a correction instead.
                    @endif
                </p>

                @if ($hasOlder)
                    <p style="margin-top:.75rem;text-align:center">
                        <x-filament::link tag="button" wire:click="loadOlder('{{ $pane }}')" size="sm">Load earlier messages</x-filament::link>
                    </p>
                @endif

                <ol style="margin-top:.75rem;display:flex;flex-direction:column;gap:.6rem;max-height:32rem;overflow-y:auto" aria-live="polite">
                    @forelse ($messages as $message)
                        <li wire:key="m-{{ $message->id }}" id="staff-message-{{ $message->id }}"
                            style="border-radius:.5rem;padding:.6rem .8rem;{{ $message->sender_is_client ? 'background:rgba(0,123,248,.08);margin-right:2rem' : ($internal ? 'background:#fff;border:1px solid #fcd34d' : 'background:rgba(127,127,127,.08);margin-left:2rem') }}">
                            <p style="font-size:.75rem;opacity:.8">
                                <strong>{{ $message->senderLabel() }}</strong>
                                @if ($message->kindLabel()) · {{ $message->kindLabel() }}@endif
                                · {{ $message->created_at->timezone($tz)->format('j M Y, H:i') }} WAT
                            </p>
                            @if ($message->amends_message_id)
                                <p style="font-size:.75rem;opacity:.8"><a href="#staff-message-{{ $message->amends_message_id }}" style="text-decoration:underline">Correction to message #{{ $message->amends_message_id }}</a></p>
                            @endif
                            <p style="white-space:pre-line;overflow-wrap:anywhere;margin-top:.2rem">{{ $message->body }}</p>
                            @if ($message->document?->current_version_id)
                                <p style="font-size:.85rem;margin-top:.3rem">
                                    <a href="{{ route('admin.document-file', $message->document->current_version_id) }}" target="_blank" rel="noopener" style="text-decoration:underline">{{ $message->document->title }}</a>
                                </p>
                            @endif
                            <p style="font-size:.7rem;opacity:.7;margin-top:.2rem;display:flex;gap:.75rem;justify-content:flex-end">
                                @if (! $internal && ! $message->sender_is_client)
                                    <span>{{ isset($readReceipts[$message->id]) ? 'Seen by the client '.$readReceipts[$message->id]->timezone($tz)->format('j M, H:i') : 'Not yet seen by the client' }}</span>
                                @endif
                                @if ($message->sender_id === auth()->id() && ! $closed && $amendingId !== $message->id)
                                    <x-filament::link tag="button" size="xs" wire:click="startAmend({{ $message->id }})">Add correction</x-filament::link>
                                @endif
                            </p>
                            @if ($amendingId === $message->id)
                                <form wire:submit="amend" style="margin-top:.5rem">
                                    <x-filament::input.wrapper :valid="! $errors->has('amendBody')">
                                        <textarea wire:model="amendBody" rows="2" maxlength="5000" class="fi-input" style="width:100%" aria-label="Correction"></textarea>
                                    </x-filament::input.wrapper>
                                    @error('amendBody')<p style="color:#dc2626;font-size:.8rem">{{ $message }}</p>@enderror
                                    <div style="display:flex;gap:.5rem;margin-top:.4rem">
                                        <x-filament::button type="submit" size="xs">Add correction</x-filament::button>
                                        <x-filament::button type="button" size="xs" color="gray" wire:click="cancelAmend">Cancel</x-filament::button>
                                    </div>
                                </form>
                            @endif
                        </li>
                    @empty
                        <li style="opacity:.7;font-size:.9rem">No messages yet.</li>
                    @endforelse
                </ol>

                @if ($closed)
                    <p style="margin-top:1rem;font-size:.9rem;opacity:.8">This matter is closed. Reopen it to add messages.</p>
                @else
                    <form wire:submit="send('{{ $pane }}')" style="margin-top:1rem">
                        <label for="{{ $bodyField }}" style="font-size:.85rem;font-weight:500">{{ $internal ? 'Add an internal note' : 'Message the client' }}</label>
                        <x-filament::input.wrapper :valid="! $errors->has($bodyField)" style="margin-top:.25rem">
                            <textarea id="{{ $bodyField }}" wire:model="{{ $bodyField }}" rows="3" maxlength="5000" class="fi-input" style="width:100%"></textarea>
                        </x-filament::input.wrapper>
                        @error($bodyField)<p style="color:#dc2626;font-size:.8rem">{{ $message }}</p>@enderror
                        <div style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:center;justify-content:space-between;margin-top:.5rem">
                            <input type="file" wire:model="{{ $fileField }}" accept="{{ $accept }}" aria-label="Attach a file" style="font-size:.8rem;max-width:100%">
                            <x-filament::button type="submit" :color="$internal ? 'warning' : 'primary'" wire:loading.attr="disabled" wire:target="send, {{ $fileField }}">
                                {{ $internal ? 'Save internal note' : 'Send to client' }}
                            </x-filament::button>
                        </div>
                        @error($fileField)<p style="color:#dc2626;font-size:.8rem">{{ $message }}</p>@enderror
                        @if (! $internal)
                            <p style="font-size:.75rem;opacity:.7;margin-top:.3rem">Files sent here are released to the client straight away. The client gets one email notice if they have not read the message within 10 minutes.</p>
                        @endif
                    </form>
                @endif
            </section>
        @endforeach
    </div>

    @unless ($closed)
        <x-filament::section collapsible collapsed heading="Record a phone call, WhatsApp chat or meeting">
            <form wire:submit="recordCall" style="display:grid;gap:.75rem">
                <div style="display:flex;flex-wrap:wrap;gap:1rem">
                    <label style="font-size:.85rem">How
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model="noteChannel">
                                @foreach ($channels as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </label>
                    <label style="font-size:.85rem">Where to record it
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model="noteAudience">
                                <option value="internal">Internal notes (staff only)</option>
                                <option value="client">Client conversation (the client will see it)</option>
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </label>
                </div>
                <x-filament::input.wrapper :valid="! $errors->has('noteBody')">
                    <textarea wire:model="noteBody" rows="3" maxlength="5000" class="fi-input" style="width:100%" aria-label="Summary of the conversation"></textarea>
                </x-filament::input.wrapper>
                @error('noteBody')<p style="color:#dc2626;font-size:.8rem">{{ $message }}</p>@enderror
                <div><x-filament::button type="submit">Save record</x-filament::button></div>
            </form>
        </x-filament::section>
    @endunless
</x-filament-panels::page>
