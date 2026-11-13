<?php

namespace App\Billing;

use App\Park\Vehicle;
use App\Support\Demo\HidesDemo;
use App\Support\FastAttributes;
use App\Users\User;
use App\Vendors\ContactRole;
use App\Vendors\Kind;
use App\Vendors\Vendor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/** Контрагент счёта: юрлицо, ИП, самозанятый или физлицо с паспортом. Строки `is_self` — мы, по одной на продавца (`seller`). */
#[Fillable(['kind', 'name', 'is_self', 'seller', 'inn', 'kpp', 'ogrn', 'legal_address', 'director', 'director_basis', 'full_name', 'director_full', 'signed_by', 'bank_name', 'bik', 'account', 'corr_account',
    'passport', 'passport_issued', 'passport_issued_at', 'passport_code', 'reg_address', 'birth_at', 'birth_place', 'phone', 'email', 'card', 'payment_purpose', 'notes', 'vat_on_top'])]
class Party extends Model implements HasMedia
{
    use FastAttributes;
    use InteractsWithMedia;

    /** Страницы скана паспорта (11.10.2026, владелец): разворот с фото и прописка — обе обязательны покупателю-физлицу. */
    public const PASSPORT_PAGES = ['main' => 'Разворот с фото', 'reg' => 'Прописка'];

    /** Реквизиты плательщика поменялись — «нужны реквизиты» в закрытии месяца пересчитывается сразу, а не через 10 минут. */
    protected static function booted(): void
    {
        static::saved(fn (self $p) => $p->is_self ? null : Closing::forgetPending());
    }

    use HidesDemo;

    protected $table = 'billing_parties';

    protected function casts(): array
    {
        return ['kind' => PartyKind::class, 'is_self' => 'bool', 'seller' => Seller::class, 'birth_at' => 'date', 'passport_issued_at' => 'date', 'vat_on_top' => 'bool'];
    }

