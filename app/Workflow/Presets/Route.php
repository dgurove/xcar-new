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
 * Т-Страхование присылает контакты владельца автомобиля, Альфа и Совкомбанк — счёт
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
                'name' => 'Поставщику: уведомление о покупке',
                'subject' => 'Покупка {{ car }}, убыток {{ claim_ref }}',
                'body' => "Здравствуйте!\n\nСообщаем о готовности приобрести {{ car }}, VIN {{ vin }}, убыток {{ claim_ref }}.\nПокупатель определён. Просим подтвердить продажу и готовность автомобиля к передаче.",
            ],
            'confirmed' => [
                'name' => 'Поставщику: подтверждение покупки',
                'subject' => 'Подтверждение покупки {{ car }}, убыток {{ claim_ref }}',
                'body' => "Здравствуйте!\n\nПодтверждаем покупку {{ car }}, убыток {{ claim_ref }}.\nПокупатель готов к сделке. Просим сообщить дальнейший порядок оформления.",
            ],
            'payment' => [
                'name' => 'Поставщику: платёжное поручение',
                'subject' => 'Оплата {{ car }}, убыток {{ claim_ref }}',
                'body' => "Здравствуйте!\n\nСчёт за {{ car }}, убыток {{ claim_ref }}, оплачен на сумму {{ price }}.\nПлатёжное поручение во вложении. Просим подтвердить поступление средств.",
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
            'sale' => ['name' => 'Приём подтверждений', 'text' => 'Автомобиль размещён в каталоге, идёт приём подтверждений.'],
            'agreement' => ['name' => 'Согласование с поставщиком', 'text' => 'Уведомили поставщика о покупке и ждём его ответа.'],
            'pickup' => ['name' => 'Получение автомобиля', 'text' => 'Поставщик передал контакты владельца. Заберите автомобиль и оформите договор купли-продажи.'],
            'payment' => ['name' => 'Оплата', 'text' => 'Поставщик выставил счёт. После подтверждения оплаты перейдём к документам.'],
            'signing' => ['name' => 'Оформление документов', 'text' => 'Осталось подписать документы и передать их поставщику.'],
            'won' => ['name' => 'Сделка закрыта', 'text' => 'Документы получены, сделка закрыта. Благодарим за сотрудничество.'],
            'declined' => ['name' => 'Отказ поставщика', 'text' => 'Поставщик снял автомобиль с продажи. Сделка не состоится.'],
            'nobody' => ['name' => 'Подтверждений не поступило', 'text' => 'Срок приёма подтверждений истёк, подтверждений не поступило.'],
        ];
    }

    /**
     * Общее начало: письмо, приём, выбор, «берём», подтверждение менеджера.
     *
     * Таймер закрытия приёма ведёт не в архив, а на выбор: при живых, ещё не
     * принятых подтверждениях он сжёг бы их. В архив (или к покупателю от
     * страховой) — кнопкой с выбора.
     *
     * @param  string  $nobody  куда уводит «подтверждений нет»
     * @param  list<array>|null  $confirm  кнопки подтверждения менеджера, если не две обычные
     * @param  list<array>  $draftExits  что ещё уводит с черновика
     */
    protected function head(string $nobody = 'no_bids', ?array $confirm = null, array $draftExits = [], string $draftDeadline = 'own'): array
    {
        return [
            'draft' => [
                'name' => 'Черновик', 'block' => 'sale', 'waits_for' => 'us', 'deadline_source' => $draftDeadline, 'offer_state' => 'draft',
                'exits' => [['Опубликовать', 'staff', 'bidding'], ...$draftExits],
            ],
            'bidding' => [
                // Срок не свой: часы идут по bids_close_at, тому же, что видит покупатель.
                'name' => 'Приём подтверждений', 'block' => 'sale', 'waits_for' => 'manager', 'limit_minutes' => 3 * self::DAY, 'deadline_source' => 'bids_close', 'offer_state' => 'open',
                'exits' => [['Подтверждение принято', 'staff', 'claimed'], ['Срок приёма истёк', 'timer', 'choosing']],
            ],
            'choosing' => [
                'name' => 'Выбор подтверждения', 'block' => 'sale', 'waits_for' => 'us', 'limit_minutes' => self::DAY,
                'exits' => [['Подтверждение принято', 'staff', 'claimed'], ['Подтверждений нет', 'staff', $nobody], ['Возобновить приём', 'staff', 'bidding']],
            ],
            'claimed' => [
                'name' => 'Уведомили поставщика о покупке', 'block' => 'agreement', 'waits_for' => 'supplier', 'limit_minutes' => self::DAY, 'offer_state' => 'sold', 'letter' => 'claimed',
                'exits' => [['Поставщик согласовал', 'staff', 'manager_confirm'], ['Поставщик отказал', 'staff', 'supplier_declined']],
            ],
            'manager_confirm' => [
                // «Согласие», а не «подтверждение»: подтверждением менеджер уже назвал цену.
                'name' => 'Согласие менеджера', 'block' => 'agreement', 'waits_for' => 'manager', 'limit_minutes' => 240,
                'ask_title' => 'Подтвердите покупку', 'ask_text' => 'Поставщик согласовал продажу автомобиля по Вашей цене. Подтвердите покупку или откажитесь от неё.',
                // Отказ — обычный исход в приём: вход туда отменяет сделку и отклоняет подтверждение.
                'exits' => $confirm ?? [['Покупаю', 'manager', 'confirmed'], ['Отказываюсь', 'manager', 'bidding']],
            ],
        ];
    }

    /** Подтвердили поставщику — этап ветки, а не общего начала: у Совкомбанка их два. */
    protected function confirmed(string $after, string $suffix = ''): array
    {
        return [
            'name' => 'Подтвердили покупку поставщику', 'block' => 'agreement'.$suffix, 'waits_for' => 'supplier', 'limit_minutes' => self::DAY, 'letter' => 'confirmed',
            'exits' => [['Ответ поставщика получен', 'staff', $after]],
        ];
    }

    /** Чем маршрут кончается: этапы без исходов. Что вышло — по состоянию оффера. */
    protected function tail(): array
    {
        return [
            'closed_won' => ['name' => 'Сделка закрыта', 'block' => 'won', 'waits_for' => 'nobody', 'offer_state' => 'delivered'],
            'supplier_declined' => ['name' => 'Отказ поставщика', 'block' => 'declined', 'waits_for' => 'nobody', 'offer_state' => 'cancelled'],
            'no_bids' => ['name' => 'Подтверждений не поступило', 'block' => 'nobody', 'waits_for' => 'nobody', 'offer_state' => 'archived'],
        ];
    }

    /** Счёт менеджеру и проверка оплаты. */
    protected function invoiceSegment(string $next, string $suffix = ''): array
    {
        return [
            'invoice'.$suffix => [
                'name' => 'Счёт выставлен менеджеру', 'block' => 'payment'.$suffix, 'waits_for' => 'manager', 'limit_minutes' => 3 * self::DAY, 'asks' => 'document',
                'ask_title' => 'Оплатите счёт', 'ask_text' => 'Поставщик выставил счёт. Оплатите его и приложите платёжное поручение — без него оплата не будет подтверждена.',
                'staff_fields' => [['label' => 'Номер счёта'], ['label' => 'Сумма'], ['label' => 'Реквизиты', 'type' => 'textarea']],
                'exits' => [['Платёжное поручение приложено', 'manager', 'payment_check'.$suffix]],
            ],
            'payment_check'.$suffix => [
                'name' => 'Подтверждение оплаты поставщиком', 'block' => 'payment'.$suffix, 'waits_for' => 'supplier', 'limit_minutes' => 2 * self::DAY, 'letter' => 'payment',
                'exits' => [['Оплата получена', 'staff', $next], ['Оплата не поступила', 'staff', 'invoice'.$suffix]],
            ],
        ];
    }

    /** Где подписывает покупатель: в Москве — приём, иначе оригиналы СДЭКом. */
    protected function signingSegment(string $next, string $suffix = '', string $intro = ''): array
    {
        return [
            'signing_place'.$suffix => [
                'name' => 'Место подписания документов', 'block' => 'signing'.$suffix, 'waits_for' => 'manager', 'limit_minutes' => self::DAY,
                'ask_title' => 'Укажите место подписания', 'ask_text' => $intro.'Сообщите, где покупатель подпишет документы: в Москве запишем его на приём, в другом городе направим оригиналы курьерской службой.',
                'exits' => [['В Москве', 'manager', 'appointment'.$suffix], ['В другом городе', 'manager', 'sdek_address'.$suffix]],
            ],
            'appointment'.$suffix => [
                'name' => 'Запись на приём', 'block' => 'signing'.$suffix, 'waits_for' => 'us', 'limit_minutes' => self::DAY,
                'exits' => [['Приём назначен', 'staff', 'signing'.$suffix]],
            ],
            'sdek_address'.$suffix => [
                'name' => 'Адрес для отправки оригиналов', 'block' => 'signing'.$suffix, 'waits_for' => 'manager', 'limit_minutes' => self::DAY, 'asks' => 'fields',
                'ask_title' => 'Укажите адрес для отправки оригиналов', 'ask_text' => 'Сообщите адрес и получателя — направим оригиналы документов курьерской службой СДЭК.',
                'fields' => [['label' => 'Адрес и получатель', 'type' => 'textarea']],
                'exits' => [['Адрес получен', 'manager', 'sending'.$suffix]],
            ],
            'sending'.$suffix => [
                'name' => 'Отправка оригиналов', 'block' => 'signing'.$suffix, 'waits_for' => 'us', 'limit_minutes' => 2 * self::DAY,
                'exits' => [['Отправлено', 'staff', 'signing'.$suffix]],
            ],
            'signing'.$suffix => [
                'name' => 'Подписание документов', 'block' => 'signing'.$suffix, 'waits_for' => 'manager', 'limit_minutes' => 5 * self::DAY, 'asks' => 'document',
                'ask_title' => 'Подпишите и передайте документы', 'ask_text' => 'Покупателю необходимо подписать документы и передать их поставщику. После этого приложите подписанный договор.',
                // Одно поле на обе дороги: пустое не показывается, менеджер видит либо приём, либо трек.
                'staff_fields' => [['label' => 'Дата приёма'], ['label' => 'Адрес приёма', 'type' => 'textarea'], ['label' => 'Трек-номер отправления']],
                'exits' => [['Документы переданы', 'manager', $next]],
            ],
        ];
    }

    /** Передача автомобиля: он стоит у нас, и отдаёт его кто-то из нас. */
    protected function releaseSegment(string $next, string $suffix = ''): array
    {
        return [
            'release'.$suffix => [
                'name' => 'Передача автомобиля покупателю', 'block' => 'handover'.$suffix, 'waits_for' => 'us', 'limit_minutes' => 3 * self::DAY,
                'staff_fields' => [['label' => 'Кто получил автомобиль'], ['label' => 'Дата передачи']],
                'exits' => [['Автомобиль передан', 'staff', $next]],
            ],
        ];
    }
}
