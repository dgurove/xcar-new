<?php

namespace App\Workflow\Presets;

/**
 * Описание маршрута данными: блоки, этапы по ключам, письма поставщику.
 *
 * Это и настройка, и приёмка модели: если процесс, рассказанный владельцем,
 * не выражается здесь данными, чинить надо модель, а не описывать частный
 * случай в коде. Ключи — только здесь, чтобы сцепить исходы; в базу они не
 * попадают.
 *
 * До подтверждения поставщику все продажи совпадают, дальше расходятся:
 * Т-Страхование присылает контакты хозяина машины, Альфа и Совкомбанк — счёт
 * на оплату и развилку «Москва или нет».
 *
 * Строка этапа: name, block, waits_for, limit_minutes, deadline_source,
 * offer_state, car_place, ask_title, ask_text, asks, fields, staff_fields,
 * letter, exits — [[label, actor, to], …].
 */
abstract class Route
{
    protected const DAY = 1440;

    /** @return array<string, array{name: string, text: string}> */
    abstract public function blocks(): array;

    /** @return array<string, array<string, mixed>> */
    abstract public function stages(): array;

    /**
     * Письма поставщику по ключу: этап зовёт их полем `letter`. Собирает
     * этап, а отправляет человек — кнопкой на карточке.
     *
     * @return array<string, array{name: string, subject: string, body: string}>
     */
    public function letters(): array
    {
        return [
            'claimed' => [
                'name' => 'Поставщику: берём машину',
                'subject' => 'Берём {{ car }}, {{ claim_ref }}',
                'body' => "Здравствуйте!\n\nГотовы забрать {{ car }}, VIN {{ vin }}, убыток {{ claim_ref }}.\nПокупатель есть, ждём Вашего подтверждения и готовности машины.",
            ],
            'confirmed' => [
                'name' => 'Поставщику: подтверждаем сделку',
                'subject' => 'Подтверждаем {{ car }}, {{ claim_ref }}',
                'body' => "Здравствуйте!\n\nПодтверждаем сделку по {{ car }}, убыток {{ claim_ref }}.\nПокупатель готов. Пришлите, пожалуйста, следующий шаг с Вашей стороны.",
            ],
            'payment' => [
                'name' => 'Поставщику: платёжное поручение',
                'subject' => 'Оплата по {{ car }}, {{ claim_ref }}',
                'body' => "Здравствуйте!\n\nСчёт по {{ car }}, убыток {{ claim_ref }}, оплачен на {{ price }}.\nПлатёжное поручение приложено. Подтвердите, пожалуйста, поступление.",
            ],
        ];
    }

    /**
     * Блоки продажи — то, что видит менеджер. Их шесть-восемь против
     * пятнадцати этапов: «согласуем с поставщиком» — одно положение дела, а
     * сколько писем мы внутри него написали, менеджера не касается.
     *
     * @return array<string, array{name: string, text: string}>
     */
    protected function saleBlocks(): array
    {
        return [
            'sale' => ['name' => 'Принимаем предложения', 'text' => 'Машина в каталоге. Ждём предложений по цене.'],
            'agreement' => ['name' => 'Согласуем с поставщиком', 'text' => 'Мы написали поставщику, что берём машину, и ведём с ним переписку.'],
            'pickup' => ['name' => 'Забираем машину', 'text' => 'Поставщик передал контакты хозяина. Осталось забрать машину и оформить договор.'],
            'payment' => ['name' => 'Оплата', 'text' => 'Поставщик выставил счёт. После оплаты подтвердим её и перейдём к документам.'],
            'signing' => ['name' => 'Подписание документов', 'text' => 'Осталось подписать документы и сдать их поставщику.'],
            'won' => ['name' => 'Сделка закрыта', 'text' => 'Документы получены, сделка закрыта. Спасибо.'],
            'declined' => ['name' => 'Поставщик отказал', 'text' => 'Поставщик снял машину с продажи. Сделка не состоится.'],
            'nobody' => ['name' => 'Никто не откликнулся', 'text' => 'Срок приёма предложений вышел, откликов не было.'],
        ];
    }

