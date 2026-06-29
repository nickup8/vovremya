<?php

return [
    'reminder_24h' => [
        'telegram' => "⏰ Напоминание!\n\nВы записаны к :master завтра в :time.\nУслуга: :service\nАдрес: :address\n\nЖдём вас!",
        'max' => "⏰ Напоминание!\n\nВы записаны к :master завтра в :time.\nУслуга: :service\nАдрес: :address\n\nЖдём вас!",
    ],
    'reminder_final' => [
        'telegram' => "🔔 До вашего визита осталось :hours ч.\n\nМастер: :master\nУслуга: :service\nВремя: :time\nАдрес: :address\n\nЖдём вас!",
        'max' => "🔔 До вашего визита осталось :hours ч.\n\nМастер: :master\nУслуга: :service\nВремя: :time\nАдрес: :address\n\nЖдём вас!",
    ],
    'tariff_limit_reached' => 'Мастер достиг лимита записей на этот месяц. Обновите тариф для продолжения.',
];
