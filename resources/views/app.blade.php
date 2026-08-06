<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

        @production
            <meta name="yandex-verification" content="7050fc1b2f8a8507" />
        @endproduction

        {{-- SEO-мета рендерится сервером из seo-пропа Inertia (без SSR React) — видна в view-source и краулерам. --}}
        @php
            $seo = $page['props']['seo'] ?? [];
            $seoType = $seo['type'] ?? 'website';
            $seoImage = $seo['image'] ?? null;
            $structuredData = $seo['structured_data'] ?? null;
            if (! empty($structuredData)) {
                $structuredData = array_is_list($structuredData) ? $structuredData : [$structuredData];
            }
        @endphp

        <title inertia>{{ $seo['title'] ?? config('app.name', 'Laravel') }}</title>
        @if (! empty($seo['description']))
            <meta name="description" content="{{ $seo['description'] }}">
        @endif
        @if (! empty($seo['keywords']))
            <meta name="keywords" content="{{ $seo['keywords'] }}">
        @endif
        @if (! empty($seo['canonical']))
            <link rel="canonical" href="{{ $seo['canonical'] }}">
        @endif
        @if (! empty($seo['robots']))
            <meta name="robots" content="{{ $seo['robots'] }}">
        @endif

        {{-- Open Graph --}}
        @if (! empty($seo['title']))
            <meta property="og:title" content="{{ $seo['title'] }}">
        @endif
        @if (! empty($seo['description']))
            <meta property="og:description" content="{{ $seo['description'] }}">
        @endif
        <meta property="og:type" content="{{ $seoType }}">
        @if (! empty($seo['url']))
            <meta property="og:url" content="{{ $seo['url'] }}">
        @endif
        @if ($seoImage)
            <meta property="og:image" content="{{ $seoImage }}">
        @endif

        {{-- Twitter Card --}}
        <meta name="twitter:card" content="{{ $seoImage ? 'summary_large_image' : 'summary' }}">
        @if (! empty($seo['title']))
            <meta name="twitter:title" content="{{ $seo['title'] }}">
        @endif
        @if (! empty($seo['description']))
            <meta name="twitter:description" content="{{ $seo['description'] }}">
        @endif
        @if ($seoImage)
            <meta name="twitter:image" content="{{ $seoImage }}">
        @endif

        {{-- JSON-LD --}}
        @if (! empty($structuredData))
            <script type="application/ld+json">{!! json_encode($structuredData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
        @endif

        <link rel="icon" type="image/x-icon" href="/favicon.ico">
        <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
        <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16.png">
        <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
        <link rel="manifest" href="/manifest.json">
        <meta name="theme-color" content="#9e1b32">

        <!-- Fonts: preload наиболее используемых весов -->
        <link rel="preload" href="/fonts/jost/jost-latin-400-normal.woff2" as="font" type="font/woff2" crossorigin>
        <link rel="preload" href="/fonts/jost/jost-cyrillic-400-normal.woff2" as="font" type="font/woff2" crossorigin>
        <link rel="preload" href="/fonts/jost/jost-latin-600-normal.woff2" as="font" type="font/woff2" crossorigin>
        <link rel="preload" href="/fonts/jost/jost-cyrillic-600-normal.woff2" as="font" type="font/woff2" crossorigin>

        <!-- Scripts -->
        @routes
        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.jsx'])
        @inertiaHead

        @production
            <!-- Yandex.Metrika counter -->
            <script type="text/javascript">
                (function(m,e,t,r,i,k,a){
                    m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};
                    m[i].l=1*new Date();
                    for (var j = 0; j < document.scripts.length; j++) {if (document.scripts[j].src === r) { return; }}
                    k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)
                })(window, document,'script','https://mc.yandex.ru/metrika/tag.js?id=109735102', 'ym');

                ym(109735102, 'init', {ssr:true, webvisor:true, clickmap:true, ecommerce:"dataLayer", referrer: document.referrer, url: location.href, accurateTrackBounce:true, trackLinks:true});
            </script>
            <noscript><div><img src="https://mc.yandex.ru/watch/109735102" style="position:absolute; left:-9999px;" alt="" /></div></noscript>
            <!-- /Yandex.Metrika counter -->

            <!-- Google tag (gtag.js) -->
            <script async src="https://www.googletagmanager.com/gtag/js?id=G-R8WPGNCEN0"></script>
            <script>
                window.dataLayer = window.dataLayer || [];
                function gtag(){dataLayer.push(arguments);}
                gtag('js', new Date());

                gtag('config', 'G-R8WPGNCEN0');
            </script>
            <!-- /Google tag (gtag.js) -->
        @endproduction
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
