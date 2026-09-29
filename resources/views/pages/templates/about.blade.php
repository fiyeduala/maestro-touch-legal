@php
    use App\Support\Markup;
    $c = $revision->content;
@endphp

@include('pages.partials.banner', ['heading' => $c['banner']['heading'] ?? $revision->title])

<section class="bg-white py-16 md:py-20 lg:py-[100px]">
    <div class="site-container">
        <h2 class="heading-2 text-center">{{ Markup::hl($c['who']['heading'] ?? '') }}</h2>

        <div class="mt-12 grid items-center gap-10 lg:mt-16 lg:grid-cols-2 lg:gap-20">
            <div class="max-w-[500px] space-y-6 text-[15px] leading-[1.75]">
                @foreach ($c['who']['paragraphs'] ?? [] as $p)
                    <p>{{ $p }}</p>
                @endforeach
            </div>
            @if (! empty($c['who']['image']))
                <img src="{{ Markup::safeUrl($c['who']['image']) }}" alt="{{ $c['who']['image_alt'] ?? '' }}"
                     width="1280" height="958" loading="lazy" class="h-auto w-full max-w-[460px] justify-self-center rounded-md">
            @endif
        </div>

        @if (! empty($c['affiliate']['title']))
            <div class="mx-auto mt-12 max-w-[960px] rounded-md border border-brand px-6 py-6">
                <h3 class="text-[22px] font-medium">{{ $c['affiliate']['title'] }}</h3>
                <p class="mt-3 text-[15px]">{{ $c['affiliate']['text'] }}</p>
                @if (! empty($c['affiliate']['image']))
                    {{-- As on the old site: the affiliate's logo shows on tablets and phones only. --}}
                    <img src="{{ Markup::safeUrl($c['affiliate']['image']) }}" alt="{{ $c['affiliate']['image_alt'] ?? '' }}"
                         loading="lazy" class="mt-6 h-auto w-full max-w-[420px] lg:hidden">
                @endif
            </div>
        @endif

        @if (! empty($c['points']))
            <div class="mt-12 grid gap-8 md:grid-cols-3">
                @foreach ($c['points'] as $i => $point)
                    <div @class(['md:px-10 md:py-0', 'border-b border-line pb-8' => $i < count($c['points']) - 1, 'md:border-b-0' => $i !== 1, 'md:border md:border-line md:pb-6' => $i === 1, 'md:pl-0' => $i === 0])>
                        <p class="card-number">{{ $point['number'] ?? sprintf('%02d', $i + 1) }}</p>
                        <h3 class="mt-2 text-[22px] font-medium">{{ $point['title'] }}</h3>
                        <p class="mt-3 text-[15px]">{{ $point['text'] }}</p>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</section>

@if (! empty($c['statements']))
    <section class="bg-tint py-16 md:py-20 lg:py-[100px]">
        <div class="site-container">
            @foreach ($c['statements'] as $i => $s)
                <div @class(['grid gap-4 md:grid-cols-3 md:gap-10', 'mt-12 border-t border-brand/70 pt-12' => $i > 0])>
                    <h3 class="text-[22px] font-medium">{{ Markup::hl($s['heading']) }}</h3>
                    <p class="text-[15px] md:col-span-2">{{ $s['text'] }}</p>
                </div>
            @endforeach
        </div>
    </section>
@endif

@include('pages.partials.cta', ['cta' => $c['cta'] ?? []])
