{{-- Home-screen install: square icons and a manifest, so phones show the firm's mark instead of a letter. --}}
<link rel="manifest" href="{{ url($manifest ?? (request()->is('portal', 'portal/*') ? '/portal.webmanifest' : '/site.webmanifest')) }}">
<link rel="apple-touch-icon" href="{{ url('/icons/apple-touch-icon.png') }}" sizes="180x180">
<meta name="theme-color" content="#007bf8">
<meta name="apple-mobile-web-app-title" content="{{ $appTitle ?? (request()->is('portal', 'portal/*') ? 'MTL Portal' : 'Maestro Touch') }}">
