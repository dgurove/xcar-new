<?php

namespace App\Telegram\Offers;

use App\Live\Publisher;
use App\Live\Topics;
use App\Offers\Favorite;
use App\Offers\Offer;
use App\Support\Plural;
use App\Support\Surface;
use App\Telegram\StartLink;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Разговор бота предложений по образцу «Дайвинчика» (владелец 03.10.2026): анонс «Опубликовано N предложений,
 * показать их?», «✨🔍» и карточки по одной (➡️ дальше, 📌 в избранное, 💬 вопрос, 💤 потом), в конце — «Вы увидели
 * все предложения на сегодня», главное меню цифрами. Тексты — слово в слово владельца, без точки в конце.
 *
 * Одна запись дорожки — обновление Telegram или «часы» (анонс, 13:00, напоминание) по одному человеку: в чат пишет
 * один процесс, клавиатуры не путаются. Исключение наружу не выходит — одно битое обновление не останавливает бота.
 */
final class Handler
{
    public function __construct(private OffersBot $bot, private Questions $questions, private Invites $invites, private Announce $announce, private Publisher $publish) {}

    /** Чат, по которому дорожка раскладывает записи: один человек — всегда одна дорожка. */
    public static function chatOf(array $item): int
    {
        $u = $item['update'] ?? [];

        return (int) ($item['chat_id'] ?? $u['message']['chat']['id'] ?? $u['callback_query']['message']['chat']['id'] ?? $u['my_chat_member']['chat']['id'] ?? 0);
    }

    /** @param  array<string, mixed>  $item  ['update' => …] или ['timer' => announce|morning|remind, 'chat_id' => …] */
    public function handle(array $item): void
    {
        try {
            if (is_array($item['update'] ?? null)) {
                $this->update($item['update']);
            } elseif (is_string($item['timer'] ?? null) && ($sub = Subscriber::with('user')->where('chat_id', self::chatOf($item))->first())) {
                $this->timer($item['timer'], $sub);
            }
        } catch (Throwable $e) {
            Log::error('Бот предложений: запись не разобрана', ['item' => $item['update']['update_id'] ?? $item['timer'] ?? null, 'error' => $e->getMessage()]);
        }
    }

    private function update(array $update): void
    {
        if (is_array($update['my_chat_member'] ?? null)) {
            $this->member($update['my_chat_member']);
        } elseif (is_array($update['callback_query'] ?? null)) {
            $this->press($update['callback_query']);
        } elseif (is_array($update['message'] ?? null) && ($update['message']['chat']['type'] ?? '') === 'private') {
            $this->message($update['message']);
        }
    }

    /** Остановил бота — писать нельзя; запустил снова — можно (подписка та же). */
    private function member(array $m): void
    {
        if (($m['chat']['type'] ?? '') !== 'private') {
            return;
        }
        $status = (string) ($m['new_chat_member']['status'] ?? '');
        Subscriber::where('chat_id', (int) $m['chat']['id'])->update(['blocked_at' => in_array($status, ['kicked', 'left'], true) ? now() : null]);
    }

    private function press(array $q): void
    {
        $chatId = (int) ($q['message']['chat']['id'] ?? 0);
        $sub = Subscriber::with('user')->where('chat_id', $chatId)->first();
        $this->bot->answer((string) ($q['id'] ?? ''), '');
        if ($sub && str_starts_with((string) ($q['data'] ?? ''), 'invite:')) {
            $this->invites->press($sub, (string) $q['data']);
        }
    }

    private function message(array $msg): void
    {
        $chatId = (int) $msg['chat']['id'];
        $text = trim((string) ($msg['text'] ?? ''));

        if (preg_match('~^/start(?:@\w+)?(?:\s+(\S+))?$~', $text, $m) === 1) {
            $this->start($chatId, $msg['from'] ?? [], $m[1] ?? null);

            return;
        }
        $sub = Subscriber::with(['user', 'offer'])->where('chat_id', $chatId)->first() ?? $this->recognize($chatId, $msg['from'] ?? []);
        if (! $sub || ! self::eligible($sub->user)) {
            $this->stranger($chatId);

            return;
        }
        // Реплай на вопрос или ответ по предложению — в тот же чат xcar, где бы человек ни был в разговоре.
        if (($replyTo = (int) ($msg['reply_to_message']['message_id'] ?? 0)) && $this->questions->reply($sub, $replyTo, $msg)) {
            return;
        }
        if ($text === Keys::MENU) {
            $this->menu($sub);

            return;
        }

        match ($sub->mode) {
            Subscriber::ASKING => $this->asking($sub, $msg, $text),
            Subscriber::INVITE => $this->invites->label($sub, $text) || $this->unknown($sub),
            Subscriber::PROMPT => match ($text) {
                Keys::SHOW, '1' => $this->feed($sub),
                Keys::LATER, '2' => $this->later($sub),
                default => $this->unknown($sub),
            },
            Subscriber::FEED => match ($text) {
                Keys::NEXT => $this->next($sub, 'next'),
                Keys::PIN => $this->pin($sub),
                Keys::ASK => $this->ask($sub),
                Keys::SLEEP => $this->sleep($sub),
                default => $this->unknown($sub),
            },
            default => $this->choose($sub, $text),
        };
    }

