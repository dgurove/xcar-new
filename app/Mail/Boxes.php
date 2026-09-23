<?php

namespace App\Mail;

/**
 * Коробки почты одним правилом: их читают список почты, экран «Из писем» и бейдж раздела.
 * Правило «надо завести» раньше жило внутри `attention()` в контроллере, а по нему же стоит
 * отдельный экран и счётчик — три копии разъехались бы на первой правке.
 */
final class Boxes
{
    /** Пилюли почты. `register` сюда не входит: это вшитая выборка «Из писем», снять её нечем. */
    public const BOXES = ['attention' => 'Требуют внимания', 'all' => 'Все', 'other' => 'Прочее', 'sent' => 'Отправленные', 'archive' => 'Архив'];

    public const SORTS = ['fresh' => 'Свежие', 'waiting' => 'Дольше ждут'];

    /** Направление и смысл последнего письма ветки — колонки, их держит `Threads::refresh` (было подзапросом на строку). */
    public const LAST_DIRECTION = 'mail_threads.last_direction';

    public const LAST_INTENT = 'mail_threads.last_intent';

    /**
     * «Прочее» — не про машину: без ТС, цепочки и предложения, и при этом либо не вендор, либо автоответ,
     * либо бухгалтерия (акты, счета, сверка — это переписка бухгалтерий, а не дело по машине).
     */
    public static function noise($query): void
    {
        $query->whereNull('vehicle_id')->whereNull('candidate_id')->whereNull('offer_id')
            ->where(fn ($w) => $w->whereNull('vendor_id')->orWhereRaw(self::LAST_INTENT." in ('auto', 'billing')"));
    }

    public static function notNoise($query): void
    {
        $query->where(fn ($w) => $w->whereNotNull('vehicle_id')->orWhereNotNull('candidate_id')->orWhereNotNull('offer_id')
            ->orWhere(fn ($v) => $v->whereNotNull('vendor_id')->whereRaw('coalesce('.self::LAST_INTENT.", '') not in ('auto', 'billing')")));
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
}
