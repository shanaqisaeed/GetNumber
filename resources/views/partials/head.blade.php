<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, interactive-widget=resizes-content">
<meta name="theme-color" content="#0f172a" media="(prefers-color-scheme: dark)">
<meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">

<!-- Instant Theme Preload (Prevents Flash of Wrong Theme) -->
<script>
    (function() {
        try {
            var saved = localStorage.getItem('getnumber_theme');
            var isDark = saved === 'dark' || (saved !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches);
            var root = document.documentElement;
            if (isDark) {
                root.classList.add('dark');
                root.classList.remove('light');
                root.style.colorScheme = 'dark';
            } else {
                root.classList.remove('dark');
                root.classList.add('light');
                root.style.colorScheme = 'light';
            }
        } catch (e) {}
    })();
</script>

<!-- Base SEO & Metadata -->
<meta name="description" content="{{ __('views.head.meta_description') }}">
<link rel="canonical" href="{{ url()->current() }}">

<!-- Dynamic Robots Directive: Strictly Protect Private, Dashboard, and Authenticated Routes -->
@if(auth()->check() || request()->is('admin*', 'dashboard*', 'numbers*', 'requests*', 'messages*', 'api-tokens*', 'tickets*', 'profile*'))
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
@else
    <meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large">
@endif

<!-- Open Graph / Facebook -->
<meta property="og:type" content="website">
<meta property="og:locale" content="{{ app()->getLocale() === 'fa' ? 'fa_IR' : 'en_US' }}">
<meta property="og:url" content="{{ url()->current() }}">
<meta property="og:site_name" content="{{ config('app.name', 'GetNumber') }}">
<meta property="og:title" content="{{ config('app.name', 'GetNumber') }} - {{ __('views.head.og_title') }}">
<meta property="og:description" content="{{ __('views.head.og_description') }}">

<!-- Twitter Card -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ config('app.name', 'GetNumber') }} - {{ __('views.head.twitter_title') }}">
<meta name="twitter:description" content="{{ __('views.head.twitter_description') }}">

<!-- Structured Data (JSON-LD) for Public Pages -->
@if(!auth()->check() && (request()->is('/') || request()->is('docs*')))
<script type="application/ld+json">
{!! json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'SoftwareApplication',
    'name' => config('app.name', 'GetNumber'),
    'applicationCategory' => 'BusinessApplication',
    'operatingSystem' => 'All',
    'description' => __('views.head.jsonld_description'),
    'offers' => [
        '@type' => 'Offer',
        'price' => '0',
        'priceCurrency' => 'IRR',
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
</script>
@endif

<title inertia>{{ config('app.name', 'GetNumber') }}</title>

<!-- Scripts and Styles handled entirely via Vite -->
@routes
@vite(['resources/js/app.ts', "resources/js/Pages/{$page['component']}.vue"])
@inertiaHead
