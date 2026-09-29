@php use App\Support\Markup; @endphp
{{-- Inner-page title band; the header overlays its top (as on the live site). --}}
<section class="page-banner pt-[120px] pb-16 md:pt-[150px] md:pb-[100px]">
    <div class="site-container">
        <h1 class="heading-1">{{ Markup::hl($heading) }}</h1>
    </div>
</section>
