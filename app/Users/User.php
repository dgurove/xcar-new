<?php

namespace App\Users;

use App\Media\MediaUrl;
use App\Support\Phone;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laragear\WebAuthn\Contracts\WebAuthnAuthenticatable;
use Laragear\WebAuthn\WebAuthnAuthentication;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

#[Fillable(['name', 'phone', 'email', 'password', 'role', 'access', 'notification_settings', 'approved_at', 'approved_by', 'rejected_at'])]
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
            'notification_settings' => 'array',
            'list_prefs' => 'array',
            'email_verified_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function setPhoneAttribute(?string $value): void
    {
        $this->attributes['phone'] = Phone::normalize($value) ?? $value;
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

    public function canAccess(Section $section): bool
    {
        return $this->isAdmin() || in_array($section->value, $this->access ?? [], true);
    }

    public function wantsMail(): bool
    {
        return ($this->notification_settings['mail'] ?? true) !== false;
    }

    public function unreadCount(): int
    {
        return $this->unreadNotifications()->count();
    }

    public function phoneFormatted(): string
    {
        return Phone::format($this->phone);
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
