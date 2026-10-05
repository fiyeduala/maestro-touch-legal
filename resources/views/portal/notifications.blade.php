@extends('portal.layout', ['title' => 'Notifications'])

@section('portal')
    @php($tz = config('app.firm_timezone'))
    <div class="flex flex-wrap items-center justify-between gap-4">
        <p class="max-w-[760px]">Updates from the firm about your matters, documents, invoices and appointments. Turn on <strong>Alerts</strong> above to get them on this device as they happen.</p>
        @if (auth()->user()->unreadNotifications()->exists())
            <form method="post" action="{{ route('portal.notifications.read') }}">
                @csrf
                <button type="submit" class="btn-outline">Mark all as read</button>
            </form>
        @endif
    </div>

    @if ($notifications->isEmpty())
        <p class="mt-6">You have no notifications yet.</p>
    @else
        <ul class="mt-6 divide-y divide-line border-y border-line">
            @foreach ($notifications as $notification)
                <li>
                    <a href="{{ route('portal.notifications.open', $notification->id) }}" class="flex flex-wrap items-start justify-between gap-2 py-4 hover:text-brand">
                        <span>
                            <span @class(['block text-ink', 'font-semibold' => ! $notification->read_at, 'font-medium' => $notification->read_at])>{{ $notification->data['title'] ?? 'Update' }}</span>
                            @if (! empty($notification->data['body']))
                                <span class="block text-sm text-body">{{ $notification->data['body'] }}</span>
                            @endif
                            <span class="block text-sm text-body/70">{{ $notification->created_at->timezone($tz)->format('j M Y, g:i a') }} WAT</span>
                        </span>
                        @unless ($notification->read_at)
                            <span class="rounded-full bg-brand px-3 py-1 text-sm text-white">New</span>
                        @endunless
                    </a>
                </li>
            @endforeach
        </ul>
        <div class="mt-6">{{ $notifications->links() }}</div>
    @endif
@endsection
