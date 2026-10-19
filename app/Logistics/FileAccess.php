<?php

namespace App\Logistics;

use App\Cars\Car;
use App\Mail\Extraction\AttachmentClassifier;
use App\Users\User;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Фото и документы машины в логистике (19.10.2026, админы и Татьяна: «не хватает документов и фоток из писем»): ведущие
 * логистику решают глазиком, что видит логист, логист — что видит его исполнитель. Отметки — у файла машины, как «Видно
 * менеджеру» (`Media\ForManagers`): `custom_properties.logist` и `executor`; пусто — по умолчанию (`byDefault`): кадр виден
 * обоим; логисту ещё договор комиссии и акт приёма-передачи (19.10.2026, владелец: «только Татьяне»), остальные документы
 * скрыты (оценка с ценой, паспорта); исполнителю документы по умолчанию не видны никогда. Кадры погрузки и выдачи сюда не входят — у них свой блок
 * (`x-logistics.photos`); «скрыто с сайта» (`Media\Hidden`) здесь ни при чём.
 */
final class FileAccess
{
    /** @param  'logist'|'executor'  $who */
    public static function sees(Media $media, string $who): bool
    {
        $mark = $media->getCustomProperty($who);

        return $mark === null ? self::byDefault($media, $who) : (bool) $mark;
    }

    /**
     * Видно ли без отметки: кадр — обоим; документ — только логисту и только договор комиссии или акт приёма-передачи (по
     * имени файла, как их называет почта — `AttachmentClassifier`). Всё прочее — только глазом.
     *
     * @param  'logist'|'executor'  $who
     */
    public static function byDefault(Media $media, string $who): bool
    {
        if ($media->collection_name === 'photos') {
            return true;
        }
        if ($who !== 'logist' || $media->collection_name !== 'papers') {
            return false;
        }
        $name = mb_strtolower(str_replace('ё', 'е', $media->file_name.' '.$media->name));

        return str_contains($name, 'комисси') || AttachmentClassifier::kindOf($name) === 'act';
    }

    /** @param  'logist'|'executor'  $who */
    public static function toggle(Media $media, string $who): bool
    {
        $media->setCustomProperty($who, $on = ! self::sees($media, $who))->save();

        return $on;
    }

    /**
     * За кого человек решает в этой задаче: ведущий — за логиста, если задача идёт через него, иначе за исполнителя (наши
     * люди, другой менеджер); логист — за своего исполнителя; исполнитель — ни за кого.
     *
     * @return 'logist'|'executor'|null
     */
    public static function toggleFor(Transport $t, User $user): ?string
    {
        return match (true) {
            $user->leadsLogistics() && ! $t->isOrder() => $t->logist_id ? 'logist' : 'executor',
            $t->hasLogist($user) && self::live($t) => 'executor',
            default => null,
        };
    }

    /**
     * Видит ли человек этот файл в задаче. Исполнитель через логиста — только то, что открыто и логисту. Логист и исполнитель
     * — только пока задача идёт (`live`): отметки лежат у файла машины, а не у задачи, и иначе исполнитель прошлого отрезка
     * или снятый с задачи видел бы то, что потом открыли следующему.
     */
    public static function visible(Transport $t, User $user, Media $media): bool
    {
        return match (true) {
            $user->leadsLogistics() && ! $t->isOrder() => true,
            ! self::live($t) => false,
            $t->hasLogist($user) => self::sees($media, 'logist'),
            $t->isExecutedBy($user) => self::forExecutor($t, $media),
            default => false,
        };
    }

    /** Исполнителю — и со входом, и по ссылке `/trip/{code}`; привёз или сняли — больше ничего. */
    public static function forExecutor(Transport $t, Media $media): bool
    {
        return self::live($t) && self::sees($media, 'executor') && (! $t->logist_id || self::sees($media, 'logist'));
    }

    /** Задача идёт: не привезена и не отменена. */
    public static function live(Transport $t): bool
    {
        return ! in_array($t->state, [TransportState::Delivered, TransportState::Cancelled], true);
    }

    /**
     * Файлы машины задачи: кадры (без погрузки и выдачи) и документы. Заявка логиста от своего заказчика — машина без
     * дорожек, файлов у неё нет.
     *
     * @return array{photos: Collection<int, Media>, papers: Collection<int, Media>}
     */
    public static function files(Transport $t): array
    {
        $photos = $t->car ? $t->car->getMedia('photos')->reject(fn (Media $m) => in_array($m->getCustomProperty('stage'), ['pickup', 'release'], true))->values() : collect();

        return ['photos' => $photos, 'papers' => $t->car ? $t->car->getMedia('papers')->values() : collect()];
    }

    /** Файл — этой машины задачи (иначе 404 у действий и выдачи). */
    public static function belongs(Transport $t, Media $media): bool
    {
        return $t->car_id && $media->model_type === Car::class && (int) $media->model_id === (int) $t->car_id
            && in_array($media->collection_name, ['photos', 'papers'], true) && ! in_array($media->getCustomProperty('stage'), ['pickup', 'release'], true);
    }
}
