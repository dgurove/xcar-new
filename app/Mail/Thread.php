<?php

namespace App\Mail;

use App\Mail\Extraction\Keys;
use App\Offers\Offer;
use App\Park\City;
use App\Park\Vehicle;
use App\Search\Term;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Ветка писем. `keys` — её номера (code:/vin:/plate:, см. Extraction\Keys), `candidate_id` — кандидат «Из писем»,
 * `archived_at` — убрана из «Входящих» (как в Gmail: найдётся поиском, новое чужое письмо вернёт).
 */
#[Fillable(['account_id', 'root_message_id', 'subject', 'subject_normalized', 'participants', 'last_message_at', 'messages_count', 'unread_count', 'has_attachments', 'offer_id', 'vehicle_id', 'buyer', 'unlinked_at', 'keys', 'candidate_id', 'archived_at', 'vendor_id'])]
class Thread extends Model
{
    /** Управляющий с городом видит ветки парковки только своего города (`Park\City`); ветки CRM область не трогает. */
    protected static function booted(): void
    {
        static::addGlobalScope(City::SCOPE, fn (Builder $q) => ($city = City::viewing()) ? City::threads($q, $city) : null);
    }

    protected $table = 'mail_threads';

    protected function casts(): array
    {
        return ['participants' => 'array', 'last_message_at' => 'datetime', 'has_attachments' => 'bool', 'buyer' => 'bool', 'unlinked_at' => 'datetime', 'keys' => 'array', 'archived_at' => 'datetime',
            'needs_reply_at' => 'datetime', 'answered_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'thread_id')->orderBy('date_at');
    }

    /** Последнее письмо ветки — строке списка нужны его первые слова (`preview`). */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class, 'thread_id')->latestOfMany('date_at');
    }

    /** Последнее входящее — его смысл идёт тегом в строку почты и решает, ждёт ли ветка ответа. */
    public function latestIncoming(): HasOne
    {
        return $this->hasOne(Message::class, 'thread_id')->ofMany(['date_at' => 'max'], fn ($q) => $q->where('direction', Direction::In));
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function attachments(): HasManyThrough
    {
        return $this->hasManyThrough(Attachment::class, Message::class, 'thread_id', 'message_id');
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class, 'candidate_id');
    }

    /**
     * Ветки ящиков парковки. Сами по себе письма CRM (offer@, deal@) к делу ТС не относятся; вместе их показывает
     * `ofCar` — тем, у кого есть почта другой стороны.
     */
    public function scopePark($query)
    {
        return $query->whereHas('account', fn ($q) => $q->where('scope', Scope::Park));
    }

    /**
     * Письма одной машины. На парковке — только ветки ТС из ящика парковки (08.10.2026, владелец: «в park.xcar должно
     * попадать только то, что на storage@»): письма CRM (offer@, deal@) туда не идут, даже у связанной с предложением ТС.
     * В CRM (владелец 06.10.2026: «в CRM по парковке») — ветки предложения и, тому, у кого есть «Почта» парковки, ветки
     * ТС той же машины на парковке (`Cars\Car`); отвечают на них её ящиком, как и раньше.
     */
    public function scopeOfCar($query, Offer|Vehicle $car, ?User $user)
    {
        $offers = fn ($q) => $q->whereIn('account_id', Account::where('scope', Scope::Offers)->select('id'));
        if ($car instanceof Vehicle) {
            return $query->where('vehicle_id', $car->id)->park();
        }
        $park = $user?->canManagePark();

        return $query->where(fn ($w) => $w->where(fn ($o) => $offers($o->where('offer_id', $car->id)))
            ->when($park, fn ($w) => $w->orWhere(fn ($p) => $p->whereIn('vehicle_id', Vehicle::where('car_id', $car->car_id)->select('id'))->park())));
    }

    /** Ветки с любым из номеров (`keys` пересекаются с `$keys`). */
    /**
     * Поиск по письмам — одна дверь для почты CRM и парковки и шапок: полнотекст с префиксом последнего слова, участники,
     * имена файлов, номера ветки целиком (`Keys::fromQuery`) и куском («05481» из «0890/046/05481/26»: полнотекст держит
     * номер с косыми одним словом).
     */
    public function scopeSearch(Builder $query, string|Term $term): Builder
    {
        $t = Term::of($term);
        if ($t->blank()) {
            return $query;
        }
        $q = $t->raw;
        $tsquery = self::tsquery($q);

        return $query->where(fn ($w) => $w
            ->when($tsquery, fn ($w, $ts) => $w->whereHas('messages', fn ($m) => $m->whereRaw("search @@ to_tsquery('russian', ?)", [$ts])))
            ->orWhereRaw('participants::text ilike ?', [Term::like($q)])
            ->orWhereHas('attachments', fn ($a) => $a->where('filename', 'ilike', Term::like($q)))
            ->when(Keys::fromQuery($q), fn ($w, $keys) => $w->orWhere(fn ($k) => $k->withAnyKey($keys)))
            ->when($t->key && mb_strlen($t->key) >= 4, fn ($w) => $w->orWhereRaw('mail_threads.keys::text ilike ?', [Term::like($t->key)])));
    }

    /**
     * Запрос для полнотекста. `websearch_to_tsquery` ищет только целые слова, а при наборе последнее
     * слово почти всегда неполное — оно уходит с префиксом, и «колбас» находит «Колбасина». Строка
     * разбирается здесь, а не в SQL: `to_tsquery` падает на любом постороннем знаке.
     */
    private static function tsquery(string $q): ?string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($q), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (! $words) {
            return null;
        }
        $last = array_key_last($words);

        return implode(' & ', array_map(fn ($w, $i) => $w.($i === $last ? ':*' : ''), $words, array_keys($words)));
    }

    public function scopeWithAnyKey($query, array $keys)
    {
        // `?|` спорит с плейсхолдерами PDO — та же проверка функцией.
        return $keys ? $query->whereRaw('jsonb_exists_any(keys, array['.implode(',', array_fill(0, count($keys), '?')).']::text[])', array_values($keys)) : $query->whereRaw('false');
    }

    /** Ветка ведётся по машине: файлы её писем закрепляются у нас, а не живут только в ящике. */
    public function isLinked(): bool
    {
        return $this->offer_id !== null || $this->vehicle_id !== null;
    }

    /** Собеседники без нас самих. */
    public function counterparts(): array
    {
        $own = mb_strtolower((string) $this->account?->email);

        return array_values(array_filter($this->participants ?? [], fn ($p) => ($p['email'] ?? '') !== $own));
    }
}
