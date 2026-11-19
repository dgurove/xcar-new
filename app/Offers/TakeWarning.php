<?php

namespace App\Offers;

/**
 * Предупреждение перед «Беру в работу» (19.11.2026, владелец: «слишком часты случаи, когда менеджеры на шару эту кнопку нажимают,
 * а потом говорят „ой, я случайно нажал, не буду покупать“»; текст — его, дословно): везде, где менеджер берёт ТС, — шаг сделки и
 * гаража (`cabinet/deals/exits`), кнопки в чате, на полке, в строке и в вопросе при входе (`x-deal.ask-action`), доп. просьба
 * (`cabinet/deals/extra-ask`), Telegram (`UpdateHandler::turn|extra`). Одна дверь — своих копий текста в видах нет.
 */
final class TakeWarning
{
    public const TITLE = 'Берёте в работу?';

    /** После согласия, в доп. просьбе: «Всё в порядке, берём». */
    public const TITLE_AFTER = 'Берёте после ответа поставщика?';

    public const TEXT = "После подтверждения отказаться от выкупа нельзя.\n\n"
        ."ТС продаётся как есть: в нынешнем состоянии и комплектации, со всеми повреждениями и недостатками.\n\n"
        .'После нажатия на эту кнопку мы сразу сообщаем поставщику, что выкупаем это ТС. Проверьте фото и описание до подтверждения.';

    public const OK = 'Да, беру в работу';

    public const CANCEL = 'Ещё подумаю';

    /**
     * Атрибуты кнопки для `confirm.js` (заголовок, абзацы, «Да» и «Ещё подумаю» — с нажатой кнопки).
     *
     * @return array<string, string>
     */
    public static function attrs(bool $after = false): array
    {
        return ['data-turbo-confirm' => $after ? self::TITLE_AFTER : self::TITLE, 'data-turbo-confirm-text' => self::TEXT,
            'data-turbo-confirm-label' => self::OK, 'data-turbo-confirm-cancel' => self::CANCEL];
    }

    /** Переспрос в Telegram: вопрос и текст одним сообщением. */
    public static function telegram(bool $after = false): string
    {
        return ($after ? self::TITLE_AFTER : self::TITLE)."\n\n".self::TEXT;
    }
}