    /**
     * Главное меню: подписанному три пункта, отписавшемуся — два, «пригласи» всегда последним. Только свои кнопки:
     * «💤 2» со старой клавиатуры анонса — это «напомнить», а не «2. Я больше не хочу получать».
     */
    private function choose(Subscriber $sub, string $text): void
    {
        match (true) {
            in_array($text, ['1 🚀', '1', Keys::SHOW], true) => $this->feed($sub),
            $text === Keys::LATER => $this->later($sub),
            $text === '2' && $sub->isSubscribed() => $this->unsubscribe($sub),
            ($text === '3' && $sub->isSubscribed()) || ($text === '2' && ! $sub->isSubscribed()) => $this->invites->show($sub),
            default => $this->unknown($sub),
        };
    }

    // ———————————————————————————————— вход

    /**
     * `/start L…` — подписанная ссылка с сайта; голый `/start` — человек уже привязан к основному боту (id личного
     * чата у всех ботов один) или уже подписан. Повторный `/start` возвращает подписку и снимает «остановил».
     */
    private function start(int $chatId, array $from, ?string $payload): void
    {
        $parsed = $payload ? StartLink::parse($payload) : null;
        $user = match (true) {
            $parsed !== null && $parsed[0] === 'link' && ! $parsed[2] => User::find($parsed[1]),
            default => Subscriber::where('chat_id', $chatId)->first()?->user ?? User::where('telegram_chat_id', (int) ($from['id'] ?? 0))->first(),
        };
        if (! $user || ! self::eligible($user)) {
            $this->stranger($chatId);

            return;
        }
        $sub = $this->subscribe($user, $chatId, resubscribe: true);
        $name = e($user->firstName());
        $this->bot->quietly(fn () => $this->bot->say($chatId, $user->isAdmin()
            ? "Готово, {$name}. Сюда будут приходить вопросы менеджеров по предложениям"
            : "Готово, {$name}. Каждый день в 16:00 пришлю новые предложения"));
        $this->menu($sub);
    }

    /** Написал без `/start`, но это менеджер, привязанный к основному боту, — узнаём сам. */
    private function recognize(int $chatId, array $from): ?Subscriber
    {
        $user = User::where('telegram_chat_id', (int) ($from['id'] ?? 0))->first();

        return $user && self::eligible($user) ? $this->subscribe($user, $chatId, resubscribe: false) : null;
    }

    private function subscribe(User $user, int $chatId, bool $resubscribe): Subscriber
    {
        // Один чат — один аккаунт: чат, бывший у другого, переходит к этому.
        Subscriber::where('chat_id', $chatId)->where('user_id', '!=', $user->id)->delete();
        $sub = Subscriber::firstOrNew(['user_id' => $user->id]);
        $fresh = ! $sub->exists;
        $sub->forceFill(['chat_id' => $chatId, 'blocked_at' => null] + ($resubscribe ? ['muted_at' => null] : [])
            // Новому — без анонса того, что вышло до подписки: смотреть это он может из меню.
            + ($fresh ? ['announced_at' => now(), 'mode' => Subscriber::MENU] : []))->save();
        $sub->setRelation('user', $user);
        ($this->publish)(Topics::user($user), 'offers-bot', ['state' => 'linked']);

        return $sub;
    }

    /** Кому бот служит: допущенные менеджеры и админы, без демо и закрытых. */
    public static function eligible(?User $user): bool
    {
        return $user !== null && ($user->isManager() || $user->isAdmin()) && $user->isApproved() && ! $user->isRejected() && ! $user->is_demo;
    }

    private function stranger(int $chatId): void
    {
        $this->bot->quietly(fn () => $this->bot->say($chatId, 'Это бот для менеджеров XCar. Подключите его на xcar.ru в «Предложениях»',
            Keys::inline([[['text' => 'Открыть XCar', 'url' => Surface::Site->url('/offers')]]])));
    }

    // ———————————————————————————————— меню

