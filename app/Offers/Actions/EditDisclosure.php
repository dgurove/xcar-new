<?php

namespace App\Offers\Actions;

use App\Chats\Actions\DeleteMessage;
use App\Notifications\DealInfoNotice;
use App\Offers\Deal;
use App\Offers\Disclosure;
use App\Offers\OfferEventType;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Исправить или удалить показанное менеджеру (25.11.2026, владелец: «должна быть возможность редактировать: в тексте что-то
 * убрать или добавить, документ убрать или добавить; при сохранении в сделке поменялось, то сообщение удалилось и новое
 * отправилось, и в Telegram тоже стёрлось»). Под замком сделки, как `Disclose`: пункты — новые (пусто или удаление — показа нет),
 * убранный документ менеджеру больше не виден, если его не держит другой показ сделки, добавленный — виден; прежнее сообщение
 * в чате удаляется (`DeleteMessage`: у менеджера его нет, сообщения бота в Telegram отзываются — `RetractChatMessage`, Telegram
 * даёт только младше 48 часов), новое уходит тем же видом (`Disclose::toChat`) с уведомлением чата; чата нет — `DealInfoNotice`.
 * Ход сделки не трогается.
 *
 * @param  ?list<array{kind: string, value: string, media?: int}>  $items  null — удалить показ
 */
final class EditDisclosure
{
    public function __construct(private DeleteMessage $delete) {}

    public function __invoke(Disclosure $disclosure, User $by, ?array $items): ?Disclosure
    {
        $deal = $disclosure->deal;
        [$disclosure, $old] = DB::transaction(function () use ($disclosure, $deal, $by, $items) {
            Deal::whereKey($deal->id)->lockForUpdate()->firstOrFail();
            $disclosure = Disclosure::whereKey($disclosure->id)->firstOrFail();
            $old = $disclosure->chatMessage();
            $ids = fn (?array $list) => array_values(array_unique(array_filter(array_map(fn ($i) => ($i['kind'] ?? null) === 'file' ? (int) ($i['media'] ?? 0) : 0, $list ?? []))));
            $was = $ids($disclosure->items);
            $now = $ids($items);
            // Документ держит другой показ этой сделки — остаётся видимым.
            $held = Disclosure::where('deal_id', $deal->id)->whereKeyNot($disclosure->id)->get()->flatMap(fn (Disclosure $d) => $ids($d->items))->all();
            Media::whereIn('id', array_diff($was, $now, $held))->get()->each(fn (Media $m) => $m->setCustomProperty('managers', false)->save());
            Media::whereIn('id', array_diff($now, $was))->get()->each(fn (Media $m) => $m->setCustomProperty('managers', true)->save());
            if ($items) {
                $disclosure->update(['items' => $items, 'chat_message_id' => null]);
                $deal->offer->log(OfferEventType::Note, $by, ['text' => 'Исправили показанное менеджеру: '.$disclosure->summary()]);
            } else {
                $deal->offer->log(OfferEventType::Note, $by, ['text' => 'Удалили показанное менеджеру: '.$disclosure->summary()]);
                $disclosure->delete();
                $disclosure = null;
            }

            return [$disclosure, $old];
        });
        if ($old && ! $old->isDeleted() && $old->deletableBy($by)) {
            ($this->delete)($old, $by, log: false);
        }
        Disclose::publish($deal);
        if ($disclosure && $deal->buyer && ! Disclose::toChat($deal->fresh(['offer', 'buyer']), $disclosure)) {
            $deal->buyer->notify(new DealInfoNotice($disclosure, null));
        }

        return $disclosure;
    }
}
