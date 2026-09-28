@php
    use App\Support\Markup;
    $c = $revision->content;
@endphp

@include('pages.partials.banner', ['heading' => $c['banner']['heading'] ?? $revision->title])

<section class="bg-white pt-14 pb-10">
    <div class="site-container">
        <ul class="grid gap-x-5 gap-y-10 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($c['areas'] ?? [] as $area)
                <li class="text-center">
                    @if (! empty($area['image']))
                        <img src="{{ Markup::safeUrl($area['image']) }}" alt="{{ $area['image_alt'] ?? '' }}"
                             width="2560" height="1440" loading="lazy" class="aspect-[16/9] h-auto w-full object-cover">
                    @endif
                    <h3 class="mt-5 text-2xl leading-[1.2] font-semibold">{{ $area['title'] }}</h3>
                    <p class="mt-5 text-base">{{ $area['text'] }}</p>
                </li>
            @endforeach
        </ul>

        @if (! empty($c['notary']['heading']))
            <div class="mx-auto mt-10 flex max-w-[960px] flex-col items-center gap-5 rounded-xl border-2 border-brand px-6 py-4 text-center">
                <h2 class="text-[30px] leading-[1.2] font-normal md:text-[40px]">{{ $c['notary']['heading'] }}</h2>
                @if (! empty($c['notary']['button']['label']))
                    <a href="{{ Markup::safeUrl($c['notary']['button']['url'] ?? '#') }}" class="btn" rel="noopener">
                        {{ $c['notary']['button']['label'] }} <x-mtl-icon name="arrow-circle-right" />
                    </a>
                @endif
            </div>
        @endif
    </div>
</section>

@include('pages.partials.cta', ['cta' => $c['cta'] ?? []])
