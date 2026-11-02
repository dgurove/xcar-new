<?php

namespace App\Telegram\Offers;

use App\Live\Publisher;
use App\Live\Topics;
use App\Offers\Offer;
use App\Telegram\Actions\LogoutTelegram;
use App\Telegram\Actions\UnlinkChat;
use App\Telegram\Bot;
use App\Telegram\ConnectLink;
use App\Telegram\StartLink;
use App\Users\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Бот предложений (03.11.2026, владелец: «не спрашивать, показывать или нет, а просто слать»): каждое новое предложение —
 * одной карточкой (`Card`), как только оно стало видно человеку (`Cards::due`). Меню, ленты и вопросов нет. Человек
 * пишет боту только `/start` (привязка, «Готово»), `/logout` и «остановить»; на любое другое — что бот присылает и
 * кнопка на сайт.
 *
 * Одна запись дорожки — обновление Telegram или карточка одному человеку: в чат пишет один процесс, порядок
 * сохраняется. Исключение наружу не выходит — одно битое обновление не останавливает бота.
 */
final class Handler
{
    public function __construct(private OffersBot $bot, private Publisher $publish) {}

    /** Чат, по которому дорожка раскладывает записи: один человек — всегда одна дорожка. */
    public static function chatOf(array $item): int
    {
        $u = $item['update'] ?? [];

        return (int) ($item['chat_id'] ?? $u['message']['chat']['id'] ?? $u['callback_query']['message']['chat']['id'] ?? $u['my_chat_member']['chat']['id'] ?? 0);
    }

    /** @param  array<string, mixed>  $item  ['update' => …] или ['chat_id' => …, 'offer_id' => …] — карточка */
    public function handle(array $item): void
    {
        try {
            if (is_array($item['update'] ?? null)) {
                $this->update($item['update']);
            } elseif (isset($item['offer_id'])) {
                $this->card(self::chatOf($item), (int) $item['offer_id']);
            }
        } catch (Throwable $e) {
            Log::error('Бот предложений: запись не разобрана', ['item' => $item['update']['update_id'] ?? $item['offer_id'] ?? null, 'error' => $e->getMessage()]);
        }
    }

    private function update(array $update): void
    {
        if (is_array($update['my_chat_member'] ?? null)) {
            $this->member($update['my_chat_member']);
        } elseif (is_array($update['callback_query'] ?? null)) {
            // Кнопки под старыми сообщениями (до 03.11.2026) больше ничего не делают — только снять часики.
            $this->bot->answer((string) ($update['callback_query']['id'] ?? ''), '');
        } elseif (is_array($update['message'] ?? null) && ($update['message']['chat']['type'] ?? '') === 'private') {
            $this->message($update['message']);
        }
    }

    /** Остановил бота — писать нельзя; запустил снова — можно, с этого момента (открытое за паузу не досылаем). */
    private function member(array $m): void
    {
        if (($m['chat']['type'] ?? '') !== 'private') {
            return;
        }
        $stopped = in_array((string) ($m['new_chat_member']['status'] ?? ''), ['kicked', 'left'], true);
        Subscriber::where('chat_id', (int) $m['chat']['id'])->update($stopped ? ['blocked_at' => now()] : ['blocked_at' => null, 'cards_since' => now()]);
    }

    private function message(array $msg): void
    {
        $chatId = (int) $msg['chat']['id'];
        $text = trim((string) ($msg['text'] ?? ''));

        if (preg_match('~^/logout(?:@\w+)?$~', $text) === 1) {
            $this->logout($chatId);

            return;
        }
        if (preg_match('~^/start(?:@\w+)?(?:\s+(\S+))?$~', $text, $m) === 1) {
            $this->start($chatId, $msg['from'] ?? [], $m[1] ?? null);

            return;
        }
        $sub = Subscriber::with('user')->where('chat_id', $chatId)->first() ?? $this->recognize($chatId, $msg['from'] ?? []);
        if (! $sub || ! Subscriber::eligible($sub->user)) {
            // Привязан к основному боту, но этот бот ему не служит (покупатель), — не «войдите», а куда ему.
            $this->stranger($chatId, $sub?->user ?? User::where('telegram_chat_id', (int) ($msg['from']['id'] ?? 0))->first());

            return;
        }
        $this->bot->quietly(fn () => $this->bot->say($chatId, 'Сюда приходят новые предложения, как только они выходят. Каждое — PDF с текстом для клиента: просто перешлите его',
            ['inline_keyboard' => [[Card::site('🌐 XCar', Card::url('offers', $this->bot->logsIn()), $this->bot->logsIn())]]]));
    }

    // ———————————————————————————————— карточка

    private function card(int $chatId, int $offerId): void
    {
        $sub = Subscriber::with('user')->where('chat_id', $chatId)->first();
        $offer = Offer::find($offerId);
        $user = $sub?->user;
        if (! $sub || $sub->blocked_at || ! Subscriber::eligible($user) || ! $offer || ! Cards::showable($user, $offer) || ! Cards::claim($user, $offer, $chatId)) {
            return;
        }
        // Срочное — со звуком всегда; остальное — тихо ночью (`quietHours`) и после первой карточки пачки.
        $silent = ! $offer->isUrgent() && ($user->quietHours() || Cards::inPack($user, $offer));
        try {
            Cards::remember($user, $offer, Card::send($this->bot, $chatId, $offer, $user, $silent));
        } catch (Throwable $e) {
            Cards::release($user, $offer);
            if (Bot::chatGone($e)) {
                $sub->forceFill(['blocked_at' => now()])->save();
            }
            Log::warning('Бот предложений: карточка не ушла', ['chat' => $chatId, 'offer' => $offerId, 'error' => $e->getMessage()]);
        }
    }

