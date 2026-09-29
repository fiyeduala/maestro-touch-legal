<div class="text-sm">
    <p class="text-lg font-semibold text-ink">{{ \App\Domain\Operations\Settings::get('site.title') }}</p>
    @if ($address = \App\Domain\Operations\Settings::get('contact.address'))<p class="whitespace-pre-line">{{ $address }}</p>@endif
    @if ($email = \App\Domain\Operations\Settings::get('contact.email'))<p>{{ $email }}</p>@endif
    @if ($phone = \App\Domain\Operations\Settings::get('contact.phone'))<p>{{ $phone }}</p>@endif
</div>