    /**
     * Общее начало: письмо, приём, выбор, «берём», подтверждение менеджера.
     *
     * Таймер закрытия приёма ведёт не в архив, а на выбор: при живых, ещё не
     * принятых ставках он сжёг бы их. В архив (или к покупателю от страховой)
     * — кнопкой с выбора.
     *
     * @param  string  $nobody  куда уводит «откликов нет»
     * @param  list<array>|null  $confirm  кнопки подтверждения менеджера, если не две обычные
     * @param  list<array>  $draftExits  что ещё уводит с черновика
     */
    protected function head(string $nobody = 'no_bids', ?array $confirm = null, array $draftExits = [], string $draftDeadline = 'own'): array
    {
        return [
            'draft' => [
                'name' => 'Черновик из письма', 'block' => 'sale', 'waits_for' => 'us', 'deadline_source' => $draftDeadline, 'offer_state' => 'draft',
                'exits' => [['Опубликовать', 'staff', 'bidding'], ...$draftExits],
            ],
            'bidding' => [
                // Срок не свой: часы идут по bids_close_at, тому же, что видит покупатель.
                'name' => 'Приём предложений', 'block' => 'sale', 'waits_for' => 'manager', 'limit_minutes' => 3 * self::DAY, 'deadline_source' => 'bids_close', 'offer_state' => 'open',
                'exits' => [['Выбрали предложение', 'staff', 'claimed'], ['Срок приёма вышел', 'timer', 'choosing']],
            ],
            'choosing' => [
                'name' => 'Выбираем предложение', 'block' => 'sale', 'waits_for' => 'us', 'limit_minutes' => self::DAY, 'offer_state' => 'closed',
                'exits' => [['Выбрали предложение', 'staff', 'claimed'], ['Откликов нет', 'staff', $nobody], ['Открыть приём снова', 'staff', 'bidding']],
            ],
            'claimed' => [
                'name' => 'Написали поставщику «берём»', 'block' => 'agreement', 'waits_for' => 'supplier', 'limit_minutes' => self::DAY, 'offer_state' => 'sold', 'letter' => 'claimed',
                'exits' => [['Согласны, машина готова', 'staff', 'manager_confirm'], ['Поставщик отказал', 'staff', 'supplier_declined']],
            ],
            'manager_confirm' => [
                'name' => 'Подтверждение менеджера', 'block' => 'agreement', 'waits_for' => 'manager', 'limit_minutes' => 240,
                'ask_title' => 'Подтвердите сделку', 'ask_text' => 'Поставщик готов отдать машину. Подтвердите, что берёте её.',
                // Отказ — обычный исход в приём: вход туда отменяет сделку и отклоняет ставку.
                'exits' => $confirm ?? [['Подтверждаю', 'manager', 'confirmed'], ['Отказываюсь', 'manager', 'bidding']],
            ],
        ];
    }

    /** Подтвердили поставщику — этап ветки, а не общего начала: у Совкомбанка их два. */
    protected function confirmed(string $after, string $suffix = ''): array
    {
        return [
            'name' => 'Подтвердили поставщику', 'block' => 'agreement'.$suffix, 'waits_for' => 'supplier', 'limit_minutes' => self::DAY, 'letter' => 'confirmed',
            'exits' => [['Поставщик прислал', 'staff', $after]],
        ];
    }

    /** Чем маршрут кончается: этапы без исходов. Что вышло — по состоянию оффера. */
    protected function tail(): array
    {
        return [
            'closed_won' => ['name' => 'Сделка закрыта', 'block' => 'won', 'waits_for' => 'nobody', 'offer_state' => 'delivered'],
            'supplier_declined' => ['name' => 'Поставщик отказал', 'block' => 'declined', 'waits_for' => 'nobody', 'offer_state' => 'cancelled'],
            'no_bids' => ['name' => 'Никто не откликнулся', 'block' => 'nobody', 'waits_for' => 'nobody', 'offer_state' => 'archived'],
        ];
    }

