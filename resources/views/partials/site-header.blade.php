@php
    use App\Domain\Operations\Settings;
    use App\Support\Markup;

    $normalise = fn (string $path) => '/'.trim($path, '/');
    $current = $normalise(request()->path());
    $isActive = fn (string $url) => $normalise(parse_url($url, PHP_URL_PATH) ?: '/') === $current;

    $user = auth()->user();
    if ($user?->isStaff() && $user->isActive()) {
        [$accountLabel, $accountUrl] = ['Staff Admin', url('/admin')];
    } elseif ($user) {
        [$accountLabel, $accountUrl] = ['My Account', url('/portal')];
    } else {
        [$accountLabel, $accountUrl] = [Settings::get('navigation.account_label'), url('/register/')];
    }

    $headerBg = match ($tone ?? 'white') {
        'tint' => 'bg-tint',
        default => 'bg-white',
    };
@endphp
<header class="{{ $overlay ? 'absolute inset-x-0 top-0 z-30' : 'relative z-30 '.$headerBg }}">
    <div class="site-container flex h-[100px] items-center justify-between gap-6">
        <a href="{{ url('/') }}" class="shrink-0" rel="home">
            <img src="{{ Markup::safeUrl(Settings::get('site.logo_path')) }}" alt="{{ Settings::get('site.logo_alt') }}"
                 width="450" height="130" class="h-auto w-[120px] nav:w-[122px]">
        </a>

        <nav aria-label="Primary" class="hidden nav:block">
            <ul class="flex items-center">
                @foreach (Settings::get('navigation.primary') as $item)
                    <li>
                        <a href="{{ Markup::safeUrl($item['url']) }}"
                           @class(['block px-4 text-base leading-4 font-medium', 'text-brand' => $isActive($item['url']), 'text-ink hover:text-brand' => ! $isActive($item['url'])])
                           @if ($isActive($item['url'])) aria-current="page" @endif>{{ $item['label'] }}</a>
                    </li>
                @endforeach
            </ul>
        </nav>

        <div class="hidden nav:block">
            <a href="{{ $accountUrl }}" class="btn-outline">{{ $accountLabel }}</a>
        </div>

        <button type="button" class="inline-flex size-11 items-center justify-center rounded border border-dotted border-brand text-brand nav:hidden"
                data-menu-toggle aria-controls="mobile-menu" aria-expanded="false">
            <span class="sr-only">Menu</span>
            <x-mtl-icon name="menu" class="size-6" data-icon-open />
            <x-mtl-icon name="close" class="hidden size-6" data-icon-close />
        </button>
    </div>

    <div id="mobile-menu" class="nav:hidden {{ $overlay ? 'bg-white' : $headerBg }}" hidden>
        <nav aria-label="Mobile" class="site-container pb-6">
            <ul>
                @foreach (Settings::get('navigation.primary') as $item)
                    <li>
                        <a href="{{ Markup::safeUrl($item['url']) }}"
                           @class(['block py-4 text-lg', 'text-brand' => $isActive($item['url']), 'text-ink' => ! $isActive($item['url'])])>{{ $item['label'] }}</a>
                    </li>
                @endforeach
                <li class="pt-3"><a href="{{ $accountUrl }}" class="btn-outline">{{ $accountLabel }}</a></li>
            </ul>
        </nav>
    </div>
</header>
