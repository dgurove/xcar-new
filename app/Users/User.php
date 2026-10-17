<?php

namespace App\Users;

use App\Billing\Party;
use App\Chats\Chat;
use App\Media\MediaUrl;
use App\Notifications\PasswordResetLetter;
use App\Offers\Actions\SyncViewers;
use App\Offers\Interest;
use App\Offers\Offer;
use App\Park\City;
use App\Search\Term;
use App\Support\Demo\HidesDemo;
use App\Support\Phone;
use App\Support\Surface;
use App\Telegram\Bot;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Laragear\WebAuthn\Contracts\WebAuthnAuthenticatable;
use Laragear\WebAuthn\WebAuthnAuthentication;
use Laragear\WebAuthn\WebAuthnData;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

#[Fillable(['name', 'first_name', 'last_name', 'phone', 'login', 'email', 'password', 'roles', 'access', 'notification_settings', 'approved_at', 'approved_by', 'rejected_at', 'manager_id', 'invite_id', 'contact_fields', 'park_readonly', 'party_id', 'park_city_id', 'buyer_sees', 'buyer_show_vin', 'logist_id', 'logistics_city', 'cdek_point'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements HasMedia, WebAuthnAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, InteractsWithMedia, Notifiable, WebAuthnAuthentication;

    use HidesDemo;

    /** Стал менеджером или перестал — круги показа опубликованных пересчитываются: правило «все» его касается. */
    protected static function booted(): void
    {
        // Имя и фамилия — два поля (05.10.2026), `name` — их сборка «Имя Фамилия»: по нему ищут, сортируют и рисуют
        // короткое имя. Код, что пишет только `name` (команды, фабрика), получает разбивку по первому слову.
        static::saving(function (self $user) {
            if ($user->isDirty(['first_name', 'last_name'])) {
                $user->name = trim($user->first_name.' '.$user->last_name);
            } elseif ($user->isDirty('name') || $user->first_name === null) {
                $parts = preg_split('/\s+/u', trim((string) $user->name), 2) ?: [];
                $user->first_name = $parts[0] ?? '';
                $user->last_name = $parts[1] ?? null;
            }
        });
        static::saved(function (self $user) {
            if ($user->wasChanged('roles') || ($user->wasRecentlyCreated && ($user->isManager() || $user->isSuperBuyer()))) {
                app(SyncViewers::class)->all();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'roles' => AsEnumCollection::of(Role::class),
            'access' => 'array',
            'park_readonly' => 'bool',
            'notification_settings' => 'array',
            'seen_at' => 'datetime',
            'list_prefs' => 'array',
            'contact_fields' => 'array',
            'buyer_show_vin' => 'bool',
            'email_verified_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'telegram_linked_at' => 'datetime',
        ];
    }

    public function setPhoneAttribute(?string $value): void
    {
        $this->attributes['phone'] = $value === null || $value === '' ? null : (Phone::normalize($value) ?? $value);
    }

    /** Логин хранится строчными: вход и уникальность без оглядки на регистр. */
    public function setLoginAttribute(?string $value): void
    {
        $this->attributes['login'] = $value === null || trim($value) === '' ? null : mb_strtolower(trim($value));
    }

    // -------------------------------------------------------------- роли

    /**
     * Ролей у человека может быть несколько (04.10.2026): менеджер и модератор, админ и менеджер… Покупатель — только
     * один (он человек своего менеджера). Способность человека — если её даёт хоть одна его роль (Role::canX).
     */
    public function hasRole(Role ...$any): bool
    {
        $roles = $this->roles ?? collect();

        return collect($any)->contains(fn (Role $r) => $roles->contains($r));
    }

    /** Люди с любой из ролей: `User::withRole(Role::Manager)`. */

    /**
     * Поиск человека — одна дверь для «Пользователей», покупателей менеджера, «За менеджера», чатов, сделок и шапок: имя,
     * логин, почта, телефон цифрами (от трёх — иначе поиск по имени находил всех с телефоном).
     */
    public function scopeSearch(Builder $q, string|Term $term): Builder
    {
        $t = Term::of($term);
        if ($t->blank()) {
            return $q;
        }
        $like = Term::like($t->raw);
        $digits = (string) preg_replace('/\D+/', '', $t->raw);

        return $q->where(fn ($w) => $w->where('users.name', 'ilike', $like)->orWhere('users.login', 'ilike', $like)->orWhere('users.email', 'ilike', $like)
            ->when(strlen($digits) >= 3, fn ($w) => $w->orWhere('users.phone', 'like', Term::like($digits))));
    }

    public function scopeWithRole(Builder $query, Role ...$roles): Builder
    {
        return $query->where(function ($w) use ($roles) {
            foreach ($roles as $role) {
                $w->orWhereJsonContains('roles', $role->value);
            }
        });
    }

    private function roleCan(string $ability): bool
    {
        return ($this->roles ?? collect())->contains(fn (Role $r) => $r->{$ability}());
    }

    /** Роли словами через запятую: «Менеджер, Модератор». */
    public function rolesLabel(): string
    {
        return ($this->roles ?? collect())->map(fn (Role $r) => $r->label())->implode(', ');
    }

    public function canSeePrices(): bool
    {
        return $this->roleCan('canSeePrices');
    }

    public function canBid(): bool
    {
        return $this->roleCan('canBid');
    }

    /**
     * Форма подтверждения на сайте: у менеджера — своё, у админа — за менеджера (08.10.2026, владелец: «как и менеджеры
     * жмёт подтвердить… только в финале спрашивает, за какого менеджера»; `Site\BidController` → `PlaceBidFor`).
     */
    public function bidsOnSite(): bool
    {
        return $this->canBid() || $this->isAdmin();
    }

    public function canShare(): bool
    {
        return $this->roleCan('canShare');
    }

    /** Интерес — покупателю и гостю; у кого есть подтверждение ценой (менеджер, админ) — интереса нет. */
    public function canInterest(): bool
    {
        return $this->roleCan('canInterest') && ! $this->canBid();
    }

    /**
     * Интерес к этому предложению: в галерее — менеджеру, покупателю и посетителю (владелец 08.10.2026: «чтобы все могли
     * оставлять интерес»), на открытом — тем, кто не подтверждает ценой.
     */
    public function canInterestIn(Offer $offer): bool
    {
        if ($offer->isGallery()) {
            // Админ на сайте видит галерею как менеджер (08.10.2026) — и интерес оставляет так же.
            return $this->hasRole(Role::Manager, Role::Buyer, Role::SuperBuyer, Role::Visitor, Role::Admin);
        }

        return $offer->state->acceptsInterest() && $this->canInterest();
    }

    // -------------------------------------------------------------- покупатели и менеджер

    public function isBuyer(): bool
    {
        return $this->hasRole(Role::Buyer);
    }

    /**
     * Покупатель видит всё, что открыто его менеджеру (12.10.2026, владелец; `users.buyer_sees = all`, по умолчанию), а не
     * только открытое вручную (`picked`). Одна дверь видимости, «нового», VIN и уведомлений о новых предложениях.
     */
    public function buyerSeesAll(): bool
    {
        return $this->isBuyer() && ($this->buyer_sees ?? 'all') !== 'picked';
    }

    public function isManager(): bool
    {
        return $this->hasRole(Role::Manager);
    }

    /**
     * Супер-покупатель (10.10.2026): покупает у нас без менеджера — видит предложения своей волны (все супер-покупатели
     * в волнах как один менеджер, `AudienceRules`), только цену продажи, подтверждает ценой и ведёт свои сделки, как
     * менеджер; покупателей, гаража, закупок и шеринга у него нет. Сам себя он видит «Покупателем» (`Role::selfLabel`).
     */
    public function isSuperBuyer(): bool
    {
        return $this->hasRole(Role::SuperBuyer);
    }

    /** Свои сделки и деньги по ним на сайте: менеджер и супер-покупатель (`ability:hasDeals`). */
    public function hasDeals(): bool
    {
        return $this->isManager() || $this->isSuperBuyer();
    }

    /**
     * Менеджер под замком (07.10.2026, владелец: ему видна заявленная — по сути закупочная): одно устройство, вход только
     * через Telegram, окно 15 минут (`Access\Lock`). Админа, совмещающего менеджера, и демо-кабинет не касается.
     */
    public function underLock(): bool
    {
        return $this->isManager() && ! $this->isAdmin() && ! $this->is_demo;
    }

    /** Чат по предложению: по роли, а покупателю — только со своим менеджером. */
    public function canChat(): bool
    {
        return $this->roleCan('canChat') && (! $this->isBuyer() || $this->manager_id);
    }

    /** Закупки по роли; демо-кабинету закрыты — закупки настоящие. */
    public function canSeePurchases(): bool
    {
        return $this->roleCan('canSeePurchases') && ! $this->is_demo;
    }

    /** Гараж по роли; демо-кабинету его нет — гаражных машин у него не бывает. */
    public function canGarage(): bool
    {
        return $this->roleCan('canGarage') && ! $this->is_demo;
    }

    /** Менеджер покупателя — тот, чью пригласительную ссылку он открыл. */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function buyers(): HasMany
    {
        return $this->hasMany(User::class, 'manager_id')->whereJsonContains('roles', Role::Buyer->value);
    }

    /** Группы, в которых состоит покупатель. */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(BuyerGroup::class, 'buyer_group_user', 'user_id', 'group_id')->withPivot('created_at');
    }

    /** Группы покупателя id-шниками — круг видимости спрашивает их на каждый подсчёт: в запросе раз. */
    public function groupIds(): array
    {
        if ($this->relationLoaded('groups')) {
            return $this->groups->modelKeys();
        }
        $ids = fn () => $this->groups()->pluck('buyer_groups.id')->all();

        return app()->runningInConsole() ? $ids() : once($ids);
    }

    /** Группы, которые завёл менеджер. */
    public function ownGroups(): HasMany
    {
        return $this->hasMany(BuyerGroup::class, 'manager_id')->orderBy('position')->orderBy('id');
    }

    public function invites(): HasMany
    {
        return $this->hasMany(Invite::class, 'manager_id');
    }

    public function invite(): BelongsTo
    {
        return $this->belongsTo(Invite::class);
    }

    public function interests(): HasMany
    {
        return $this->hasMany(Interest::class);
    }

    /**
     * Может ли покупатель указывать этот контакт. Менеджер решает при создании
     * ссылки; у всех остальных ролей ограничений нет.
     */
    public function mayHave(string $field): bool
    {
        return ! $this->isBuyer() || in_array($field, $this->contact_fields ?? [], true);
    }

    /** Чем человек входит: логин, телефон или почта — первое, что есть. */
    public function loginLabel(): string
    {
        return $this->login ?: ($this->phone ? $this->phoneFormatted() : (string) $this->email);
    }

    public function isStaff(): bool
    {
        return $this->roleCan('isStaff');
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(Role::Admin);
    }

    /** Вся CRM, а не только черновики (Role::canManageCrm) — для `ability:canManageCrm` и проверок в видах. */
    public function canManageCrm(): bool
    {
        return $this->roleCan('canManageCrm');
    }

    public function isModerator(): bool
    {
        return $this->hasRole(Role::Moderator);
    }

    /** Раздел CRM сверх черновиков (почта): админу — всё, модератору — отмеченное. */
    public function canCrm(CrmArea $area): bool
    {
        return $this->isAdmin() || ($this->isModerator() && in_array($area->value, $this->access ?? [], true));
    }

    /**
     * Модераторы его групп, он сам — первым: их предложения он видит и правит (`Offer::visibleTo`). Без группы — один.
     *
     * @return list<int>
     */
    public function teamIds(): array
    {
        $ids = fn () => array_values(array_unique([$this->id, ...DB::table('user_group_user as mine')
            ->join('user_groups as g', fn ($j) => $j->on('g.id', '=', 'mine.group_id')->where('g.kind', UserGroup::MODERATORS))
            ->join('user_group_user as theirs', 'theirs.group_id', '=', 'mine.group_id')
            ->where('mine.user_id', $this->id)->pluck('theirs.user_id')->all()]));

        // В очереди объект живёт весь job — там считаем каждый раз, как unreadCount.
        return app()->runningInConsole() ? $ids() : once($ids);
    }

    /** Группы человека в «Пользователях»: менеджеру — для волн показа, модератору — общие предложения (UserGroup). */
    public function userGroups(): BelongsToMany
    {
        return $this->belongsToMany(UserGroup::class, 'user_group_user', 'user_id', 'group_id')->orderBy('position')->orderBy('name');
    }

    /** В группы видов его ролей (менеджер — волны, модератор — общие предложения), только они. Чужого вида id отбрасываются. */
    public function syncUserGroups(array $ids): void
    {
        $kinds = ($this->roles ?? collect())->map(fn (Role $r) => UserGroup::kindFor($r))->filter()->values()->all();
        $this->userGroups()->sync($kinds ? UserGroup::whereIn('kind', $kinds)->whereIn('id', array_map('intval', $ids))->pluck('id')->all() : []);
    }

    /** Почта CRM — для `ability:canCrmMail` на маршрутах. */
    public function canCrmMail(): bool
    {
        return $this->canCrm(CrmArea::Mail);
    }

    /** Сайт закрыт: внутрь — сотрудник или тот, кому доступ открыли. */
    public function isApproved(): bool
    {
        return $this->approved_at !== null || $this->isStaff();
    }

    public function isRejected(): bool
    {
        return $this->rejected_at !== null && ! $this->isApproved();
    }

    public function isPending(): bool
    {
        return ! $this->isApproved() && ! $this->isRejected();
    }

    /**
     * Управляющий: вся парковка на всех парковках — заявки, приём, выдача, оплаты, счета, закрытие месяца, почта,
     * контакты вендоров и реквизиты плательщиков. Не он — помощник «только приёмка» (`isParkHelper`).
     * Настройки парковки (прайс, правила вендоров, парковки, свои реквизиты, люди) — `isParkAdmin`; продажа — `isAdmin`.
     */
    public function canManagePark(): bool
    {
        return $this->isParkAdmin() || ($this->isParking() && ! $this->park_readonly);
    }

    /** Управляющий парковкой: только park.xcar, без продаж и настроек. */
    /**
     * Город, которым ограничена парковка человека (`Park\City`): видит только его ТС, письма и счета. Пусто — весь парк;
     * у администратора парковки — всегда весь.
     */
    public function parkCity(): ?int
    {
        return City::of($this);
    }

    /** Раздел «Парковки» — тому, у кого их больше одной: управляющему одной парковки он ни к чему (владелец 09.10.2026). */
    public function seesYards(): bool
    {
        $city = $this->parkCity();

        return ! $city || count(City::yardIds($city)) > 1;
    }

    public function isParking(): bool
    {
        return $this->hasRole(Role::Parking);
    }

    /**
     * Администратор парковки — своя роль (владелец 08.10.2026): админ CRM без неё на парковку не входит, а с ней —
     * настройки парковки и её люди. У Дмитрия и Андрея она есть, у Екатерины — нет.
     */
    public function isParkAdmin(): bool
    {
        return $this->hasRole(Role::ParkAdmin);
    }

    /** Помощник на воротах («только приёмка»): заявки, приём и выдача; без оплат, почты, вендоров и правки ТС. */
    public function isParkHelper(): bool
    {
        return $this->isParking() && $this->park_readonly && ! $this->isParkAdmin();
    }

    /** Сайтовое (избранное, чаты, интересы, поддержка, поток каталога) — всем, у кого сайт есть: не только парковка или логистика. */
    public function usesSite(): bool
    {
        return ! in_array(Surface::onlyFor($this), [Surface::Park, Surface::Logistics], true);
    }

    // -------------------------------------------------------------- логистика

    /** Логист (Татьяна): вывозы в регионах — назначает своих исполнителей, вводит расстояние и цену. */
    public function isLogist(): bool
    {
        return $this->hasRole(Role::Logist);
    }

    /** Наш человек на вывозе (ООО «ПРАЙМ»): только свои задачи. */
    public function isDriver(): bool
    {
        return $this->hasRole(Role::Driver);
    }

    /** Исполнитель логиста в регионах: только свои задачи, заводит его логист. */
    public function isContractor(): bool
    {
        return $this->hasRole(Role::Contractor);
    }

    /** Чей исполнитель (логист, что его позвал). */
    public function logist(): BelongsTo
    {
        return $this->belongsTo(self::class, 'logist_id');
    }

    /** Исполнители логиста. */
    public function contractors(): HasMany
    {
        return $this->hasMany(self::class, 'logist_id');
    }

    /** Исполнители, которых видит человек: логисту — свои, админу — все исполнители логистов. */
    public static function contractorsFor(self $user): Builder
    {
        return self::withRole(Role::Contractor)->when(! $user->isAdmin(), fn ($q) => $q->where('logist_id', $user->id));
    }

    /**
     * logistics.xcar.ru: админ — всё; логист — свои вывозы и исполнители; наши люди и исполнители логиста — свои задачи;
     * модератор — «Документы» (СДЭК, 17.10.2026: оформляет Лиза). Менеджеру логистики нет: забирает сам — в своей сделке.
     */
    public function canLogistics(): bool
    {
        return $this->isAdmin() || $this->isLogist() || $this->isDriver() || $this->isContractor() || $this->isModerator();
    }

    /** Все задачи и назначение — админу. */
    public function seesAllTransports(): bool
    {
        return $this->isAdmin();
    }

    /** Исполнители логиста — сам логист и админ. */
    public function managesContractors(): bool
    {
        return $this->isAdmin() || $this->isLogist();
    }

    /** Отправка документов СДЭКом — модератору (Лизе) и админу. */
    public function sendsLogisticsDocs(): bool
    {
        return $this->isAdmin() || $this->isModerator();
    }

    /** Кто работает на парковке: администраторы парковки и управляющие — список «Кто ведёт», уведомления, закрытие месяца. */
    public function scopeParkStaff($query)
    {
        return $query->withRole(Role::ParkAdmin, Role::Parking);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'party_id');
    }

    /** Парковка — администраторам парковки и управляющим; админу CRM без роли «Администратор парковки», менеджерам и прочим — нет. */
    public function canAccess(Section $section): bool
    {
        return $section === Section::Park && ($this->isParkAdmin() || $this->isParking());
    }

    public function wantsMail(): bool
    {
        return ($this->notification_settings['mail'] ?? true) !== false;
    }

    /** Звук важных уведомлений в приложении — по умолчанию включён. */
    public function wantsSound(): bool
    {
        return ($this->notification_settings['sound'] ?? true) !== false;
    }

    /** Категория уведомлений не выключена (Notifications\Categories). */
    public function wants(string $category): bool
    {
        return ! in_array($category, $this->notification_settings['off'] ?? [], true);
    }

    /** Тихие часы включены и сейчас ночь по Москве: пуш не шлём, лента и почта работают. */
    public function quietHours(): bool
    {
        if (! ($this->notification_settings['quiet'] ?? false)) {
            return false;
        }
        $h = (int) now('Europe/Moscow')->format('G');

        return $h >= 22 || $h < 8;
    }

    /** Telegram привязан и не выключен в настройках уведомлений. */
    /** Telegram привязан и включён; с категорией — она ещё и не выключена «в Telegram» (`telegram_off`, Categories::telegram). */
    public function wantsTelegram(?string $category = null): bool
    {
        $s = $this->notification_settings ?? [];

        return $this->telegram_chat_id !== null && ($s['telegram'] ?? true) !== false
            && ($category === null || ! in_array($category, $s['telegram_off'] ?? [], true));
    }

    /**
     * Подключить Telegram можно: менеджеру, его покупателю и супер-покупателю (12.10.2026: машины от менеджера и чат),
     * админу, модератору (вход в CRM, новое из писем) и парковке без привязки. Не за человека —
     * админ привязал бы свой чат к чужому аккаунту.
     */
    public function canLinkTelegram(): bool
    {
        return ($this->isManager() || $this->isBuyer() || $this->isSuperBuyer() || $this->isAdmin() || $this->isModerator() || $this->isParking() || $this->isParkAdmin() || $this->isLogist() || $this->isDriver() || $this->isContractor()) && $this->telegram_chat_id === null && ! $this->is_demo
            && app(Bot::class)->username() !== null;
    }

    /**
     * Привязать по-настоящему можно только свой Telegram: под «Войти как» админ видит всё, что видит человек,
     * но шторка в Telegram не ведёт — иначе к чужому аккаунту привязался бы Telegram админа (решение владельца 01.10.2026).
     */
    public function linksOwnTelegram(): bool
    {
        return ! Impersonation::active();
    }

    /**
     * Когда шторка подключения открывается сама (решение владельца 30.09.2026):
     * intro — один раз за всё время при входе; bid — после подтверждения ценой (покупателю — после интереса), не чаще раза в неделю и не больше
     * трёх раз; card — карточка в «Сделках», пока не скрыли. Отметки ставит TelegramController::seen.
     *
     * @return list<'intro'|'bid'|'card'>
     */
    public function telegramMoments(): array
    {
        $s = $this->notification_settings ?? [];
        $bidAt = $s['telegram_bid_at'] ?? null;
        $hidden = $s['telegram_card_hidden'] ?? null;

        return array_values(array_filter([
            empty($s['telegram_intro']) ? 'intro' : null,
            ($s['telegram_bid_n'] ?? 0) < 3 && (! $bidAt || now()->subDays(7)->greaterThan($bidAt)) ? 'bid' : null,
            ! $hidden || now()->greaterThan($hidden) ? 'card' : null,
        ]));
    }

    /** Подписанная ссылка «не присылать на почту» — работает без входа. */
    /** «Забыл пароль» — с боевого ящика: системный мейлер на проде выключен. */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new PasswordResetLetter($token));
    }

    public function unsubscribeUrl(): string
    {
        return URL::signedRoute('mail.unsubscribe', ['user' => $this->id]);
    }

    /**
     * Уведомления этого приложения (`data.surface`): парковка — только свои строки (владелец, 05.10.2026), CRM — свои и
     * общие, сайт — только общие: то, что ведёт в CRM, на сайте не показываем (08.10.2026). Вне запроса хост называют
     * явно (пуш — по хосту подписки).
     */
    public function noticesHere(?Surface $surface = null)
    {
        return match ($surface ?? Surface::current()) {
            Surface::Park => $this->notifications()->where('data->surface', 'park'),
            Surface::Logistics => $this->notifications()->where('data->surface', 'logistics'),
            Surface::Crm => $this->notifications()->where(fn ($q) => $q->whereNull('data->surface')->orWhere('data->surface', 'crm')),
            Surface::Site => $this->notifications()->whereNull('data->surface'),
        };
    }

    /** Непрочитанные уведомления; в запросе считаются раз — их читают значок приложения, шапка и таб-бар. */
    public function unreadCount(?Surface $surface = null): int
    {
        $count = fn () => $this->noticesHere($surface)->whereNull('read_at')->count();

        // В очереди объект человека живёт весь job — там считаем каждый раз (значок пуша после нового уведомления).
        return app()->runningInConsole() || $surface ? $count() : once($count);
    }

    /** Бейдж приложения: непрочитанные уведомления и сообщения в чатах вместе; у парковки чатов нет. */
    public function badgeCount(?Surface $surface = null): int
    {
        $here = $surface ?? Surface::current();

        // Чаты площадки — работа CRM: на значке сайта их нет (08.10.2026).
        // На парковке и в логистике чатов нет — их непрочитанное в значок приложения не идёт.
        return $this->unreadCount($surface) + (in_array($here, [Surface::Park, Surface::Logistics], true) ? 0 : $this->unreadChats(platform: $here === Surface::Crm));
    }

    /** Непрочитанное в чатах: свои чаты и чаты покупателей, где человек — вторая сторона; сотруднику — и площадки. */
    public function unreadChats(bool $platform = true): int
    {
        $staff = $this->isAdmin() && $platform;
        $own = $this->canChat();
        if (! $staff && ! $own) {
            return 0;
        }

        // Одним запросом (было три суммы), в запросе — раз: его читают значок приложения, шапка и таб-бар.
        $sum = function () use ($staff, $own) {
            $sums = Chat::query()
                ->where(fn ($q) => $q
                    ->when($own, fn ($q) => $q->where('user_id', $this->id)->orWhere('manager_id', $this->id))
                    ->when($staff, fn ($q) => $q->orWhereNull('manager_id')))
                ->selectRaw('coalesce(sum(case when user_id = ? then unread_for_user end), 0) as mine,
                    coalesce(sum(case when manager_id = ? then unread_for_staff end), 0) as buyers,
                    coalesce(sum(case when manager_id is null then unread_for_staff end), 0) as platform', [$this->id, $this->id])
                ->toBase()->first();

            return ($own ? (int) $sums->mine + (int) $sums->buyers : 0) + ($staff ? (int) $sums->platform : 0);
        };

        return app()->runningInConsole() ? $sum() : once($sum);
    }

    /** «в сети» — был здесь только что; иначе когда: «в сети 12:40», «в сети вчера», «в сети 12 сен». */
    public function seenLabel(): ?string
    {
        $at = $this->seen_at;
        if (! $at) {
            return null;
        }
        if ($at->gt(now()->subMinutes(2))) {
            return 'в сети';
        }

        return 'в сети '.match (true) {
            $at->isToday() => $at->format('H:i'),
            $at->isYesterday() => 'вчера',
            default => $at->translatedFormat('j M'),
        };
    }

    /** Подпись ключа в связке устройства: почта, телефон или логин и имя. Пакет ждёт строки, а почты может не быть. */
    public function webAuthnData(): WebAuthnData
    {
        $handle = $this->email ?: ($this->phone ? $this->phoneFormatted() : (string) $this->login);

        return WebAuthnData::make($handle, $this->name ?: $handle);
    }

    public function phoneFormatted(): string
    {
        return $this->phone ? Phone::format($this->phone) : '';
    }

    // -------------------------------------------------------------- имя и аватар

    /** «Иван П.» — для капсулы в шапке и таб-бара. */
    public function shortName(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];
        if (count($parts) < 2) {
            return $this->name;
        }

        return $parts[0].' '.mb_substr($parts[1], 0, 1).'.';
    }

    /** «Иван» — обращение в приветствии. */
    public function firstName(): string
    {
        return preg_split('/\s+/', trim($this->name))[0] ?? $this->name;
    }

    /** Одна-две буквы для кружка без фото. */
    public function initials(): string
    {
        $parts = array_slice(preg_split('/\s+/', trim($this->name)) ?: [], 0, 2);

        return mb_strtoupper(implode('', array_map(fn ($p) => mb_substr($p, 0, 1), $parts))) ?: '·';
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('avatar')->useDisk('media')->singleFile();
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')->fit(Fit::Crop, 128, 128)->format('webp')->nonQueued();
    }

    /** Жадная загрузка аватара для списков людей: без неё каждый кружок — свой запрос. `$path` — 'media', 'user.media'. */
    public static function withAvatar(string $path = 'media'): array
    {
        return [$path => fn ($m) => $m->where('collection_name', 'avatar')];
    }

    public function avatarUrl(): ?string
    {
        $media = $this->getFirstMedia('avatar');

        return $media ? MediaUrl::for($media, 'thumb') : null;
    }
}
