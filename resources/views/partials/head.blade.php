<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, interactive-widget=resizes-content">
<meta name="theme-color" content="#0f172a" media="(prefers-color-scheme: dark)">
<meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">

<title inertia>{{ config('app.name', 'GetNumber') }}</title>
<!-- Scripts and Styles handled entirely via Vite -->
@routes
@vite(['resources/js/app.ts', "resources/js/Pages/{$page['component']}.vue"])
@inertiaHead
