<?php

namespace App\Mail;

use App\Users\User;
use Illuminate\Support\Collection;

/**
 * С каких ящиков человек пишет — одна дверь на CRM, XCAR Mail и парковку (21.11.2026, владелец: «управляющий парковки — только с
 * storage@, модераторы в CRM — только с deal@, администраторы вольны выбирать и там и там»). Ящики — активные и отправляющие
 * (`Account::sending`: offer@ только принимает и отвечает через deal@). Роли складываются: модератор, что ещё и на парковке, пишет с
 * обоих. До того «можно ли с этого ящика» решала сторона экрана, и ответ из окна машины CRM на письмо парковки (storage@) падал
 * «Значение поля account не существует».
 */
final class Senders
{
    /** @return Collection<int, Account> */
    public static function for(User $user): Collection
    {
        $scopes = $user->isAdmin() ? [Scope::Offers, Scope::Park] : array_values(array_filter([
            $user->canCrmMail() ? Scope::Offers : null,
            $user->canManagePark() ? Scope::Park : null,
        ]));

        return $scopes === [] ? collect() : Account::query()->sending()->whereIn('scope', $scopes)->orderBy('title')->get();
    }

    public static function allows(User $user, Account $account): bool
    {
        return self::for($user)->contains('id', $account->id);
    }

    /**
     * Ящик по умолчанию: нужный (тот, что отвечает за письмо, на которое отвечаем, или ящик вендора), если с него можно; иначе —
     * первый своей стороны экрана; иначе — любой разрешённый.
     */
    public static function pick(User $user, ?Account $preferred, Scope $side): ?Account
    {
        $accounts = self::for($user);
        $preferred = $preferred?->sender();

        return ($preferred ? $accounts->firstWhere('id', $preferred->id) : null)
            ?? $accounts->firstWhere('scope', $side)
            ?? $accounts->first();
    }
}
