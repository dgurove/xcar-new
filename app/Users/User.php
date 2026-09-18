<?php

namespace App\Users;

use App\Chats\Chat;
use App\Media\MediaUrl;
use App\Offers\Interest;
use App\Park\Yard;
use App\Support\Phone;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\URL;
use Laragear\WebAuthn\Contracts\WebAuthnAuthenticatable;
use Laragear\WebAuthn\WebAuthnAuthentication;
use Laragear\WebAuthn\WebAuthnData;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

#[Fillable(['name', 'phone', 'login', 'email', 'password', 'role', 'access', 'notification_settings', 'approved_at', 'approved_by', 'rejected_at', 'manager_id', 'invite_id', 'contact_fields', 'park_yard_id', 'park_readonly'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements HasMedia, WebAuthnAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, InteractsWithMedia, Notifiable, WebAuthnAuthentication;

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => Role::class,
            'access' => 'array',
            'park_readonly' => 'bool',
            'notification_settings' => 'array',
            'seen_at' => 'datetime',
            'list_prefs' => 'array',
            'contact_fields' => 'array',
            'email_verified_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
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

    // -------------------------------------------------------------- покупатели и менеджер

    public function isBuyer(): bool
    {
        return $this->role === Role::Buyer;
    }

    public function isManager(): bool
    {
        return $this->role === Role::Manager;
    }

    /** Чат по предложению: по роли, а покупателю — только со своим менеджером. */
    public function canChat(): bool
    {
        return $this->role->canChat() && (! $this->isBuyer() || $this->manager_id);
    }

    /** Менеджер покупателя — тот, чью пригласительную ссылку он открыл. */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function buyers(): HasMany
    {
        return $this->hasMany(User::class, 'manager_id')->where('role', Role::Buyer);
    }

    /** Группы, в которых состоит покупатель. */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(BuyerGroup::class, 'buyer_group_user', 'user_id', 'group_id')->withPivot('created_at');
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
        return $this->role->isStaff();
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
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

    /** Стоянка: править вендоров, площадки, реквизиты ТС, отменять и удалять — не «только приёмка». */
    public function canManagePark(): bool
    {
        return $this->isAdmin() || ($this->canAccess(Section::Park) && ! $this->park_readonly);
    }

    public function parkYard(): BelongsTo
    {
        return $this->belongsTo(Yard::class, 'park_yard_id');
    }

    public function canAccess(Section $section): bool
    {
        return $this->isAdmin() || in_array($section->value, $this->access ?? [], true);
    }

    public function wantsMail(): bool
    {
        return ($this->notification_settings['mail'] ?? true) !== false;
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

    /** Подписанная ссылка «не присылать на почту» — работает без входа. */
    public function unsubscribeUrl(): string
    {
        return URL::signedRoute('mail.unsubscribe', ['user' => $this->id]);
    }

    public function unreadCount(): int
    {
        return $this->unreadNotifications()->count();
    }

    /** Бейдж приложения: непрочитанные уведомления и сообщения в чатах вместе. */
    public function badgeCount(): int
    {
        return $this->unreadCount() + $this->unreadChats();
    }

    /** Непрочитанное в чатах: свои чаты и чаты покупателей, где человек — вторая сторона. */
    public function unreadChats(): int
    {
        $staff = $this->isStaff() ? (int) Chat::whereNull('manager_id')->sum('unread_for_staff') : 0;
        if (! $this->canChat()) {
            return $staff;
        }

        return $staff + (int) Chat::where('user_id', $this->id)->sum('unread_for_user') + (int) Chat::where('manager_id', $this->id)->sum('unread_for_staff');
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

    public function avatarUrl(): ?string
    {
        $media = $this->getFirstMedia('avatar');

        return $media ? MediaUrl::for($media, 'thumb') : null;
    }
}
