<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';
                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        <style>
            html {
                background-color: oklch(1 0 0);
            }
            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        <link rel="icon" href="/favicon.ico?v=3" sizes="any">
        <link
            rel="icon"
            href="/favicon-light.svg?v=3"
            type="image/svg+xml"
            media="(prefers-color-scheme: light)"
        >
        <link
            rel="icon"
            href="/favicon-dark.svg?v=3"
            type="image/svg+xml"
            media="(prefers-color-scheme: dark)"
        >
        <link rel="apple-touch-icon" href="/apple-touch-icon.png?v=3">
        {{-- Golos Text self-hosted via app.css @font-face --}}

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ config('app.brand', 'ИРСИ') }}</title>
        </x-inertia::head>

        @php($yandexMetrikaCounterId = (int) config('analytics.yandex_metrika_counter_id'))
        {{-- Яндекс Метрика — только гостевая главная (welcome-v2, маршрут /).
             Сниппет статичен в blade и лежит вне React-дерева: SSR-рендер и
             гидратация не выполняют его повторно, один init = один просмотр.
             Пустой/нечисловый YANDEX_METRIKA_COUNTER_ID (0) — счётчик выключен.
             webvisor и trackLinks выключены явно; аналитика форм включается
             вместе с Вебвизором в настройках счётчика и остаётся выключенной. --}}
        @if($yandexMetrikaCounterId > 0 && $page['component'] === 'welcome-v2')
            <script type="text/javascript">
                (function(m,e,t,r,i,k,a){m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};
                m[i].l=1*new Date();
                for (var j = 0; j < document.scripts.length; j++) {if (document.scripts[j].src === r) { return; }}
                k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)})
                (window, document, "script", "https://mc.yandex.ru/metrika/tag.js", "ym");

                ym({{ $yandexMetrikaCounterId }}, "init", {
                    clickmap: true,
                    trackLinks: false,
                    accurateTrackBounce: false,
                    webvisor: false
                });
            </script>
            <noscript><div><img src="https://mc.yandex.ru/watch/{{ $yandexMetrikaCounterId }}" style="position:absolute; left:-9999px;" alt="" /></div></noscript>
        @endif
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
