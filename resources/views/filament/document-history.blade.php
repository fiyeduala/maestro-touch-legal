@php($tz = config('app.firm_timezone'))
<div class="space-y-6 text-sm">
    <div>
        <h3 class="font-semibold mb-2">Versions</h3>
        <table class="w-full text-left">
            <thead class="text-gray-500">
                <tr><th class="py-1 pr-3">Version</th><th class="py-1 pr-3">File</th><th class="py-1 pr-3">Uploaded</th><th class="py-1">Note</th></tr>
            </thead>
            <tbody>
                @foreach ($document->versions->sortByDesc('version') as $version)
                    <tr class="border-t border-gray-200 dark:border-white/10 align-top">
                        <td class="py-1 pr-3">
                            v{{ $version->version }}
                            @if ($version->id === $document->released_version_id)
                                <span class="text-primary-600">(client sees this)</span>
                            @endif
                        </td>
                        <td class="py-1 pr-3">
                            <a class="underline" href="{{ route('admin.document-file', $version) }}" target="_blank" rel="noopener">{{ $version->original_name }}</a>
                            <span class="text-gray-500">({{ number_format($version->size / 1024, 0) }} KB)</span>
                        </td>
                        <td class="py-1 pr-3">
                            {{ $version->created_at?->timezone($tz)->format('j M Y H:i') }}<br>
                            <span class="text-gray-500">{{ $version->uploaded_by_client ? 'Client' : ($version->uploadedBy?->name ?? '—') }}</span>
                        </td>
                        <td class="py-1">{{ $version->note ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div>
        <h3 class="font-semibold mb-2">History</h3>
        <ul class="space-y-2">
            @forelse ($document->events as $event)
                <li>
                    <span class="text-gray-500">{{ $event->created_at?->timezone($tz)->format('j M Y H:i') }}</span>
                    · {{ str_replace('_', ' ', $event->type) }}
                    @if ($event->actor) · {{ $event->actor->name }} @endif
                    @if ($event->client_visible) <span class="text-primary-600">(client can see)</span> @endif
                    @if ($event->body)<div class="text-gray-700 dark:text-gray-300">{{ $event->body }}</div>@endif
                </li>
            @empty
                <li class="text-gray-500">No history recorded.</li>
            @endforelse
        </ul>
    </div>
</div>
