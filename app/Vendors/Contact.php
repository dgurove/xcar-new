<?php

namespace App\Vendors;

use App\Park\Yard;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Человек у вендора: роль говорит, по какому вопросу к нему; `always_cc` — держать в копии каждого письма.
 * Парковка — у кого убытки по городу: машины из его писем стоят там, и заводятся сразу на неё.
 */
#[Fillable(['vendor_id', 'name', 'title', 'role', 'email', 'phone', 'yard_id', 'always_cc', 'is_default', 'notes'])]
class Contact extends Model
{
    protected $table = 'vendor_contacts';

    protected function casts(): array
    {
        return ['role' => ContactRole::class, 'always_cc' => 'bool', 'is_default' => 'bool'];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function yard(): BelongsTo
    {
        return $this->belongsTo(Yard::class, 'yard_id');
    }

    /** Чьи это письма и где стоят его машины: точный адрес, домен не в счёт — город у каждого свой. */
    public static function yardFor(?string $email): ?Yard
    {
        $email = mb_strtolower(trim((string) $email));

        return $email === '' ? null : static::whereNotNull('yard_id')->whereRaw('lower(email) = ?', [$email])->first()?->yard;
    }

    public function phoneFormatted(): string
    {
        return Phone::format(Phone::normalize($this->phone) ?? $this->phone);
    }

    public function phoneDigits(): string
    {
        return (string) preg_replace('/\D+/', '', (string) $this->phone);
    }
}
