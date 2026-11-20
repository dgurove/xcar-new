<?php

namespace App\Support;

use App\Users\Role;
use App\Users\Section;
use App\Users\User;

/**
 * Поверхность — одно из пяти приложений на своём хосте: сайт, CRM, стоянка, логистика (гараж — раздел сайта /garage) и
 * партнёр — partner.xcar.ru для поставщиков (07.11.2026), чат — chat.xcar.ru (20.11.2026): те же чаты сайта и CRM отдельным
 * приложением, вид — по человеку (`Chat::staffView`), сделка — листом поверх чата (`x-chat.deal-sheet`).
 * Определяется по хосту запроса в ResolveSurface; вне запроса — сайт.
 */
enum Surface: string
{
    case Site = 'site';
    case Crm = 'crm';
    case Park = 'park';
    case Logistics = 'logistics';
    case Partner = 'partner';
    case Chat = 'chat';

    public static function fromHost(string $host): self
    {
        return match ($host) {
            config('xcar.crm_host') => self::Crm,
            config('xcar.park_host') => self::Park,
            config('xcar.logistics_host') => self::Logistics,
            config('xcar.partner_host') => self::Partner,
            config('xcar.chat_host') => self::Chat,
            default => self::Site,
        };
    }

    /** Поверхность запроса; до middleware (404 на чужом пути, страницы ошибок) — по хосту. */
    public static function current(): self
    {
        if (app()->bound(self::class)) {
            return app(self::class);
        }

        return app()->runningInConsole() || ! app()->has('request') ? self::Site : self::fromHost(request()->getHost());
    }

    /** Запрос из CRM — там видны внутренние номера (убыток, ДЛ) и умолчания шеринга сотрудника. */
    public static function inCrm(): bool
    {
        return self::current() === self::Crm;
    }

    public function host(): string
    {
        return match ($this) {
            self::Site => parse_url(config('app.url'), PHP_URL_HOST) ?: 'localhost',
            self::Crm => config('xcar.crm_host'),
            self::Park => config('xcar.park_host'),
            self::Logistics => config('xcar.logistics_host'),
            self::Partner => config('xcar.partner_host'),
            self::Chat => config('xcar.chat_host'),
        };
    }

    /** Абсолютная ссылка на эту поверхность: схема и порт — из app.url. */
    public function url(string $path = '/'): string
    {
        $app = parse_url(config('app.url'));
        $port = isset($app['port']) ? ':'.$app['port'] : '';

        return ($app['scheme'] ?? 'http').'://'.$this->host().$port.'/'.ltrim($path, '/');
    }

    /**
     * Дом человека, которому сайт не нужен вовсе (ни одной роли сайта: менеджер, админ, покупатель, проверяющий,
     * посетитель): модератору — CRM, управляющему — парковка. С хоста, который ему закрыт, уводят туда (SiteWall,
     * ResolveSurface, вход, регистрация по ссылке); с открытого — не уводят: модератор с ролью «Парковка» работает в обоих.
     * Логисту, нашим людям на вывозе и исполнителям логиста — логистика. Поставщику — партнёр, всегда и первым: роль не
     * совмещается, и ни сайт, ни CRM ему не открываются никогда (07.11.2026).
     */
    public static function onlyFor(?User $user): ?self
    {
        if ($user?->isSupplier()) {
            return self::Partner;
        }
        if (! $user || $user->hasRole(Role::Manager, Role::Admin, Role::Buyer, Role::SuperBuyer, Role::Reviewer, Role::Visitor)) {
            return null;
        }

        return match (true) {
            $user->isModerator() => self::Crm,
            $user->isParking() || $user->isParkAdmin() => self::Park,
            $user->isLogist() || $user->isDriver() || $user->isContractor() => self::Logistics,
            default => null,
        };
    }

    /** Где дом аккаунта: хост, без которого ему нельзя (`onlyFor`), иначе сайт. По нему связанные аккаунты делят хосты (`UseLinkedAccount`). */
    public static function homeFor(User $user): self
    {
        return self::onlyFor($user) ?? self::Site;
    }

    /** Кому хост открыт: CRM — сотрудникам, стоянка — по разделу, логистика — логисту, водителю, партнёру и админу. Одна дверь на ResolveSurface и вход. */
    public function opensFor(?User $user): bool
    {
        return match ($this) {
            self::Site => true,
            self::Crm => (bool) $user?->isStaff(),
            self::Park => (bool) $user?->canAccess(Section::Park),
            self::Logistics => (bool) $user?->canLogistics(),
            self::Partner => (bool) $user?->isSupplier(),
            self::Chat => (bool) $user && ($user->canEditDeals() || $user->canChat()),
        };
    }

    public function label(): string
    {
        return match ($this) {
            // 20.11.2026, владелец: «XCAR, XCAR CRM, XCAR Park, XCAR Logistics», чат — «XCAR Chat».
            self::Site => 'XCAR',
            self::Crm => 'XCAR CRM',
            self::Park => 'XCAR Park',
            self::Logistics => 'XCAR Logistics',
            self::Partner => 'XCAR Partner',
            self::Chat => 'XCAR Chat',
        };
    }

    /** Имя под иконкой на экране «Домой» — label(), а логистика в 12 знаков не укладывается. */
    public function short(): string
    {
        return $this === self::Logistics ? 'Logistics' : $this->label();
    }

    /**
     * Где начинается работа после входа: на сайте и парковке `/` — лендинг для гостя, списки на своих адресах. В CRM
     * `/` — редирект сюда же (космос не прижился, 17.11.2026, `archive/space`): вход, иконка и «Войти как» ведут на предложения.
     */
    public function home(): string
    {
        return match ($this) {
            self::Site, self::Crm, self::Partner => '/offers',
            self::Park => '/requests',
            self::Logistics => '/transports',
            self::Chat => '/chats',
        };
    }

    /**
     * Куда ведёт логотип вошедшего: в CRM — предложения с открытым колесом вкладок (17.11.2026, владелец: вместо космоса;
     * `tab_wheel_controller` снимает `#tabs` и открывает колесо, на `/offers` — на месте), у остальных — в начало работы.
     */
    public function brand(): string
    {
        return $this === self::Crm ? '/offers#tabs' : $this->home();
    }
}
