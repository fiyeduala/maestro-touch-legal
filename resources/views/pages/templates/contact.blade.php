@php
    use App\Domain\Operations\Settings;
    use App\Support\Markup;
    $c = $revision->content;

    // Contact details appear only once the owner has entered them in Settings; none are invented.
    $email = Settings::get('contact.email');
    $phone = Settings::get('contact.phone');
    $address = Settings::get('contact.address');
    $whatsapp = preg_replace('/\D+/', '', (string) Settings::get('contact.whatsapp_number'));
@endphp

@include('pages.partials.banner', ['heading' => $c['banner']['heading'] ?? $revision->title])

<section class="bg-white py-16 md:py-20 lg:py-[100px]">
    <div class="site-container max-w-[640px] text-center">
        <h2 class="heading-2">{{ Markup::hl($c['intro']['heading'] ?? '') }}</h2>

        @if ($email || $phone || $address || $whatsapp)
            <dl class="mt-10 grid gap-6 text-left sm:grid-cols-2">
                @if ($email)
                    <div><dt class="font-semibold text-ink">Email</dt><dd><a class="text-brand hover:underline" href="mailto:{{ $email }}">{{ $email }}</a></dd></div>
                @endif
                @if ($phone)
                    <div><dt class="font-semibold text-ink">Phone</dt><dd><a class="text-brand hover:underline" href="tel:{{ preg_replace('/[^\d+]/', '', $phone) }}">{{ $phone }}</a></dd></div>
                @endif
                @if ($whatsapp)
                    <div><dt class="font-semibold text-ink">WhatsApp</dt><dd><a class="text-brand hover:underline" rel="noopener" target="_blank" href="https://wa.me/{{ $whatsapp }}?text={{ rawurlencode((string) Settings::get('contact.whatsapp_message')) }}">Chat with us</a></dd></div>
                @endif
                @if ($address)
                    <div class="sm:col-span-2"><dt class="font-semibold text-ink">Address</dt><dd class="whitespace-pre-line">{{ $address }}</dd></div>
                @endif
            </dl>
        @endif
    </div>
</section>

@include('pages.partials.cta', ['cta' => $c['cta'] ?? []])
