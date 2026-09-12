<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>Мои записи</title>
    <script>window.__VK_GROUP_ID__ = @json(config('services.vk.group_id'));</script>
    @viteReactRefresh
    @vite(['resources/js/vk-app/main.tsx'])
</head>
<body>
    <div id="vk-root"></div>
</body>
</html>
