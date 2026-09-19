<?php

namespace App\Billing;

use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Контрагент счёта: юрлицо с реквизитами или физлицо с паспортом. Одна строка `is_self` — мы. */
#[Fillable(['kind', 'name', 'is_self', 'inn', 'kpp', 'ogrn', 'legal_address', 'director', 'director_basis', 'bank_name', 'bik', 'account', 'corr_account',
    'passport', 'passport_issued', 'reg_address', 'birth_at', 'phone', 'email', 'payment_purpose', 'notes'])]
class Party extends Model
{
    protected $table = 'billing_parties';

    protected function casts(): array
    {
        return ['kind' => PartyKind::class, 'is_self' => 'bool', 'birth_at' => 'date'];
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'party_id');
    }

    /** Мы. Строки нет (свежая база, снесли руками) — заводится из конфига, реквизиты банка дозаполняют на экране. */
    public static function self(): self
    {
        $company = config('xcar.company', []);

        return self::firstOrCreate(['is_self' => true], [
            'kind' => PartyKind::Company, 'name' => $company['name'] ?? 'ООО «ПРАЙМ»', 'inn' => $company['inn'] ?? null,
            'director' => $company['director'] ?? null, 'director_basis' => 'Устава',
        ]);
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
        $party = self::make([
            'kind' => PartyKind::Company, 'name' => $vendor->legal_name ?: $vendor->name, 'inn' => $vendor->inn, 'kpp' => $vendor->kpp,
            'legal_address' => $vendor->legal_address, 'bank_name' => $vendor->bank_name, 'bik' => $vendor->bank_bic, 'account' => $vendor->bank_account,
            'corr_account' => $vendor->bank_corr, 'payment_purpose' => $vendor->payment_purpose, 'email' => $vendor->email(),
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

    /** Реквизиты строкой для счёта: ИНН, КПП, адрес — или паспорт и адрес. */
    public function details(): string
    {
        return implode(', ', array_filter($this->kind === PartyKind::Person
            ? [$this->passport ? 'паспорт '.$this->passport : null, $this->passport_issued, $this->reg_address]
            : [$this->inn ? 'ИНН '.$this->inn : null, $this->kpp ? 'КПП '.$this->kpp : null, $this->ogrn ? 'ОГРН '.$this->ogrn : null, $this->legal_address]));
    }

    public function bankDetails(): string
    {
        return implode(', ', array_filter([$this->bank_name, $this->bik ? 'БИК '.$this->bik : null, $this->account ? 'р/с '.$this->account : null, $this->corr_account ? 'к/с '.$this->corr_account : null]));
    }
}
