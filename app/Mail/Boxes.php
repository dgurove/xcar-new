<?php

namespace App\Mail;

/**
 * Коробки почты одним правилом: их читают список почты, экран «Из писем» и бейдж раздела.
 * Правило «надо завести» раньше жило внутри `attention()` в контроллере, а по нему же стоит
 * отдельный экран и счётчик — три копии разъехались бы на первой правке.
 */
final class Boxes
{
    /**
     * Пилюли почты своей стороны. `register` сюда не входит: это вшитая выборка «Из писем», снять её нечем.
     * «Цепочки» (открываются, 22.11.2026, владелец: «по умолчанию должны быть цепочки»), «Входящие» и «Отправленные» — письмами
     * строками, как в Gmail, и каждую можно сложить пачками по цепочке (`STACKED`); «Все делами» и «Архив» — делами
     * (17.11.2026, владелец: «во входящих только входящие, в отправленных только отправленные»). В CRM «Требуют внимания»
     * нет — только прочитанные и непрочитанные; на парковке остаются: там это задачи (осмотр, документы).
     *
     * @return array<string, string>
     */
    public static function boxes(Scope $scope): array
    {
        return ($scope === Scope::Park ? ['attention' => 'Требуют внимания'] : [])
            + ['chains' => 'Цепочки', 'in' => 'Входящие', 'sent' => 'Отправленные'] + (self::CASES ? ['all' => 'Все делами'] : []) + ['archive' => 'Архив'];
    }

    /**
     * Пилюля «Все делами» (17.11.2026, владелец: «юзлесс вид, оставь входящие и отправленные; на всякий случай пусть включается
     * в коде») — выключена. Сам вид живёт: в нём ищет лупа и показывает «Архив»; включить — `true`.
     */
    public const CASES = false;

    /** Пилюли, где строка — письмо, а не дело. */
    public const LETTERS = ['chains', 'in', 'sent'];

    /**
     * Пачками по цепочке — как открывается пилюля, пока человек не переключил сам (22.11.2026, владелец: «во входящих и
     * отправленных — возможность схлопывать в пачки по цепочкам, чтобы было видно только последнее из цепочки, и в
     * цепочках тоже»). «Цепочки» сложены, «Входящие» и «Отправленные» — по письму, как выбрано 17.11.2026. Цепочка — дело
     * (`group`): предложение или ТС, цепочка «Из писем», иначе ветка.
     */
    public const STACKED = ['chains' => true, 'in' => false, 'sent' => false];

    /** По последнему письму: по возрастанию — бывшее «Дольше ждут». */
    public const SORTS = ['fresh' => ['Последнее письмо', 'desc']];

    /** Направление и смысл последнего письма ветки — колонки, их держит `Threads::refresh` (было подзапросом на строку). */
    public const LAST_DIRECTION = 'mail_threads.last_direction';

    public const LAST_INTENT = 'mail_threads.last_intent';

    /**
     * «Прочее» — не про машину: без ТС, цепочки и предложения, и при этом либо не вендор, либо автоответ,
     * либо бухгалтерия (акты, счета, сверка — это переписка бухгалтерий, а не дело по машине). Пилюлей оно не прячется
     * (16.10.2026, владелец: письмо новой страховой лежало в «Прочем», и его никто не видел): стоит во «Все», убрать —
     * чипом «Без прочего» (`notNoise`) на этот раз.
     */
    public static function notNoise($query): void
    {
        // Ждёт ответа (вопрос, «Не доставлено») — не прочее, даже без машины и вендора.
        $query->where(fn ($w) => $w->whereNotNull('vehicle_id')->orWhereNotNull('candidate_id')->orWhereNotNull('offer_id')
            ->orWhereNotNull('needs_reply_at')->orWhere(fn ($v) => $v->whereNotNull('vendor_id')->whereRaw('coalesce('.self::LAST_INTENT.", '') not in ('auto', 'billing')")));
    }

