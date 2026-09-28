@php use App\Support\Markup; @endphp
@if (! empty($cta['heading']))
    <section @class(['py-16 md:py-20 lg:py-[100px]', $background ?? 'bg-white'])>
        <div class="site-container flex max-w-[800px] flex-col items-center gap-4 text-center">
            <h2 class="text-[30px] leading-[1.2] font-medium text-ink md:text-[40px]">{{ Markup::hl($cta['heading']) }}</h2>
            @if (! empty($cta['button']['label']))
                <a href="{{ Markup::safeUrl($cta['button']['url'] ?? '#') }}" class="btn">
                    {{ $cta['button']['label'] }} <x-mtl-icon name="arrow-alt-circle-right" />
                </a>
            @endif
        </div>
    </section>
@endif
