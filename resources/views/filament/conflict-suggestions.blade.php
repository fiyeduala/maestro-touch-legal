<div class="space-y-3 text-sm">
    <p><strong>Names searched:</strong> {{ $terms ? implode(', ', $terms) : 'none – add parties first' }}</p>
    @if ($suggestions === [])
        <p>No possible matches were found among earlier enquiries, matters and clients. This is not a clearance: confirm with your own checks.</p>
    @else
        <p>Possible matches (only the name, role and reference are shown):</p>
        <table class="w-full text-left">
            <thead>
                <tr><th class="py-1 pe-3">Name</th><th class="py-1 pe-3">Role</th><th class="py-1 pe-3">Reference</th><th class="py-1 pe-3">Found in</th><th class="py-1">Match</th></tr>
            </thead>
            <tbody>
                @foreach ($suggestions as $s)
                    <tr class="border-t border-gray-200 dark:border-white/10">
                        <td class="py-1 pe-3">{{ $s['name'] }}</td>
                        <td class="py-1 pe-3">{{ $s['role'] }}</td>
                        <td class="py-1 pe-3">{{ $s['reference'] }}</td>
                        <td class="py-1 pe-3">{{ $s['source'] }}</td>
                        <td class="py-1">{{ $s['match'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
