@php
    use App\Support\Markup;
    /** @var \App\Models\PageRevision $revision */
    $c = $revision->content;
@endphp

{{-- Hero --}}
<section class="flex min-h-[100svh] items-center justify-center px-6 pt-[120px] pb-16 text-center md:pt-[165px] md:pb-[100px]">
    <div class="mx-auto flex max-w-[780px] flex-col items-center gap-[15px]">
        @if (! empty($c['hero']['eyebrow']))
            <p class="text-sm font-medium tracking-wide text-brand uppercase">{{ $c['hero']['eyebrow'] }}</p>
        @endif
        <h1 class="heading-1">{{ Markup::hl($c['hero']['heading'] ?? '') }}</h1>
        @if (! empty($c['hero']['subheading']))
            <p class="text-lg">{{ $c['hero']['subheading'] }}</p>
        @endif
        @if (! empty($c['hero']['button']['label']))
            <a href="{{ Markup::safeUrl($c['hero']['button']['url'] ?? '#') }}" class="btn mt-2">
                {{ $c['hero']['button']['label'] }} <x-mtl-icon name="arrow-alt-circle-right" />
            </a>
        @endif
    </div>
</section>

{{-- Fixed-background image band --}}
@if (! empty($c['band']['image']))
    <div role="img" aria-label="{{ $c['band']['alt'] ?? '' }}"
         class="min-h-[300px] bg-cover bg-top bg-no-repeat md:min-h-[400px] lg:min-h-[500px] lg:bg-fixed"
         style="background-image: url('{{ Markup::safeUrl($c['band']['image']) }}')"></div>
@endif

{{-- Intro + how it works --}}
<section class="bg-tint py-16 md:py-20 lg:py-[100px]">
    <div class="site-container">
        <div class="grid items-center gap-12 lg:grid-cols-2 lg:gap-20">
            @if (! empty($c['intro']['image']))
                <img src="{{ Markup::safeUrl($c['intro']['image']) }}" alt="{{ $c['intro']['image_alt'] ?? '' }}"
                     width="700" height="1000" loading="lazy" class="h-auto w-full max-w-[560px] rounded-md object-cover">
            @endif
            <div>
                <h2 class="heading-2 text-[30px] md:text-[34px]">{{ Markup::hl($c['intro']['heading'] ?? '') }}</h2>
                <div class="mt-4 space-y-3 text-[15px]">
                    @foreach ($c['intro']['paragraphs'] ?? [] as $p)
                        <p>{{ $p }}</p>
                    @endforeach
                </div>
                @if (! empty($c['intro']['link']['label']))
                    <a href="{{ Markup::safeUrl($c['intro']['link']['url'] ?? '#') }}" class="link-arrow mt-5">
                        {{ $c['intro']['link']['label'] }} <x-mtl-icon name="arrow-right" class="size-3.5" />
                    </a>
                @endif
            </div>
        </div>

        @if (! empty($c['how']['steps']))
            <div class="mt-14">
                @if (! empty($c['how']['heading']))
                    <h2 class="mb-6 text-2xl font-semibold text-ink">{{ Markup::hl($c['how']['heading']) }}</h2>
                @endif
                <ol class="grid gap-8 md:grid-cols-3 md:gap-0">
                    @foreach ($c['how']['steps'] as $i => $step)
                        <li @class(['md:px-8', 'md:pl-0' => $i === 0, 'md:border-l md:border-line' => $i > 0])>
                            <p class="card-number">{{ $step['number'] ?? sprintf('%02d', $i + 1) }}</p>
                            <h3 class="mt-2 text-xl font-semibold">{{ $step['title'] }}</h3>
                            <p class="mt-3 text-sm">{{ $step['text'] }}</p>
                        </li>
                    @endforeach
                </ol>
            </div>
        @endif
    </div>
</section>

{{-- What we offer --}}
<section class="bg-white py-16 md:py-20 lg:py-[100px]">
    <div class="site-container grid items-center gap-12 lg:grid-cols-2 lg:gap-10">
        <div class="order-2 lg:order-1">
            <h2 class="heading-2 text-[30px] md:text-[34px]">{{ Markup::hl($c['offer']['heading'] ?? '') }}</h2>
            <ul class="mt-8 space-y-6">
                @foreach ($c['offer']['items'] ?? [] as $item)
                    <li class="flex gap-5">
                        <span class="mt-1 inline-flex size-8 shrink-0 items-center justify-center rounded-full bg-tint text-brand">
                            <x-mtl-icon name="check-circle" class="size-4" />
                        </span>
                        <div>
                            <h3 class="text-lg font-semibold">{{ $item['title'] }}</h3>
                            <p class="mt-1 max-w-[460px] text-sm">{{ $item['text'] }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
        @if (! empty($c['offer']['image']))
            <div class="order-1 flex justify-center lg:order-2">
                {{-- The blue accent bar is part of the image itself. --}}
                <img src="{{ Markup::safeUrl($c['offer']['image']) }}" alt="{{ $c['offer']['image_alt'] ?? '' }}"
                     width="720" height="1080" loading="lazy" class="h-auto w-full max-w-[355px]">
            </div>
        @endif
    </div>
</section>

{{-- Why choose us --}}
<section class="bg-tint py-16 md:py-20 lg:py-[100px]">
    <div class="site-container flex flex-col gap-12">
        <h2 class="heading-2 text-center text-[30px] md:text-[34px]">{{ Markup::hl($c['why']['heading'] ?? '') }}</h2>
        <div class="grid items-center gap-10 md:grid-cols-2">
            @if (! empty($c['why']['logo']))
                <div class="flex justify-center">
                    <img src="{{ Markup::safeUrl($c['why']['logo']) }}" alt="{{ $c['why']['logo_alt'] ?? '' }}"
                         width="450" height="130" loading="lazy" class="h-auto w-[280px]">
                </div>
            @endif
            <ul class="space-y-4">
                @foreach ($c['why']['items'] ?? [] as $item)
                    <li class="flex items-center gap-5">
                        <x-mtl-icon name="check-circle" class="size-4 shrink-0 text-brand" />
                        <h3 class="text-base font-medium">{{ $item }}</h3>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
</section>

@include('pages.partials.cta', ['cta' => $c['cta'] ?? []])
