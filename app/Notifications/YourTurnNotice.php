<?php

namespace App\Notifications;

use App\Offers\ExtraWant;
use App\Support\Ago;
use App\Telegram\Text;
use App\Workflow\Outcome;
use App\Workflow\Requirement;

/** Менеджеру: сделка ждёт его ответа. */
final class YourTurnNotice extends Notice
{
    public function __construct(private Requirement $requirement) {}

    /**
     * Просьбу закрыли раньше, чем очередь дошла (ответили за менеджера, «Уже согласился» у «Спросить менеджера»,
     * 28.10.2026) — не шлём: иначе ему «Беру в работу?» о том, на что уже ответили.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return Requirement::whereKey($this->requirement->id)->whereNull('done_at')->exists() && parent::shouldSend($notifiable, $channel);
    }

    /**
     * Дело шага приходит менеджеру и сообщением в чат сделки (`StepAsk::post`), а оно — в Telegram целиком (`ChatNotice`, 16.11.2026):
     * второе сообщение об одном и том же в Telegram не шлём. Кроме согласия «Берём в работу?» — у этого кнопки «Беру в работу»,
     * «Отказываюсь» и «Напомнить через…» (и владелец 06.11: «чтобы точно увидели»).
     */
    public function via(\App\Users\User $user): array
    {
        $via = parent::via($user);
        $deal = $this->requirement->deal;
        $inChat = ! $this->consent() && $deal?->buyer && $deal->offer?->chatOpenFor($deal->buyer);

        return $inChat ? array_values(array_diff($via, [TelegramChannel::class])) : $via;
    }

    public function label(): string
    {
        return 'Ход в сделке';
    }

    public function title(): string
    {
        return $this->requirement->title.', № '.$this->requirement->offer->number;
    }

    /**
     * Без описания этапа и срока (18.11.2026, владелец: «описания убери везде — и из уведомлений, и из сообщений, и из телеги,
     * просто по делу пишем»; «убери сроки»): заголовок — само дело, этого достаточно.
     */
    public function text(): ?string
    {
        return null;
    }

    public function href(): string
    {
        return $this->requirement->deal?->href() ?? "/deals/{$this->requirement->deal_id}";
    }

    public function offerNumber(): ?int
    {
        return $this->requirement->offer->number;
    }

    public function category(): string
    {
        return 'deals';
    }

    public function critical(): bool
    {
        return true;
    }

    /** Согласие взять сделку (Альфа) — со звуком до нажатия: после письма поставщику «актуально» отказаться нельзя. */
    public function important(): bool
    {
        return $this->consent();
    }

    public function toTelegram(): ?array
    {
        $r = $this->requirement;

        // Заголовок — что сделать, а не «Ваш ход» (10.10.2026, владелец: проверить формулировки в Telegram).
        return ['title' => self::telegramTitle($r), 'lines' => self::telegramLines($r), 'button' => 'Открыть сделку',
            // Согласие — кнопками прямо в сообщении (27.10.2026, владелец), ответ — `UpdateHandler::turn`.
            ...($this->consent() ? ['keyboard' => self::consentButtons($r)] : [])];
    }

    public static function telegramTitle(Requirement $r): string
    {
        return $r->title.': '.$r->offer->titleWithYear();
    }

    /** @return list<string|null> */
    public static function telegramLines(Requirement $r): array
    {
        return Text::lines($r->offer);
    }

    /**
     * Ряды кнопок согласия (19.11.2026, начальник): «Беру в работу» первым рядом, под ним «Отказываюсь» и «Напомнить через…» —
     * как на странице сделки; кнопки исхода — только у тех, что есть на этом этапе. Ответ — `UpdateHandler::turn`.
     *
     * @return list<list<array{text: string, callback_data: string}>>
     */
    public static function consentButtons(Requirement $r): array
    {
        // «Доп. запрос…» (27.11.2026, `ExtraWant`) — рядом с «Беру в работу» ссылкой на шторку галок на сайте (`?want=1`): галки
        // и комментарий в Telegram не вводим, как и цены.
        $want = $r->deal && ExtraWant::can($r->deal, $r->deal->buyer) && ($url = $r->deal->personUrl())
            ? ['text' => 'Доп. запрос…', 'url' => $url.(str_contains($url, '?') ? '&' : '?').'want=1'] : null;

        return array_values(array_filter([
            self::consentExit($r, 'take') ? array_values(array_filter([['text' => 'Беру в работу', 'callback_data' => "turn:{$r->id}:take"], $want])) : null,
            array_values(array_filter([
                self::consentExit($r, 'drop') ? ['text' => 'Отказываюсь', 'callback_data' => "turn:{$r->id}:drop"] : null,
                ['text' => 'Напомнить через…', 'callback_data' => "turn:{$r->id}:remind"],
            ])),
        ]));
    }

    /** Исход согласия: take — «Беру в работу», drop — «Отказываюсь». */
    public static function consentExit(Requirement $r, string $which): ?Outcome
    {
        $exits = $r->deal ? $r->deal->managerExits($r->stage) : collect();

        return $exits->first(fn (Outcome $e) => $which === 'take' ? $e->takes() : $e->isRetreat());
    }

    private function consent(): bool
    {
        return (bool) $this->requirement->stage?->asksConsent();
    }

    public function speaksRoute(): bool
    {
        return true;
    }
}
