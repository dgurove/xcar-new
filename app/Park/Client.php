<?php

namespace App\Park;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Заказчик хранения — страховая или компания. Домены писем нужны, чтобы письмо сразу знало, чьё оно. */
#[Fillable(['name', 'contacts', 'sender_domains', 'notes'])]
class Client extends Model
{
    protected $table = 'park_clients';

    protected function casts(): array
    {
        return ['contacts' => 'array', 'sender_domains' => 'array'];
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class, 'client_id');
    }

    public function email(): ?string
    {
        foreach ($this->contacts ?? [] as $c) {
            if (! empty($c['email'])) {
                return $c['email'];
            }
        }

        return null;
    }

    public static function forSender(?string $email): ?self
    {
        $domain = $email ? mb_strtolower((string) preg_replace('/.*@/', '', $email)) : null;

        return $domain ? self::whereJsonContains('sender_domains', $domain)->first() : null;
    }
}