    public function menu(Subscriber $sub): void
    {
        $text = $sub->isSubscribed()
            ? "1. Смотреть предложения\n2. Я больше не хочу получать предложения\n***\n3. Пригласи покупателей — будь в топе ⭐️"
            : "1. Смотреть предложения\n***\n2. Пригласи покупателей — будь в топе ⭐️";
        $this->bot->quietly(fn () => $this->bot->say($sub->chat_id, $text, Keys::menu($sub->isSubscribed())));
        $sub->moveTo(Subscriber::MENU);
    }

    private function unsubscribe(Subscriber $sub): void
    {
        $sub->forceFill(['muted_at' => now(), 'remind_at' => null])->save();
        $this->bot->quietly(fn () => $this->bot->say($sub->chat_id, 'Хорошо, больше не буду вам писать'));
        $this->menu($sub);
    }

    /** Не то, что ждали: как у «Дайвинчика» — и та же клавиатура ещё раз. */
    private function unknown(Subscriber $sub): bool
    {
        $keys = match ($sub->mode) {
            Subscriber::FEED => Keys::feed(),
            Subscriber::PROMPT => Keys::prompt(),
            Subscriber::ASKING => Keys::reply([[Keys::BACK]]),
            Subscriber::INVITE => Keys::reply([[Keys::NO_LABEL], [Keys::MENU]]),
            default => Keys::menu($sub->isSubscribed()),
        };
        $this->bot->quietly(fn () => $this->bot->say($sub->chat_id, 'Нет такого варианта ответа', $keys));

        return true;
    }

    // ———————————————————————————————— лента

    /** «Показать» и «1. Смотреть предложения»: «✨🔍» ставит клавиатуру карточек, следом — первая карточка. */
    private function feed(Subscriber $sub): void
    {
        $sub->forceFill(['remind_at' => null])->save();
        $offer = Feed::next($sub->user);
        if (! $offer) {
            $this->end($sub);

            return;
        }
        $this->bot->quietly(fn () => $this->bot->say($sub->chat_id, '✨🔍', Keys::feed()));
        $this->card($sub, $offer);
    }

    private function card(Subscriber $sub, Offer $offer): void
    {
        $user = $sub->user;
        $more = max(0, Feed::left($user) - 1);
        Feed::mark($user, $offer);
        $sub->moveTo(Subscriber::FEED, $offer->id);
        $caption = Card::caption($offer, $user, $more);
        $buttons = Card::buttons($offer, $this->bot->logsIn());
        // Запрет шеринга у предложения — и в Telegram не переслать и не сохранить.
        $extra = $offer->share_locked ? ['protect_content' => 'true'] : [];
        $photo = $offer->mainPhoto();
        $this->bot->quietly(fn () => $photo
            ? PhotoCache::send($this->bot, $sub->chat_id, $photo, $caption, $buttons, $extra)
            : $this->bot->say($sub->chat_id, $caption, $buttons, $extra));
    }

    private function next(Subscriber $sub, string $reaction): void
    {
        if ($sub->offer) {
            Feed::mark($sub->user, $sub->offer, $reaction);
        }
        $offer = Feed::next($sub->user);
        $offer ? $this->card($sub, $offer) : $this->end($sub);
    }

    /** 📌 — в избранное на сайте, «в избранное» — ссылкой на раздел, и сразу следующая. */
    private function pin(Subscriber $sub): void
    {
        $offer = $sub->offer;
        if ($offer && $offer->isVisibleTo($sub->user)) {
            Favorite::firstOrCreate(['user_id' => $sub->user_id, 'offer_id' => $offer->id]);
            $this->bot->quietly(fn () => $this->bot->say($sub->chat_id, 'Предложение добавлено <a href="'.e(Surface::Site->url('/account/favorites')).'">в избранное</a>'));
        }
        $this->next($sub, 'pin');
    }

    /** 💬 — вопрос админам по этому предложению: в чат по предложению (сайт и CRM «Чаты»). */
    private function ask(Subscriber $sub): void
    {
        $offer = $sub->offer;
        if (! $offer || ! $offer->chatOpenFor($sub->user)) {
            $this->bot->quietly(fn () => $this->bot->say($sub->chat_id, 'Чат по этому предложению закрыт'));

            return;
        }
        $sub->moveTo(Subscriber::ASKING, $offer->id);
        $this->bot->quietly(fn () => $this->bot->say($sub->chat_id, 'Напишите вопрос по '.e($offer->titleWithYear()), Keys::reply([[Keys::BACK]])));
    }

