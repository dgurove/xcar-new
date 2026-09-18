<?php

namespace App\Vendors;

use App\Support\Phone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Человек у вендора: роль говорит, по какому вопросу к нему; `always_cc` — держать в копии каждого письма. */
#[Fillable(['vendor_id', 'name', 'title', 'role', 'email', 'phone', 'always_cc', 'is_default', 'notes'])]
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

    public function phoneFormatted(): string
    {
        return Phone::format(Phone::normalize($this->phone) ?? $this->phone);
    }

    public function phoneDigits(): string
    {
        return (string) preg_replace('/\D+/', '', (string) $this->phone);
    }
}
