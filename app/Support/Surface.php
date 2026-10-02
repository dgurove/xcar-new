<?php

namespace App\Support;

use App\Users\Section;
use App\Users\User;

/**
 * Поверхность — одно из трёх приложений на своём хосте: сайт, CRM, стоянка (гараж — раздел сайта /garage).
 * Определяется по хосту запроса в ResolveSurface; вне запроса — сайт.
 */
enum Surface: string
{
    case Site = 'site';
    case Crm = 'crm';
    case Park = 'park';

    public static function fromHost(string $host): self
    {
        return match ($host) {
            config('xcar.crm_host') => self::Crm,
            config('xcar.park_host') => self::Park,
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

    public function host(): string
    {
        return match ($this) {
            self::Site => parse_url(config('app.url'), PHP_URL_HOST) ?: 'localhost',
            self::Crm => config('xcar.crm_host'),
            self::Park => config('xcar.park_host'),
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
     * Единственный дом человека: управляющему — парковка, модератору — CRM. С чужого хоста их уводят туда
     * (SiteWall, ResolveSurface, вход, регистрация по ссылке); остальные работают там, где вошли.
     */
    public static function onlyFor(?User $user): ?self
    {
        return match (true) {
            (bool) $user?->isParking() => self::Park,
            (bool) $user?->isModerator() => self::Crm,
            default => null,
        };
    }

    /** Кому хост открыт: CRM — сотрудникам, стоянка — по разделу. Одна дверь на ResolveSurface и вход. */
    public function opensFor(?User $user): bool
    {
        return match ($this) {
            self::Site => true,
            self::Crm => (bool) $user?->isStaff(),
            self::Park => (bool) $user?->canAccess(Section::Park),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Site => 'XCar',
            self::Crm => 'CRM XCar',
            self::Park => 'Park XCar',
        };
    }

    /** Имя под иконкой на экране «Домой» — совпадает с label(), в 12 знаков укладывается. */
    public function short(): string
    {
        return $this->label();
    }

    /** Где начинается работа после входа: на сайте и парковке `/` — лендинг для гостя, списки на своих адресах. */
    public function home(): string
    {
        return match ($this) {
            self::Site => '/offers',
            self::Park => '/requests',
            default => '/',
        };
    }
}
