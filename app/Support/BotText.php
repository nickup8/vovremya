<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * Сборка текстов бота: подстановка переменных и опускание пустых строк.
 *
 * Пустое значение (null/'' ) в render() занимает в шаблоне собственную
 * строку («Телефон мастера: :master_phone», «Адрес: :address»,
 * «:instruction») и удаляется целиком — так пустой номер или адрес не
 * оставляют «висячей» строки. Пустые значения, делящие строку с другими
 * данными, шаблоном не используются.
 */
final class BotText
{
    /** Метка пустого значения: её наличие удаляет строку шаблона. */
    private const EMPTY_MARKER = "\x00";

    /**
     * Перевод ключа с подстановкой; пустые значения убирают свои строки.
     *
     * @param  array<string, mixed>  $replace
     */
    public static function render(string $key, array $replace = []): string
    {
        $replace = array_map(
            static fn ($value): mixed => ($value === null || $value === '') ? self::EMPTY_MARKER : $value,
            $replace,
        );

        $text = __($key, $replace);

        $text = preg_replace('/[^\n]*'.self::EMPTY_MARKER.'[^\n]*(?:\r?\n)?/', '', $text) ?? $text;

        return rtrim($text);
    }

    /**
     * Стоимость: один знак ₽ и читаемые разряды; ноль/минус — «—»
     * (действующая семантика «цена не указана», не «бесплатно»).
     */
    public static function price(float $price): string
    {
        if ($price <= 0) {
            return '—';
        }

        return number_format($price, 0, '.', ' ').' ₽';
    }

    /** Телефон мастера для показа в сообщении; '' — строка опускается. */
    public static function masterPhone(?User $master): string
    {
        return Phone::display($master?->phone);
    }

    /**
     * Общая инструкция A: с предложением позвонить только при наличии
     * телефона мастера.
     */
    public static function cabinetInstruction(?string $masterPhone): string
    {
        return ($masterPhone ?? '') !== ''
            ? (string) __('bot.instructions.cabinet')
            : (string) __('bot.instructions.cabinet_short');
    }

    /**
     * Фраза с предложением позвонить: без телефона мастера — ''.
     */
    public static function callToMaster(string $key, ?string $masterPhone): string
    {
        return ($masterPhone ?? '') !== '' ? (string) __($key) : '';
    }

    /**
     * Канонический URL виджета мастера (booking.widget, /book/{master}).
     * null — ссылка не формируется: мастера нет или у него нет slug'
     * (другой мастер не подставляется).
     */
    public static function bookingWidgetUrl(?User $master): ?string
    {
        $slug = $master?->master_slug;

        if ($slug === null || $slug === '') {
            return null;
        }

        return URL::route('booking.widget', ['master' => $slug]);
    }

    /**
     * Инструкция повторной записи после отмены мастером: показывается
     * только при наличии корректной ссылки на виджет (иначе нельзя
     * честно попросить нажать отсутствующую кнопку); от телефона
     * мастера не зависит.
     */
    public static function rebookInstruction(string $key, ?User $master): string
    {
        return self::bookingWidgetUrl($master) !== null ? (string) __($key) : '';
    }
}
