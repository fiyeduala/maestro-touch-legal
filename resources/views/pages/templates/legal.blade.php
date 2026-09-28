@php
    use Mews\Purifier\Facades\Purifier;
    $c = $revision->content;
@endphp

@include('pages.partials.banner', ['heading' => $c['banner']['heading'] ?? $revision->title])

<section class="bg-white py-14 md:py-20">
    <div class="site-container max-w-[900px]">
        @if (! empty($c['notice']))
            <div class="alert alert-info mb-8" role="note">{{ $c['notice'] }}</div>
        @endif
        <div class="prose-mtl">
            {{-- Stored body is sanitised on save; purified again on output as defence in depth. --}}
            {!! Purifier::clean($c['body'] ?? '', 'content') !!}
        </div>
    </div>
</section>