    /** Счёт менеджеру и проверка оплаты. */
    protected function invoiceSegment(string $next, string $suffix = ''): array
    {
        return [
            'invoice'.$suffix => [
                'name' => 'Счёт менеджеру', 'block' => 'payment'.$suffix, 'waits_for' => 'manager', 'limit_minutes' => 3 * self::DAY, 'asks' => 'document',
                'ask_title' => 'Оплатите счёт', 'ask_text' => 'Поставщик выставил счёт. Оплатите его и приложите платёжное поручение — без него мы не сможем подтвердить оплату.',
                'staff_fields' => [['label' => 'Номер счёта'], ['label' => 'Сумма к оплате'], ['label' => 'Реквизиты', 'type' => 'textarea']],
                'exits' => [['Платёжка приложена', 'manager', 'payment_check'.$suffix]],
            ],
            'payment_check'.$suffix => [
                'name' => 'Подтверждаем оплату', 'block' => 'payment'.$suffix, 'waits_for' => 'supplier', 'limit_minutes' => 2 * self::DAY, 'letter' => 'payment',
                'exits' => [['Деньги пришли', 'staff', $next], ['Оплата не прошла', 'staff', 'invoice'.$suffix]],
            ],
        ];
    }

    /** Где подписывает покупатель: в Москве — приём, иначе оригиналы СДЭКом. */
    protected function signingSegment(string $next, string $suffix = '', string $intro = ''): array
    {
        return [
            'signing_place'.$suffix => [
                'name' => 'Где подписывает покупатель', 'block' => 'signing'.$suffix, 'waits_for' => 'manager', 'limit_minutes' => self::DAY,
                'ask_title' => 'Где подписывает покупатель', 'ask_text' => $intro.'Скажите, где покупатель будет подписывать документы: в Москве мы запишем его на приём, в другом городе отправим оригиналы.',
                'exits' => [['В Москве', 'manager', 'appointment'.$suffix], ['В другом городе', 'manager', 'sdek_address'.$suffix]],
            ],
            'appointment'.$suffix => [
                'name' => 'Записываем на приём', 'block' => 'signing'.$suffix, 'waits_for' => 'us', 'limit_minutes' => self::DAY,
                'exits' => [['Записали', 'staff', 'signing'.$suffix]],
            ],
            'sdek_address'.$suffix => [
                'name' => 'Адрес для оригиналов', 'block' => 'signing'.$suffix, 'waits_for' => 'manager', 'limit_minutes' => self::DAY, 'asks' => 'fields',
                'ask_title' => 'Куда отправить оригиналы', 'ask_text' => 'Пришлите адрес и получателя: отправим оригиналы документов СДЭКом.',
                'fields' => [['label' => 'Адрес и получатель', 'type' => 'textarea']],
                'exits' => [['Адрес получен', 'manager', 'sending'.$suffix]],
            ],
            'sending'.$suffix => [
                'name' => 'Отправляем оригиналы', 'block' => 'signing'.$suffix, 'waits_for' => 'us', 'limit_minutes' => 2 * self::DAY,
                'exits' => [['Отправили', 'staff', 'signing'.$suffix]],
            ],
            'signing'.$suffix => [
                'name' => 'Подписание документов', 'block' => 'signing'.$suffix, 'waits_for' => 'manager', 'limit_minutes' => 5 * self::DAY, 'asks' => 'document',
                'ask_title' => 'Подпишите и сдайте документы', 'ask_text' => 'Покупателю нужно подписать документы и сдать их поставщику. После этого приложите подписанный договор.',
                // Одно поле на обе дороги: пустое не показывается, менеджер видит либо приём, либо трек.
                'staff_fields' => [['label' => 'Приём назначен на'], ['label' => 'Адрес приёма', 'type' => 'textarea'], ['label' => 'Трек отправления']],
                'exits' => [['Документы сданы', 'manager', $next]],
            ],
        ];
    }

    /** Передача машины: она стоит у нас, и отдаёт её кто-то из нас. */
    protected function releaseSegment(string $next, string $suffix = ''): array
    {
        return [
            'release'.$suffix => [
                'name' => 'Передаём машину покупателю', 'block' => 'handover'.$suffix, 'waits_for' => 'us', 'limit_minutes' => 3 * self::DAY,
                'staff_fields' => [['label' => 'Кто забрал машину'], ['label' => 'Дата передачи']],
                'exits' => [['Машина передана', 'staff', $next]],
            ],
        ];
    }
}