    private function asking(Subscriber $sub, array $msg, string $text): void
    {
        $offer = $sub->offer;
        if ($text === Keys::BACK || ! $offer) {
            $this->bot->quietly(fn () => $this->bot->say($sub->chat_id, '✨🔍', Keys::feed()));
            $offer ? $this->card($sub, $offer) : $this->next($sub, 'next');

            return;
        }
        if ($text === '') {
            $this->bot->quietly(fn () => $this->bot->say($sub->chat_id, 'Напишите вопрос текстом', Keys::reply([[Keys::BACK]])));

            return;
        }
        $this->questions->ask($sub, $offer, $text, (int) $msg['message_id']);
        Feed::mark($sub->user, $offer, 'ask');
        $this->bot->quietly(fn () => $this->bot->say($sub->chat_id, 'Вопрос отправлен, ответ придёт сюда', Keys::feed()));
        $this->next($sub, 'ask');
    }

    /** 💤 на карточке — «потом»: в меню. */
    private function sleep(Subscriber $sub): void
    {
        if ($sub->offer) {
            Feed::mark($sub->user, $sub->offer, 'sleep');
        }
        $this->bot->quietly(fn () => $this->bot->say($sub->chat_id, 'Подождем, когда будете готовы'));
        $this->menu($sub);
    }

    /**
     * Всё посмотрели. Нижнюю клавиатуру и кнопку под сообщением Telegram в одно сообщение не кладёт — «🏁» ставит
     * «Главное меню», как «✨🔍» ставит клавиатуру карточек.
     */
    private function end(Subscriber $sub): void
    {
        $this->bot->quietly(fn () => $this->bot->say($sub->chat_id, '🏁', Keys::toMenu()));
        $this->bot->quietly(fn () => $this->bot->say($sub->chat_id,
            'Вы увидели все предложения на сегодня. Перейдите на XCar, чтобы показать предложения своим покупателям, поделиться или подтвердить предложения',
            Keys::inline([[Keys::site('🌐 XCar', Card::url('/offers', $this->bot->logsIn()), $this->bot->logsIn())]])));
        $sub->moveTo(Subscriber::MENU);
    }

    /** «💤 2» под анонсом — напомнить через час. */
    private function later(Subscriber $sub): void
    {
        $sub->forceFill(['remind_at' => now()->addHour()])->save();
        $this->bot->quietly(fn () => $this->bot->say($sub->chat_id, 'Напомню через час'));
        $this->menu($sub);
    }

    // ———————————————————————————————— часы

    private function timer(string $kind, Subscriber $sub): void
    {
        if ($sub->blocked_at || ! self::eligible($sub->user)) {
            return;
        }
        match ($kind) {
            'morning' => $this->announce->morning($sub),
            'announce', 'remind' => $this->prompt($sub, $kind === 'remind'),
            default => null,
        };
    }

    /**
     * «Опубликовано N предложений, показать их?». Того, кто сейчас листает или пишет вопрос, не перебиваем —
     * клавиатуру не сбиваем; отметка не ставится, анонс дождётся, пока он выйдет (новое он и так увидит в ленте).
     */
    private function prompt(Subscriber $sub, bool $remind): void
    {
        // «Занят» — только пока он здесь: бросил ленту на середине и ушёл (10 минут тишины) — анонс приходит.
        if (in_array($sub->mode, [Subscriber::FEED, Subscriber::ASKING, Subscriber::INVITE], true) && $sub->updated_at?->gt(now()->subMinutes(10))) {
            return;
        }
        $fresh = $remind ? null : $this->announce->fresh($sub);
        $sub->forceFill($remind ? ['remind_at' => null] : ['announced_at' => now()])->save();
        $n = $remind ? Feed::left($sub->user) : $fresh;
        if (! $n || ($remind && $sub->muted_at)) {
            return;
        }
        $word = Plural::of($n, ['предложение', 'предложения', 'предложений']);
        $this->bot->quietly(fn () => $this->bot->say($sub->chat_id,
            "Опубликовано {$n} {$word}, показать ".($n === 1 ? 'его' : 'их')."?\n\n1. Показать\n2. Напомнить через 1 ч",
            Keys::prompt(), $sub->user->quietHours() ? ['disable_notification' => 'true'] : []));
        $sub->moveTo(Subscriber::PROMPT);
    }

    /** Для проверки руками (`offers-bot:poke`): сколько ещё в ленте. */
    public static function left(User $user): int
    {
        return Feed::left($user);
    }

    /** Отписаться и подписаться снова — с сайта (строка в «Предложениях» знает, подписан ли). */
    public static function subscribed(User $user): bool
    {
        return DB::connection('pgsql_async')->table('offer_bot_chats')->where('user_id', $user->id)->whereNull('blocked_at')->exists();
    }
}
