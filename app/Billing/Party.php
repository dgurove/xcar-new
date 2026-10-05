<?php

namespace App\Billing;

use App\Park\Vehicle;
use App\Support\Demo\HidesDemo;
use App\Users\User;
use App\Vendors\ContactRole;
use App\Vendors\Kind;
use App\Vendors\Vendor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Контрагент счёта: юрлицо, ИП, самозанятый или физлицо с паспортом. Строки `is_self` — мы, по одной на продавца (`seller`). */
#[Fillable(['kind', 'name', 'is_self', 'seller', 'inn', 'kpp', 'ogrn', 'legal_address', 'director', 'director_basis', 'bank_name', 'bik', 'account', 'corr_account',
    'passport', 'passport_issued', 'passport_issued_at', 'passport_code', 'reg_address', 'birth_at', 'birth_place', 'phone', 'email', 'card', 'payment_purpose', 'notes', 'vat_on_top'])]
class Party extends Model
{
    use HidesDemo;

    protected $table = 'billing_parties';

    protected function casts(): array
    {
        return ['kind' => PartyKind::class, 'is_self' => 'bool', 'seller' => Seller::class, 'birth_at' => 'date', 'passport_issued_at' => 'date', 'vat_on_top' => 'bool'];
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'party_id');
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
            'director' => $c['director'] ?? null, 'director_basis' => $seller === Seller::Prime ? 'Устава' : null,
            'bank_name' => $c['bank'] ?? null, 'bik' => $c['bik'] ?? null, 'account' => $c['account'] ?? null, 'corr_account' => $c['corr_account'] ?? null,
            'phone' => $c['phone'] ?? null, 'email' => $c['email'] ?? null,
        ]);
    }

    /** Кто подписывает от нас: у ИП — сам предприниматель, у юрлица — руководитель. */
    public function signerTitle(): string
    {
        return $this->kind === PartyKind::Entrepreneur ? 'Индивидуальный предприниматель' : 'Руководитель';
    }

    /** Паспорт для договора: ФИО, дата рождения, серия и номер, кем и когда выдан, адрес регистрации (ДКП). */
    public function hasPassport(): bool
    {
        return filled($this->name) && $this->birth_at && filled($this->passport) && filled($this->passport_issued) && $this->passport_issued_at && filled($this->reg_address);
    }

    /** Сторона договора внесена: у физлица — паспорт, у организации и ИП — название, ИНН, адрес (у юрлица и руководитель). */
    public function readyForContract(): bool
    {
        return match ($this->kind) {
            PartyKind::Company => filled($this->name) && filled($this->inn) && filled($this->legal_address) && filled($this->director),
            PartyKind::Entrepreneur => filled($this->name) && filled($this->inn) && filled($this->legal_address),
            default => $this->hasPassport(),
        };
    }

    /** Реквизитов для счёта не хватает — печатается с прочерком. */
    public function bankMissing(): bool
    {
        return ! $this->bank_name || ! $this->account || ! $this->bik;
    }

    /**
     * Контрагент вендора: создаётся из его реквизитов при первом счёте, дальше живёт своей жизнью.
     * Чтение (долги, чипы) базу не трогает: `$create = false` отдаёт незаписанный черновик без id.
     */
    public static function forVendor(Vendor $vendor, bool $create = true): self
    {
        if ($vendor->party) {
            return $vendor->party;
        }
        // Вендор-физлицо — контрагент-человек: без ИНН и банка, с телефоном контакта.
        $party = self::make($vendor->kind === Kind::Person
            ? ['kind' => PartyKind::Person, 'name' => $vendor->name, 'phone' => $vendor->contacts->first(fn ($c) => $c->phone)?->phone, 'email' => $vendor->email(ContactRole::Accounting), 'legal_address' => $vendor->legal_address]
            : [
                'kind' => PartyKind::Company, 'name' => $vendor->legal_name ?: $vendor->name, 'inn' => $vendor->inn, 'kpp' => $vendor->kpp,
                'legal_address' => $vendor->legal_address, 'bank_name' => $vendor->bank_name, 'bik' => $vendor->bank_bic, 'account' => $vendor->bank_account,
                'corr_account' => $vendor->bank_corr, 'payment_purpose' => $vendor->payment_purpose, 'email' => $vendor->email(ContactRole::Accounting),
            ]);
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
