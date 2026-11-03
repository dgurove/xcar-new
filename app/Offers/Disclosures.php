<?php

namespace App\Offers;

use App\Mail\Message;
use App\Media\ForManagers;
use App\Support\Phone;
use App\Support\VendorNames;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Что система предлагает админу показать менеджеру (03.11.2026, владелец: «чётко показывается, что пришёл такой-то ответ, и что
 * система предлагает показать: [ ] Телефон, [ ] Имя, [ ] Документ паспорт.pdf, доп. текстовое поле; каждый пункт можно
 * редактировать»). Из писем разбора — телефоны до подписи без 8-800 (`OwnerPhones`), страхователь и место осмотра, их
 * документы; из машины — страхователь, телефоны и адрес осмотра (их дописывают письма — `FillFromLetter`); без писем (показ
 * руками) — все документы машины. Уже открытые менеджеру документы не предлагаются, повторы склеиваются; не отмечено ничего.
 * Считается при открытии шторки и заново на отправке: документы письма приезжают задачей (`ShareReplyFiles`).
 */
final class Disclosures
{
    public const KINDS = ['phone' => 'Телефон', 'name' => 'Имя', 'address' => 'Адрес', 'file' => 'Документ', 'text' => 'Сообщение'];

    /**
     * @param  Collection<int, Message>  $letters  письма разбора; пусто — показ руками
     * @return array<string, array{kind: string, value: string, media: ?int}> ключ пункта → пункт
     */
    public static function proposals(Deal $deal, Collection $letters): array
    {
        $out = [];
        $add = function (string $kind, ?string $value, ?int $media = null) use (&$out) {
            $value = trim((string) $value);
            if ($value === '') {
                return;
            }
            $key = match ($kind) {
                'phone' => 'phone:'.(Phone::normalize($value) ?? md5($value)),
                'file' => 'file:'.$media,
                default => $kind.':'.md5(mb_strtolower($value)),
            };
            $out[$key] ??= ['kind' => $kind, 'value' => $kind === 'phone' && Phone::normalize($value) ? Phone::format(Phone::normalize($value)) : $value, 'media' => $media];
        };
        $offer = $deal->offer;
        foreach ($letters as $m) {
            foreach (OwnerPhones::for($m) as $phone) {
                $add('phone', $phone);
            }
            $add('phone', $m->field('insured_phone'));
            $add('name', $m->field('insured_name'));
            $add('address', VendorNames::strip($m->field('location')));
        }
        $add('name', $offer->insured_name);
        $add('phone', $offer->insured_phone);
        $add('phone', $offer->insured_phone2);
        $add('address', VendorNames::strip($offer->inspection_address));

        $ids = $letters->pluck('id')->all();
        $offer->getMedia('papers')
            ->reject(fn (Media $m) => ForManagers::is($m))
            ->filter(fn (Media $m) => $ids === [] || in_array((int) $m->getCustomProperty('letter'), $ids, true))
            ->each(fn (Media $m) => $add('file', $m->name ?: $m->file_name, $m->id));

        return $out;
    }

    /**
     * Отмеченное админом — сверкой с пересчитанными пунктами: чужой документ и выдуманный пункт не пройдут. Правленное значение
     * берётся из формы (у документа — имя, его не правят), своё сообщение — последним.
     *
     * @param  array<string, string|null>  $pick  ключ пункта → значение из формы
     * @return list<array{kind: string, value: string, media?: int}>
     */
    public static function picked(array $proposals, array $pick, ?string $text): array
    {
        $items = [];
        foreach ($pick as $key => $value) {
            $item = $proposals[$key] ?? throw ValidationException::withMessages(['disclose' => 'Пункты изменились — откройте шторку заново']);
            if ($item['kind'] === 'file') {
                $items[] = ['kind' => 'file', 'value' => $item['value'], 'media' => $item['media']];

                continue;
            }
            $value = trim((string) $value);
            if ($value !== '') {
                $items[] = ['kind' => $item['kind'], 'value' => mb_substr($value, 0, 300)];
            }
        }
        if (($text = trim((string) $text)) !== '') {
            $items[] = ['kind' => 'text', 'value' => mb_substr($text, 0, 2000)];
        }

        return $items;
    }

    /** Показанное по сделке, новое сверху — один запрос на сделку (страница, получение, плашка CRM). @return Collection<int, Disclosure> */
    public static function shown(Deal $deal): Collection
    {
        return $deal->loadMissing('disclosures')->disclosures;
    }

    /** Первое значение вида из показанного (новое главнее): контакт получения автомобиля (`Handover`). */
    public static function latest(Collection $shown, string $kind, int $nth = 0): ?string
    {
        return $shown->flatMap(fn (Disclosure $d) => $d->values($kind))->values()->get($nth);
    }
}
