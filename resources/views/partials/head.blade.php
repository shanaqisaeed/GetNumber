<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, interactive-widget=resizes-content">
<meta name="theme-color" content="#0f172a" media="(prefers-color-scheme: dark)">
<meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">

<!-- Base SEO & Metadata -->
<meta name="description" content="سامانه جامع مدیریت شماره‌های مجازی، سرشماره‌های اختصاصی سازمانی، هاب پیام‌رسانی امن و وب‌سرویس ارتباطی GetNumber">
<link rel="canonical" href="{{ url()->current() }}">

<!-- Dynamic Robots Directive: Strictly Protect Private, Dashboard, and Authenticated Routes -->
@if(auth()->check() || request()->is('admin*', 'dashboard*', 'numbers*', 'requests*', 'messages*', 'api-tokens*', 'tickets*', 'profile*'))
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
@else
    <meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large">
@endif

<!-- Open Graph / Facebook -->
<meta property="og:type" content="website">
<meta property="og:locale" content="fa_IR">
<meta property="og:url" content="{{ url()->current() }}">
<meta property="og:site_name" content="{{ config('app.name', 'GetNumber') }}">
<meta property="og:title" content="{{ config('app.name', 'GetNumber') }} - سامانه مدیریت شماره‌های مجازی و ارتباطات سازمانی">
<meta property="og:description" content="سامانه جامع مدیریت شماره‌های مجازی، سرشماره‌های اختصاصی سازمانی، هاب پیام‌رسانی امن و وب‌سرویس ارتباطی GetNumber">

<!-- Twitter Card -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ config('app.name', 'GetNumber') }} - سامانه مدیریت شماره‌های مجازی">
<meta name="twitter:description" content="سامانه جامع مدیریت شماره‌های مجازی، سرشماره‌های اختصاصی سازمانی و پیام‌رسانی امن">

<!-- Structured Data (JSON-LD) for Public Pages -->
@if(!auth()->check() && (request()->is('/') || request()->is('docs*')))
<script type="application/ld+json">
{!! json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'SoftwareApplication',
    'name' => config('app.name', 'GetNumber'),
    'applicationCategory' => 'BusinessApplication',
    'operatingSystem' => 'All',
    'description' => 'Enterprise Virtual Number Management & Internal Messaging Gateway - سامانه مدیریت شماره‌های مجازی و ارتباطات سازمانی',
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
