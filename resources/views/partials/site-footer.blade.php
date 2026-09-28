@php
    use App\Domain\Content\PageRevisions;
    use App\Domain\Operations\Settings;

    $footerText = str_replace('{year}', now(config('app.firm_timezone'))->format('Y'), (string) Settings::get('footer.text'));
    // Legal pages are linked only once published (the privacy policy starts as an unapproved draft).
    $footerLinks = PageRevisions::publishedLegalLinks() + ['/join-our-legal-team/' => 'Join Our Legal Team'];
@endphp
<footer class="bg-white py-6 text-center text-sm text-body">
    <div class="site-container">
        <p>{{ $footerText }}</p>
        <p class="mt-1 text-xs">
            @foreach ($footerLinks as $path => $label)
                @unless ($loop->first)<span aria-hidden="true">·</span>@endunless
                <a href="{{ url($path) }}" class="hover:text-brand">{{ $label }}</a>
            @endforeach
        </p>
    </div>
</footer>
