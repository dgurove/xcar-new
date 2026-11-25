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
    public const KINDS = ['phone' => 'Телефон', 'name' => 'Имя', 'address' => 'Адрес', 'file' => 'Документ', 'text' => 'Сообщение', 'photos' => 'Фото'];

    /** Группа пунктов без письма — из карточки ТС. */
    public const CARD = 'card';

    /**
     * Пункты по письмам (16.11.2026, владелец: «окно абсолютно безобразное, особенно документы», «по письмам группировать»): у каждого
     * `group` — `letter:{id}` или `card` («Из карточки ТС», только то, чего нет в письмах); фото письма — одним пунктом
     * `photos:{id}` (все кадры разом), документы — по одному.
     *
     * @param  Collection<int, Message>  $letters  письма разбора; пусто — показ руками
     * @param  ?int  $open  письмо, чей текст в поле сообщения (из окна писем — то, с которого открыли; иначе самое новое): его
     *                     документы отмечены сразу
     * @return array<string, array{kind: string, value: string, media: int|list<int>|null, group: string, label?: string, pick?: bool}> ключ пункта → пункт
     */
    public static function proposals(Deal $deal, Collection $letters, ?int $open = null, bool $contact = false): array
    {
        $out = [];
        $add = function (string $kind, ?string $value, string $group, ?int $media = null) use (&$out) {
            $value = trim((string) $value);
            if ($value === '') {
                return;
            }
            $key = match ($kind) {
                'phone' => 'phone:'.(Phone::normalize($value) ?? md5($value)),
                'file' => 'file:'.$media,
                default => $kind.':'.md5(mb_strtolower($value)),
            };
            $out[$key] ??= ['kind' => $kind, 'value' => $kind === 'phone' && Phone::normalize($value) ? Phone::format(Phone::normalize($value)) : $value, 'media' => $media, 'group' => $group];
        };
        $offer = $deal->offer;
        $letters = $letters->sortByDesc(fn (Message $m) => $m->date_at)->values();
        $open ??= $letters->first()?->id;
        // «Договор готов» (11.11.2026, `SigningLetter`): страхователь, его телефоны, адрес осмотра и стоянка поставщика тут ни
        // к чему — менеджеру где и как подписывать и что взять покупателю, отмечено сразу (`pick`).
        $signing = $letters->first(fn (Message $m) => SigningLetter::ready($m));
        if ($signing) {
            foreach (array_filter([SigningLetter::line($signing), SigningLetter::documents($signing->replyText())]) as $i => $line) {
                $out['signing:'.$i] = ['kind' => 'text', 'value' => $line, 'media' => null, 'group' => 'letter:'.$signing->id, 'label' => $i === 0 ? 'Подписание' : 'Покупателю', 'pick' => true];
            }
        }
        $papers = $offer->getMedia('papers')->reject(fn (Media $m) => ForManagers::is($m));
        foreach ($signing ? [] : $letters as $m) {
            $group = 'letter:'.$m->id;
            // Текст письма — не пунктом, а полем сообщения сверху шторки (`draft`, 16.11.2026, владелец: «не понимаю, что нажать, чтобы
            // отправить менеджеру текст письма и отредактировать перед отправкой»).
            foreach (OwnerPhones::for($m) as $phone) {
                $add('phone', $phone, $group);
            }
            $add('phone', $m->field('insured_phone'), $group);
            $add('name', $m->field('insured_name'), $group);
            $add('address', VendorNames::strip($m->field('location')), $group);
            $own = $papers->filter(fn (Media $f) => (int) $f->getCustomProperty('letter') === $m->id);
            // Документы собственника письма — паспорт, СТС, ПТС (`OwnerDocs`, 25.11.2026, владелец: Т-Страхование шлёт их, а сотрудники
            // скачивали и слали в чат руками): пунктами-файлами письма, отмечены сразу — и в показе контакта, и картинкой («Паспорт1.png»).
            $owner = OwnerDocs::among($own);
            $owner->each(function (Media $f) use ($add, $group, &$out) {
                $add('file', $f->name ?: $f->file_name, $group, $f->id);
                isset($out['file:'.$f->id]) && $out['file:'.$f->id]['pick'] = true;
            });
            // Показ контакта (`contact`, 25.11.2026): из писем — только телефоны, имя, адрес и документы собственника; остальные
            // документы — машины, как при показе руками.
            if ($contact) {
                continue;
            }
            [$pictures, $documents] = $own->reject(fn (Media $f) => $owner->contains('id', $f->id))
                ->partition(fn (Media $f) => str_starts_with((string) $f->mime_type, 'image/'));
            $documents->each(function (Media $f) use ($add, $group, $m, $open, &$out) {
                $add('file', $f->name ?: $f->file_name, $group, $f->id);
                if ($m->id === $open && isset($out['file:'.$f->id])) {
                    $out['file:'.$f->id]['pick'] = true;
                }
            });
            if ($pictures->isNotEmpty()) {
                $out['photos:'.$m->id] = ['kind' => 'photos', 'value' => 'Фото '.$pictures->count(), 'media' => $pictures->pluck('id')->values()->all(), 'group' => $group];
            }
        }
        if (! $signing) {
            $add('name', $offer->insured_name, self::CARD);
            $add('phone', $offer->insured_phone, self::CARD);
            $add('phone', $offer->insured_phone2, self::CARD);
            $add('address', VendorNames::strip($offer->inspection_address), self::CARD);
        }
        // Без писем (показ руками) — все документы машины; с письмами — только письма, остальное в «Из карточки» не тянем:
        // там 20 кадров с Мигторга строками и были «безобразием».
        if ($letters->isEmpty() || $contact) {
            $papers->each(fn (Media $f) => $add('file', $f->name ?: $f->file_name, self::CARD, $f->id));
        }

        return $out;
    }

    /**
     * Письма с контактом для шторки «Показать контакты менеджеру» (`x-deal.contact-shown`, 25.11.2026, BMW X5 915891/2026: телефон —
     * только строкой «Готовы к передаче – 8-988-…» письма Совкомбанка, в карточке пусто): входящие письма поставщика по машине,
     * где есть телефон до подписи или поля страхователя, три свежих. Шторка и сервер (`DisclosureController`, `card`) берут их здесь.
     *
     * @return Collection<int, Message>
     */
    public static function contactLetters(Deal $deal): Collection
    {
        return Message::query()->whereHas('thread', fn ($q) => $q->where('offer_id', $deal->offer_id))
            ->where('direction', \App\Mail\Direction::In)->latest('date_at')->limit(20)->get()
            ->filter(fn (Message $m) => $m->isFromVendor() && (OwnerPhones::for($m) !== [] || $m->field('insured_phone') || $m->field('insured_name') || $m->field('location')))
            ->take(3)->values();
    }

    /**
     * Группы шторки — по письмам, новое сверху, и «Из карточки ТС» последней: [key, letter, items (ключ → пункт), open]. Раскрыта
     * одна — `$open` (письмо, с которого открыли), иначе самое новое.
     *
     * @return list<array{key: string, letter: ?Message, items: array<string, array>, open: bool}>
     */
    public static function groups(array $proposals, Collection $letters, ?int $open = null): array
    {
        $letters = $letters->sortByDesc(fn (Message $m) => $m->date_at)->values();
        $open ??= $letters->first()?->id;
        $groups = [];
        foreach ($letters as $m) {
            $groups[] = ['key' => 'letter:'.$m->id, 'letter' => $m, 'items' => array_filter($proposals, fn ($p) => $p['group'] === 'letter:'.$m->id), 'open' => $m->id === $open];
        }
        $card = array_filter($proposals, fn ($p) => $p['group'] === self::CARD);
        if ($card) {
            $groups[] = ['key' => self::CARD, 'letter' => null, 'items' => $card, 'open' => $letters->isEmpty()];
        }

        return $groups;
    }

    /** Что в группе — словами для свёрнутой строки: «текст, 2 телефона, акт, 21 фото». */
    public static function summary(array $items): string
    {
        $items = collect($items);
        $count = fn (string $kind) => $items->where('kind', $kind)->count();
        $plural = fn (int $n, array $forms) => $n.' '.\App\Support\Plural::of($n, $forms);

        return implode(', ', array_filter([
            $items->contains(fn ($p) => $p['kind'] === 'text') ? 'текст' : null,
            $count('phone') ? ($count('phone') === 1 ? 'телефон' : $plural($count('phone'), ['телефон', 'телефона', 'телефонов'])) : null,
            $count('name') ? 'имя' : null,
            $count('address') ? 'адрес' : null,
            $count('file') ? ($count('file') === 1 ? 'документ' : $plural($count('file'), ['документ', 'документа', 'документов'])) : null,
            ($photos = $items->firstWhere('kind', 'photos')) ? count($photos['media']).' фото' : null,
        ]));
    }

    /**
     * Текст письма менеджеру (16.11.2026, владелец: в шторке было «<mailto:…>», «[cid:image002…]» и абзац «Информация, содержащаяся в
     * настоящем электронном сообщении…»): свои слова до подписи (`replyText`) без хвостов ссылок почтовиков и оговорок о
     * конфиденциальности, без названия вендора.
     */
    public static function letterText(Message $m): string
    {
        $text = app(\App\Mail\BodyRenderer::class)->ownText($m);
        // Подпись: «С уважением» и всё ниже; иначе — со строки, где только имя отправителя («Александр Лапшин» у «Лапшин Александр»).
        $text = (string) preg_split('/^\s*(?:С\s+уважением|C\s+уважением|Best\s+regards|--\s*$|Отправлено\s+из)/imu', $text)[0];
        $name = collect(preg_split('/\s+/u', mb_strtolower((string) \App\Mail\Chains\NodeTitle::who($m))))->filter(fn ($w) => mb_strlen($w) > 2);
        if ($name->count() >= 2) {
            $lines = preg_split('/\R/u', $text) ?: [];
            foreach ($lines as $i => $line) {
                $words = collect(preg_split('/\s+/u', mb_strtolower(trim($line), 'UTF-8')))->filter();
                if ($i > 0 && $words->count() >= 2 && $words->count() <= 3 && $words->every(fn ($w) => $name->contains(trim($w, '.,')))) {
                    $text = implode("\n", array_slice($lines, 0, $i));
                    break;
                }
            }
        }
        $text = (string) preg_replace(['/<(?:mailto|tel|https?):[^>]*>/iu', '/\[cid:[^\]]*\]/iu', '/\[(?:https?:)?[^\]\s]*\]/iu', '/<https?:[^>]*>/iu'], '', $text);
        $text = (string) preg_split('/(?:Информация,\s+содержащаяся\s+в\s+настоящем|Это\s+электронное\s+письмо\s+строго\s+конфиденциально|Настоящее\s+(?:электронное\s+)?сообщение\s+(?:и\s+все\s+приложения\s+к\s+нему\s+)?(?:является\s+)?конфиденциальн|This\s+e-?mail\s+(?:and\s+any|is\s+confidential|message))/iu', $text)[0];
        $text = (string) preg_replace(['/[ \t]+$/mu', "/\n{2,}/u"], ['', "\n"], $text);
        // Обращение к нам — не менеджеру (25.11.2026, владелец по 779371/2026: «очень плохо, что мы ему показываем „Добрый день
        // Андрей.Катерина, просьба связать с клиентом…“»): первая строка-приветствие целиком, что бы ни шло после приветствия
        // («Добрый день!», «Андрей, Екатерина, добрый день!», «Коллеги,», «Добрый день Андрей.Катерина,»), и просьбы к нам в начале
        // («Просьба связать с клиентом по вывозу», «Прошу…», «Просим…») — до первой строки по делу.
        $lines = preg_split('/\R/u', ltrim($text)) ?: [];
        // Приветствие с именами до и после — только оно: «Добрый день! Готовы к передаче…» оставит «Готовы к передаче…».
        $greet = '/^\s*(?:[А-ЯЁ][а-яё]+[\s,.]*){0,3}(?:[Дд]обр(?:ый|ое|ого)\s+(?:день|вечер|утро|дня)|[Зз]дравствуй\w*|[Пп]ривет\w*|(?:[Уу]важаем\w+\s+)?[Кк]оллеги)(?:[\s,.]*[А-ЯЁ][а-яё]+){0,3}[\s!,.]*/u';
        while ($lines && preg_match($greet, $lines[0])) {
            $rest = trim((string) preg_replace($greet, '', $lines[0], 1));
            if ($rest !== '') {
                $lines[0] = $rest;
                break;
            }
            array_shift($lines);
        }
        // Просьба об оплате и сроках — менеджеру нужна (платит он): её оставляем.
        while ($lines && preg_match('/^\s*(?:просьба|прошу|просим)\b/iu', $lines[0]) && ! preg_match('/оплат|сч[её]т|реквизит|\d/iu', $lines[0])) {
            array_shift($lines);
        }
        $text = implode("\n", $lines);
        // Второе приветствие отдельной строкой — дальше вставленное прежнее письмо без заголовка цитаты (Совкомбанк кладёт под свой текст
        // описание лота «Добрый день / МАРКА МОДЕЛЬ: …»).
        $lines = preg_split('/\R/u', $text) ?: [];
        foreach ($lines as $i => $line) {
            if ($i > 0 && preg_match('/^\s*(?:добр(?:ый|ое)\s+(?:день|вечер|утро)|здравствуйте)[!,.\s]*$/iu', $line)) {
                $text = implode("\n", array_slice($lines, 0, $i));
                break;
            }
        }

        return trim((string) VendorNames::strip($text));
    }

    /**
     * Текст поля «Сообщение менеджеру»: письмо `$open` (с которого открыли), иначе самое новое — без приветствия и подписи.
     *
     * @param  Collection<int, Message>  $letters
     */
    public static function draft(Collection $letters, ?int $open = null): string
    {
        $letter = ($open ? $letters->firstWhere('id', $open) : null) ?? $letters->sortByDesc(fn (Message $m) => $m->date_at)->first();

        return $letter ? mb_substr(self::letterText($letter), 0, 5000) : '';
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
            // Фото письма — все кадры документами по одному: менеджер получает их так же, как любой файл.
            if ($item['kind'] === 'photos') {
                foreach (Media::whereIn('id', $item['media'])->get(['id', 'name', 'file_name']) as $f) {
                    $items[] = ['kind' => 'file', 'value' => $f->name ?: $f->file_name, 'media' => $f->id, 'photo' => true];
                }

                continue;
            }
            $value = trim((string) $value);
            if ($value !== '') {
                $items[] = ['kind' => $item['kind'], 'value' => mb_substr($value, 0, 300)];
            }
        }
        if (($text = trim((string) $text)) !== '') {
            $items[] = ['kind' => 'text', 'value' => mb_substr($text, 0, 5000)];
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
