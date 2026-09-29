@php
    use App\Domain\Operations\Settings;

    // Only the two Tawk.to identifiers are configurable, each format-checked; there is
    // no free-form script field. Public pages only: the portal layout extends the public
    // layout, so the client area and private one-time-link pages are excluded here.
    $private = request()->is('portal', 'portal/*', 'invitation/*', 'careers/application/*', 'email/*', 'preview/*');
    $property = (string) Settings::get('integrations.tawk_property_id');
    $widget = (string) Settings::get('integrations.tawk_widget_id');
    $enabled = ! $private && Settings::get('integrations.tawk_enabled')
        && preg_match('/^[a-f0-9]{24}$/', $property)
        && preg_match('/^[a-z0-9]{6,20}$/i', $widget);
@endphp
@if ($enabled)
    <script>
        var Tawk_API = Tawk_API || {}, Tawk_LoadStart = new Date();
        (function () {
            var s1 = document.createElement('script'), s0 = document.getElementsByTagName('script')[0];
            s1.async = true;
            s1.src = @json('https://embed.tawk.to/'.$property.'/'.$widget);
            s1.charset = 'UTF-8';
            s1.setAttribute('crossorigin', '*');
            s0.parentNode.insertBefore(s1, s0);
        })();
    </script>
@endif
