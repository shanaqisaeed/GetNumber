<!DOCTYPE html>
<html lang="fa" dir="rtl" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>@yield('code') - @yield('title') | {{ config('app.name', 'GetNumber') }}</title>

    <style>
        :root {
            --bg-canvas: #090d16;
            --bg-card: rgba(15, 23, 42, 0.75);
            --border-card: rgba(255, 255, 255, 0.08);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --color-primary: #6366f1;
            --color-primary-hover: #4f46e5;
            --badge-bg: rgba(99, 102, 241, 0.12);
            --badge-text: #818cf8;
            --badge-border: rgba(99, 102, 241, 0.25);
        }

        @media (prefers-color-scheme: light) {
            :root {
                --bg-canvas: #f8fafc;
                --bg-card: rgba(255, 255, 255, 0.85);
                --border-card: rgba(0, 0, 0, 0.08);
                --text-main: #0f172a;
                --text-muted: #64748b;
                --color-primary: #4f46e5;
                --color-primary-hover: #4338ca;
                --badge-bg: rgba(79, 70, 229, 0.08);
                --badge-text: #4f46e5;
                --badge-border: rgba(79, 70, 229, 0.2);
            }
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: Vazirmatn, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: var(--bg-canvas);
            color: var(--text-main);
            line-height: 1.6;
            padding: 1.5rem;
            position: relative;
            overflow-x: hidden;
        }

        /* Ambient background glow */
        .ambient-glow {
            position: absolute;
            width: 450px;
            height: 450px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(99, 102, 241, 0.15) 0%, rgba(99, 102, 241, 0) 70%);
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            pointer-events: none;
            z-index: 0;
        }

        .error-card {
            position: relative;
            z-index: 1;
            max-width: 520px;
            width: 100%;
            background: var(--bg-card);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid var(--border-card);
            border-radius: 1.5rem;
            padding: 2.5rem 2rem;
            text-align: center;
            box-shadow: 0 20px 40px -15px rgba(0, 0, 0, 0.35);
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.35rem 0.85rem;
            border-radius: 9999px;
            font-size: 0.8125rem;
            font-weight: 700;
            background: var(--badge-bg);
            color: var(--badge-text);
            border: 1px solid var(--badge-border);
            margin-bottom: 1.25rem;
        }

        .status-badge svg {
            width: 1rem;
            height: 1rem;
        }

        .error-code {
            font-size: 4.5rem;
            font-weight: 900;
            line-height: 1;
            letter-spacing: -0.05em;
            background: linear-gradient(135deg, #818cf8 0%, #c084fc 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 0.75rem;
        }

        .error-title {
            font-size: 1.35rem;
            font-weight: 800;
            color: var(--text-main);
            margin-bottom: 0.75rem;
        }

        .error-description {
            font-size: 0.9375rem;
            color: var(--text-muted);
            margin-bottom: 2rem;
            line-height: 1.7;
        }

        .actions-group {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            justify-content: center;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.65rem 1.35rem;
            border-radius: 0.85rem;
            font-size: 0.875rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s ease;
            cursor: pointer;
            border: 1px solid transparent;
        }

        .btn-primary {
            background: var(--color-primary);
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
        }

        .btn-primary:hover {
            background: var(--color-primary-hover);
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-main);
            border: 1px solid var(--border-card);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-1px);
        }

        .footer-note {
            margin-top: 2rem;
            padding-top: 1.25rem;
            border-top: 1px solid var(--border-card);
            font-size: 0.75rem;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .footer-note svg {
            width: 0.875rem;
            height: 0.875rem;
        }
    </style>
</head>
<body>
    <div class="ambient-glow"></div>

    <main class="error-card">
        <div class="status-badge">
            @yield('icon')
            <span>خطای سیستمی @yield('code')</span>
        </div>

        <h1 class="error-code">@yield('code')</h1>
        <h2 class="error-title">@yield('title')</h2>
        <p class="error-description">@yield('message')</p>

        <div class="actions-group">
            <a href="{{ url('/') }}" class="btn btn-primary">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                    <polyline points="9 22 9 12 15 12 15 22"/>
                </svg>
                بازگشت به صفحه اصلی
            </a>

            @yield('actions')
        </div>

        <div class="footer-note">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect width="18" height="11" x="3" y="11" rx="2" ry="2"/>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
            <span>سامانه امن ارتباطات سازمانی GetNumber &bull; ارتباط محافظت‌شده</span>
        </div>
    </main>
</body>
</html>