    /** Номер карты — только цифры, из какой двери ни пришёл (форма даёт «2200 0000 …»): иначе в выплате и строке он разный. */
    protected function card(): Attribute
    {
        return Attribute::make(set: fn (?string $v) => ($digits = preg_replace('/\D/', '', (string) $v)) === '' ? null : $digits);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'party_id');
    }

    /**
     * Плательщики парковки — те, чьи реквизиты правит управляющий: со счетами парковки, вендоры парковки, страхователи и
     * покупатели ТС. Своих реквизитов (`is_self`) и контрагентов продаж тут нет.
     */
    public function scopeOfPark($query)
    {
        return $query->where('is_self', false)->where(fn ($w) => $w
            ->whereIn('id', Invoice::ofPark()->select('party_id'))
            ->orWhereIn('id', Vendor::onPark()->whereNotNull('party_id')->select('party_id'))
            ->orWhereIn('id', Vehicle::whereNotNull('owner_party_id')->select('owner_party_id'))
            ->orWhereIn('id', Vehicle::whereNotNull('buyer_party_id')->select('buyer_party_id')));
    }

    /** Кому из контрагентов человек на парковке может выставить и чьи реквизиты правит: админу — всем, кроме нас. */
    public function scopeVisibleOnPark($query, User $user)
    {
        return $user->isParkAdmin() ? $query->where('is_self', false) : $query->ofPark();
    }

    /**
     * Мы как продавец. Строки нет (свежая база, снесли руками) — заводится из конфига продавца; дальше реквизиты
     * живут в базе и правятся на парковке в «Реквизитах».
     */
    public static function seller(Seller $seller): self
    {
        $c = $seller->config();

        return self::firstOrCreate(['seller' => $seller->value], [
            'is_self' => true, 'kind' => $seller === Seller::Park ? PartyKind::Entrepreneur : PartyKind::Company, 'name' => $c['name'],
            'inn' => $c['inn'] ?? null, 'kpp' => $c['kpp'] ?? null, 'ogrn' => $c['ogrn'] ?? null, 'legal_address' => $c['address'] ?? null,
            'director' => $c['director'] ?? null, 'director_basis' => $seller === Seller::Park ? null : 'Устава',
            'full_name' => $c['full_name'] ?? null, 'director_full' => $c['director_full'] ?? null, 'signed_by' => $c['signed_by'] ?? null,
            'bank_name' => $c['bank'] ?? null, 'bik' => $c['bik'] ?? null, 'account' => $c['account'] ?? null, 'corr_account' => $c['corr_account'] ?? null,
            'phone' => $c['phone'] ?? null, 'email' => $c['email'] ?? null,
        ]);
    }

    /** Кто подписывает от нас: у ИП — сам предприниматель, у юрлица — генеральный директор (так у ПРАЙМ и Р.О.С. в уставе и `signed_by`; «Руководитель» — безлично, владелец 31.10.2026). */
    public function signerTitle(): string
    {
        return $this->kind === PartyKind::Entrepreneur ? 'Индивидуальный предприниматель' : 'Генеральный директор';
    }

    /**
     * Серия и номер паспорта порознь — для договора «паспорт серии 4512 № 345678» (серия без пробела — 07.11.2026, владелец). Храним одной строкой как ввели
     * («4512345678», «45 12 345678», «серия 4512 № 345678»): десять цифр — 4 + 6, иначе последние цифры от шести — номер.
     *
     * @return array{0: ?string, 1: ?string} серия, номер (не разобралось — серии нет, номер как вписан)
     */
    public function passportParts(): array
    {
        $raw = trim((string) $this->passport);
        $digits = preg_replace('/\D/', '', $raw);
        if (strlen($digits) === 10) {
            return [substr($digits, 0, 4), substr($digits, 4)];
        }
        if (preg_match('/^(.*?\d.*?)\s*№?\s*(\d{6,})$/u', $raw, $m)) {
            return [trim(preg_replace('/^серия\s*/iu', '', rtrim($m[1], ' №'))), $m[2]];
        }

        return [null, $raw ?: null];
    }

    /** Женщина ли по отчеству (-вна, -чна, кызы) — для «гражданка», «именуемая» в договоре; отчества нет — null. */
    public function isFemale(): ?bool
    {
        $last = mb_strtolower((string) last(preg_split('/\s+/u', trim((string) $this->name)) ?: ['']));

        return match (true) {
            (bool) preg_match('/(вна|чна|кызы)$/u', $last) => true,
            (bool) preg_match('/(вич|ич|оглы)$/u', $last) => false,
            default => null,
        };
    }

    /** Паспорт для договора: ФИО, дата рождения, серия и номер, кем и когда выдан, адрес регистрации (ДКП). */
    public function hasPassport(): bool
    {
        return filled($this->name) && $this->birth_at && filled($this->passport) && filled($this->passport_issued) && $this->passport_issued_at && filled($this->reg_address);
    }

    public function registerMediaCollections(): void
    {
        // Скан паспорта — только на закрытом диске, отдаёт `FileController` (админ, сам человек, его менеджер).
        $this->addMediaCollection('passport')->useDisk('private');
        // Карточка организации или ИП фотографией (10.11.2026, «Реквизиты фотографией» у менеджера): реквизиты по ней вносим мы.
        $this->addMediaCollection('card')->useDisk('private');
    }

    /** Скан страницы паспорта (`main` — разворот с фото, `reg` — прописка); новый заменяет прежний. */
    public function passportScan(string $page): ?Media
    {
        return $this->getMedia('passport')->first(fn (Media $m) => $m->getCustomProperty('page') === $page);
    }

    /** Поля паспорта, что пишет скан (`ReadPassport`); ФИО — своей дверью (`ReadPassport::passportName`). */
    public const SCAN_FIELDS = ['passport', 'passport_issued', 'passport_issued_at', 'passport_code', 'birth_at', 'birth_place', 'reg_address'];

    /**
     * Прочитанное со скана — в данные (13.11.2026, владелец по Мишину: «фотки прикреплены, а поля не распознались» — прочитанное
     * вставало только в открытую форму, закрыли раньше — лежало в файле): пустые поля заполняются, вписанное руками не трогается;
     * `$over` — «Распознать заново»: прочитанное поверх. Было что записать — true.
     */
    public function fillFromScan(bool $over = false): bool
    {
        $read = [];
        foreach (array_keys(self::PASSPORT_PAGES) as $page) {
            $media = $this->passportScan($page);
            if ($media?->getCustomProperty('read') === 'done') {
                $read += array_intersect_key((array) $media->getCustomProperty('fields', []), array_flip(self::SCAN_FIELDS));
            }
        }
        $set = array_filter($read, fn ($v, $k) => filled($v) && ($over || blank($this->getRawOriginal($k))), ARRAY_FILTER_USE_BOTH);
        if ($set === []) {
            return false;
        }
        $this->fill($set)->save();

        return $this->wasChanged();
    }

    /** Физлицо (и самозанятый): у него паспорт, у организации и ИП — реквизиты. */
    public function isPerson(): bool
    {
        return ! in_array($this->kind, [PartyKind::Company, PartyKind::Entrepreneur], true);
    }

    /**
     * Покупателю нужен скан паспорта: физлицу и ИП (06.11.2026, владелец: физлицо платит со своего ИП — поставщику
     * реквизиты ИП и PDF со сканом паспорта). У организации паспорта нет.
     */
    public function needsPassportScan(): bool
    {
        return $this->isPerson() || $this->kind === PartyKind::Entrepreneur;
    }

    /**
     * Как называть в документах: ИП, у которого в имени ФИО (так его вносят в форме покупателя), — «Индивидуальный
     * предприниматель Гамидов Тагир Гасинович»; имя уже с «ИП» и остальные — как есть.
     */
    public function title(): string
    {
        $name = trim((string) $this->name);

        return $this->kind === PartyKind::Entrepreneur && ! preg_match('/^(ип|индивидуальный\s+предприниматель)\b/iu', $name)
            ? 'Индивидуальный предприниматель '.$name : $name;
    }

    /** Обе страницы скана паспорта прикреплены. */
    public function hasPassportScans(): bool
    {
        return $this->passportScan('main') && $this->passportScan('reg');
    }

    /**
     * Покупатель готов к договору и счёту: как `readyForContract`, а физлицу — ещё и скан паспорта (11.10.2026, владелец:
     * «пока скан паспорта не прикрепят, счёт и договор нельзя сформировать» — руками ошибаются). Продавцу (собственник по
     * ДКП) скан не нужен: его паспорт вносит сотрудник по документам страховой.
     */
    public function readyAsBuyer(): bool
    {
        return $this->readyForContract() && (! $this->needsPassportScan() || $this->hasPassportScans())
            && ($this->kind !== PartyKind::Entrepreneur || ! $this->bankMissing());
    }

    /** Чего ещё нет у покупателя, когда сторона договора уже внесена: скана паспорта или банка ИП; null — всё есть или не внесена. */
    public function buyerGap(): ?string
    {
        return match (true) {
            ! $this->readyForContract() => null,
            $this->needsPassportScan() && ! $this->hasPassportScans() => 'нужен скан паспорта',
            $this->kind === PartyKind::Entrepreneur && $this->bankMissing() => 'нужен банк ИП',
            default => null,
        };
    }

    /** Чей это контрагент на сайте: сам человек (его `party_id`) или его менеджер — им можно прикреплять и смотреть скан. */
    public function managedBy(User $me): bool
    {
        return User::where('party_id', $this->id)->where(fn ($w) => $w->whereKey($me->id)->orWhere('manager_id', $me->id))->exists();
    }

    /** Сторона договора внесена: у физлица — паспорт, у организации и ИП — название, ИНН, адрес (у юрлица и руководитель). */
    public function readyForContract(): bool
    {
        return match ($this->kind) {
            PartyKind::Company => filled($this->name) && filled($this->inn) && filled($this->legal_address) && filled($this->director),
            PartyKind::Entrepreneur => filled($this->name) && filled($this->inn) && filled($this->ogrn) && filled($this->legal_address ?: $this->reg_address),
            default => $this->hasPassport(),
        };
    }

    /** Реквизитов для счёта не хватает — печатается с прочерком. */
    public function bankMissing(): bool
    {
        return ! $this->bank_name || ! $this->account || ! $this->bik;
    }

    /**
     * Контрагент вендора — его реквизиты, других нет: заводится при первом счёте или первой записи реквизитов.
     * Чтение (долги, чипы) базу не трогает: `$create = false` отдаёт незаписанный черновик без id.
     */
    public static function forVendor(Vendor $vendor, bool $create = true): self
    {
        if ($vendor->party) {
            return $vendor->party;
        }
        // Реквизиты вендора живут только здесь (29.10.2026): новый контрагент — с именем вендора и почтой бухгалтерии,
        // реквизиты вписывают в «Изменить» вендора или в «Реквизитах». Вендор-физлицо — контрагент-человек с телефоном контакта.
        $party = self::make($vendor->kind === Kind::Person
            ? ['kind' => PartyKind::Person, 'name' => $vendor->name, 'phone' => $vendor->contacts->first(fn ($c) => $c->phone)?->phone, 'email' => $vendor->email(ContactRole::Accounting)]
            : ['kind' => PartyKind::Company, 'name' => $vendor->name, 'email' => $vendor->email(ContactRole::Accounting)]);
        if ($create) {
            $party->save();
            $vendor->update(['party_id' => $party->id]);
        }

        return $party;
    }

    public static function forUser(User $user, bool $create = true): self
    {
        if ($user->party) {
            return $user->party;
        }
        $party = self::make(['kind' => PartyKind::Person, 'name' => $user->name, 'phone' => $user->phone, 'email' => $user->email]);
        if ($create) {
            $party->save();
            $user->update(['party_id' => $party->id]);
            // Связь `party` у этого экземпляра уже загружена пустой — без неё второй вызов в той же операции (гаражная
            // сделка: счёт за машину и доля) заводил второго контрагента тому же менеджеру (06.10.2026).
            $user->setRelation('party', $party);
        }

        return $party;
    }

    /** Покупатель из письма «продано»: физлицо по имени и телефону, кому выдаём. Без имени — некому. */
    public static function forPickup(Vehicle $vehicle, bool $create = true): ?self
    {
        if ($vehicle->buyerParty) {
            return $vehicle->buyerParty;
        }
        if (! $vehicle->pickup_name) {
            return null;
        }
        $party = self::make(['kind' => PartyKind::Person, 'name' => $vehicle->pickup_name, 'phone' => $vehicle->pickup_phone]);
        if ($create) {
            $party->save();
            $vehicle->update(['buyer_party_id' => $party->id]);
            $vehicle->setRelation('buyerParty', $party);
        }

        return $party;
    }

    /** Реквизиты строкой для счёта: по виду — ИНН, КПП, ОГРН и адрес; ИНН и ОГРНИП; ИНН самозанятого; паспорт и адрес. */
    public function details(): string
    {
        return implode(', ', array_filter(match ($this->kind) {
            PartyKind::Person => [$this->passport ? 'паспорт '.$this->passport : null, $this->passport_issued, $this->reg_address],
            PartyKind::SelfEmployed => [$this->inn ? 'ИНН '.$this->inn : null, 'самозанятый', $this->reg_address],
            PartyKind::Entrepreneur => [$this->inn ? 'ИНН '.$this->inn : null, $this->ogrn ? 'ОГРНИП '.$this->ogrn : null, $this->legal_address],
            default => [$this->inn ? 'ИНН '.$this->inn : null, $this->kpp ? 'КПП '.$this->kpp : null, $this->ogrn ? 'ОГРН '.$this->ogrn : null, $this->legal_address],
        }));
    }

    /**
     * Реквизиты строками для письма поставщику (03.11.2026, Альфа: «пришлите реквизиты для выставления счёта»): человек —
     * ФИО, рождение, паспорт, кем и когда выдан, код, адрес; организация и ИП — название, адрес, ИНН, КПП, ОГРН(ИП),
     * руководитель, р/с, банк, БИК, к/с. Скан паспорта физлица и ИП идёт PDF во вложении (`MailController`).
     */
    public function requisitesText(): string
    {
        $date = fn ($d) => $d?->format('d.m.Y');

        return implode("\n", array_filter($this->kind === PartyKind::Person || $this->kind === null ? [
            $this->name,
            $this->birth_at ? 'Дата рождения: '.$date($this->birth_at).($this->birth_place ? ', '.$this->birth_place : '') : null,
            // «Паспорт: 8207 445349» — без «№» и пробела в серии (07.11.2026, владелец).
            $this->passport ? 'Паспорт: '.implode(' ', array_filter($this->passportParts())) : null,
            $this->passport_issued ? 'Выдан: '.$this->passport_issued.($this->passport_issued_at ? ' '.$date($this->passport_issued_at) : '') : null,
            $this->passport_code ? 'Код подразделения: '.$this->passport_code : null,
            $this->reg_address ? 'Адрес регистрации: '.$this->reg_address : null,
        ] : [
            // Организация и ИП — как реквизиты в банке (06.11.2026, ИП Гамидова): каждое строкой со своей подписью.
            $this->title(),
            ($this->legal_address ?: $this->reg_address) ? 'Адрес: '.($this->legal_address ?: $this->reg_address) : null,
            $this->inn ? 'ИНН '.$this->inn.($this->kpp ? ', КПП '.$this->kpp : '') : null,
            $this->ogrn ? ($this->kind === PartyKind::Entrepreneur ? 'ОГРНИП ' : 'ОГРН ').$this->ogrn : null,
            $this->director ? 'Руководитель: '.$this->director : null,
            $this->account ? 'Расчётный счёт: '.$this->account : null,
            $this->bank_name ? 'Банк: '.$this->bank_name : null,
            $this->bik ? 'БИК: '.$this->bik : null,
            $this->corr_account ? 'Корр. счёт: '.$this->corr_account : null,
        ]));
    }

    public function bankDetails(): string
    {
        return implode(', ', array_filter([$this->bank_name, $this->bik ? 'БИК '.$this->bik : null, $this->account ? 'р/с '.$this->account : null, $this->corr_account ? 'к/с '.$this->corr_account : null,
            ! $this->account && $this->card ? 'карта '.$this->card : null]));
    }

    /** Есть куда перечислить: счёт с БИК или карта. */
    public function payoutReady(): bool
    {
        return ($this->account && $this->bik) || $this->card;
    }

    /** Есть чем назвать: для юрлица и ИП — ИНН, для человека — паспорт или карта. Без этого реквизиты «не указаны». */
    /**
     * Можно ли выставлять счёт: у юрлица нужны ИНН и банк, у человека — паспорт, карта или счёт. Иначе счёт
     * напечатается с прочерками вместо реквизитов, а бухгалтерия вендора его не примет.
     */
    public function billable(): bool
    {
        return $this->filled() && ($this->kind === PartyKind::Person || ! $this->bankMissing());
    }

    public function filled(): bool
    {
        return match ($this->kind) {
            PartyKind::Company, PartyKind::Entrepreneur, PartyKind::SelfEmployed => (bool) $this->inn,
            default => (bool) ($this->passport || $this->card || $this->account),
        };
    }
}