    /**
     * Дело надо завести: цепочка «Из писем» ещё ждёт при пустом своём деле, либо письмо-заявка от вендора,
     * которому парсер не нашёл ни ТС, ни предложения, ни цепочки — такое заводят руками из окна ветки.
     * Начинается с `where`, а не с `orWhere`: тогда выражение верное и само по себе, и внутри `attention()`.
     */
    public static function register($query, Scope $scope): void
    {
        $own = $scope === Scope::Park ? 'vehicle_id' : 'offer_id';
        $query->where(fn ($w) => $w->whereNull($own)
            ->whereIn('candidate_id', Candidate::where('scope', $scope)->where('state', CandidateState::New)->select('id')))
            ->orWhere(fn ($w) => $w->whereNull('vehicle_id')->whereNull('offer_id')->whereNull('candidate_id')
                ->whereNotNull('vendor_id')->whereRaw(self::LAST_INTENT." = 'intake'"));
    }

    /** Дело требует нас: ждёт ответа (`needs_reply_at`) или его надо завести. */
    public static function attention($query, Scope $scope): void
    {
        $query->whereNotNull('needs_reply_at')->orWhere(fn ($w) => self::register($w, $scope));
    }

    /** Ключ дела в SQL: ТС (в CRM — предложение), цепочка, иначе сама ветка (письмо без машины — дело само по себе). */
    public static function group(Scope $scope): string
    {
        $own = $scope === Scope::Park ? "'v:' || vehicle_id::text" : "'o:' || offer_id::text";

        return "coalesce({$own}, 'c:' || candidate_id::text, 't:' || mail_threads.id::text)";
    }

    /** Сколько дел надо завести — бейдж «Из писем»: дел, а не цепочек, у одинокого письма-заявки цепочки нет. */
    public static function registerCount(Scope $scope): int
    {
        return (int) Thread::whereIn('account_id', Account::where('scope', $scope)->select('id'))
            ->where('messages_count', '>', 0)->whereNull('archived_at')
            ->where(fn ($w) => self::register($w, $scope))
            ->selectRaw('count(distinct '.self::group($scope).') as n')->value('n');
    }

    /**
     * Письма пилюли «Цепочки», «Входящие» или «Отправленные»: ящики, ветка жива (не в архиве); во «Входящих» — извне, в
     * «Отправленных» — наши, в «Цепочках» — и те и другие; `$threads` — ветки экрана.
     */
    public static function letters($accounts, string $box, $threads)
    {
        return Message::query()->select('mail_messages.*')->join('mail_threads', 'mail_threads.id', '=', 'mail_messages.thread_id')
            ->whereIn('mail_messages.account_id', $accounts)->whereNull('mail_threads.archived_at')
            ->whereIn('mail_messages.thread_id', (clone $threads)->select('mail_threads.id'))
            ->when($box !== 'chains', fn ($q) => $q->where('mail_messages.outside', $box === 'in'));
    }

    /** Непрочитанные «Входящие» — число вкладки «Почта» CRM и пилюли (письма, не ветки, 17.11.2026). */
    public static function unreadCount(Scope $scope): int
    {
        return (int) Message::query()->join('mail_threads', 'mail_threads.id', '=', 'mail_messages.thread_id')
            ->whereIn('mail_messages.account_id', Account::where('scope', $scope)->select('id'))
            ->whereNull('mail_threads.archived_at')->where('mail_messages.outside', true)->where('mail_messages.is_seen', false)->count();
    }

    /** Сколько дел в «Требуют внимания» — бейдж почты парковки, то же число, что у пилюли. */
    public static function attentionCount(Scope $scope): int
    {
        return (int) Thread::whereIn('account_id', Account::where('scope', $scope)->select('id'))
            ->where('messages_count', '>', 0)->whereNull('archived_at')
            ->where(fn ($w) => self::attention($w, $scope))
            ->selectRaw('count(distinct '.self::group($scope).') as n')->value('n');
    }

    /** Сколько дел в пилюле «Все» — итог таба «Почта»: без архива, делами, как в заголовке экрана. */
    public static function allCount(Scope $scope): int
    {
        return (int) Thread::whereIn('account_id', Account::where('scope', $scope)->select('id'))
            ->where('messages_count', '>', 0)->whereNull('archived_at')
            ->selectRaw('count(distinct '.self::group($scope).') as n')->value('n');
    }
}