    // ———————————————————————————————— вход

    /**
     * `/start L…` — подписанная ссылка с сайта; голый `/start` — человек уже привязан к основному боту (id личного
     * чата у всех ботов один) или уже подписан. Повторный `/start` снимает «остановил».
     */
    private function start(int $chatId, array $from, ?string $payload): void
    {
        $parsed = $payload ? StartLink::parse($payload) : null;
        $user = match (true) {
            $parsed !== null && $parsed[0] === 'link' && ! $parsed[2] => User::find($parsed[1]),
            default => Subscriber::where('chat_id', $chatId)->first()?->user ?? User::where('telegram_chat_id', (int) ($from['id'] ?? 0))->first(),
        };
        if (! $user || ! Subscriber::eligible($user)) {
            $this->stranger($chatId, $user);

            return;
        }
        $this->connected($user, $chatId);
    }

    /**
     * Ссылка привязки (`/start L…`, из приложения или «Войти через XCar») или узнали по основному боту: подписка и
     * «Готово»; основной бот ещё не подключён — кнопка на него (21.10.2026, владелец: «рекомендуем подписаться на оба»).
     */
    private function connected(User $user, int $chatId): void
    {
        if (! Subscriber::eligible($user)) {
            $this->stranger($chatId, $user);

            return;
        }
        $this->subscribe($user, $chatId);
        $main = $user->telegram_chat_id === null ? app(Bot::class)->startUrl('hi') : null;
        // Полным именем: видно, под каким аккаунтом бот (21.10.2026).
        $this->bot->quietly(fn () => $this->bot->say($chatId, 'Готово, '.e($user->name).'. Новые предложения пришлю, как только они выйдут'
            .($main ? "\nСделки, ваш ход, оплаты и чаты: @".app(Bot::class)->username() : ''),
            $main ? ['inline_keyboard' => [[['text' => 'Подключить @'.app(Bot::class)->username(), 'url' => $main]]]] : null));
    }

    /** Написал без `/start`, но это менеджер, привязанный к основному боту, — узнаём сам. */
    private function recognize(int $chatId, array $from): ?Subscriber
    {
        $user = User::where('telegram_chat_id', (int) ($from['id'] ?? 0))->first();

        return $user && Subscriber::eligible($user) ? $this->subscribe($user, $chatId) : null;
    }

    /**
     * Один чат — один аккаунт, и в обоих ботах (21.10.2026): чат, бывший у другого, переходит к этому; основной бот
     * этого Telegram под чужим аккаунтом отвязывается — на /start он узнает этого сам. Новый или вернувшийся подписчик
     * получает карточки с этого момента: сразу все машины в продаже были бы лавиной.
     */
    private function subscribe(User $user, int $chatId): Subscriber
    {
        Subscriber::where('chat_id', $chatId)->where('user_id', '!=', $user->id)->delete();
        User::where('telegram_chat_id', $chatId)->whereKeyNot($user->id)->get()->each(fn (User $other) => app(UnlinkChat::class)($other));
        $sub = Subscriber::firstOrNew(['user_id' => $user->id]);
        $sub->forceFill(['chat_id' => $chatId, 'blocked_at' => null]
            + (! $sub->exists || $sub->blocked_at || (int) $sub->chat_id !== $chatId ? ['cards_since' => now()] : []))->save();
        $sub->setRelation('user', $user);
        ($this->publish)(Topics::user($user), 'offers-bot', ['state' => 'linked']);

        return $sub;
    }

    /** `/logout` — выйти из аккаунта в обоих ботах (`LogoutTelegram`). */
    private function logout(int $chatId): void
    {
        $out = app(LogoutTelegram::class)($chatId);
        $this->bot->quietly(fn () => $this->bot->say($chatId, match (true) {
            $out === LogoutTelegram::LOCKED => 'Выйти нельзя, пока вход в XCar только через Telegram',
            $out instanceof User => 'Вы вышли из аккаунта '.e($out->name),
            default => 'Вы не вошли',
        }, $out === LogoutTelegram::LOCKED ? null : ['inline_keyboard' => [[['text' => 'Войти через XCar', 'url' => ConnectLink::url(ConnectLink::OFFERS)]]]]));
    }

    /**
     * Незнакомый (21.10.2026): два бота и «Войти через XCar» (`ConnectLink`). Знакомый, которому бот не служит (покупатель),
     * — что бот для менеджеров, и кнопка основного бота.
     */
    private function stranger(int $chatId, ?User $user = null): void
    {
        $main = app(Bot::class)->startUrl('hi');
        if ($user) {
            $this->bot->quietly(fn () => $this->bot->say($chatId, 'Это бот для менеджеров XCar. Ваши уведомления приходят в @'.app(Bot::class)->username(),
                $main ? ['inline_keyboard' => [[['text' => 'Открыть @'.app(Bot::class)->username(), 'url' => $main]]]] : null));

            return;
        }
        $this->bot->quietly(fn () => $this->bot->say($chatId, ConnectLink::intro(),
            ['inline_keyboard' => [[['text' => 'Войти через XCar', 'url' => ConnectLink::url(ConnectLink::OFFERS)]]]]));
    }
}
