<?php

return [

    // Единый источник версии юридических документов.
    // ВАЖНО: та же дата, что зашита в resources/js/pages/legal/offer.tsx и privacy.tsx.
    'version' => '11.08.2026',

    // Абсолютные URL боевых документов (для URL-кнопок в ботах).
    // НЕ берём из app.url — иначе на локалке кнопки Telegram упадут (нужен https).
    'offer_url' => 'https://irsi-app.ru/offer',
    'privacy_url' => 'https://irsi-app.ru/privacy',

];
