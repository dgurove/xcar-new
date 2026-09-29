<?php

namespace App\Support;

use App\Users\Section;
use App\Users\User;

/**
 * Поверхность — одно из четырёх приложений на своём хосте: сайт, CRM, стоянка, гараж.
 * Определяется по хосту запроса в ResolveSurface; вне запроса — сайт.
 */
enum Surface: string
{
    case Site = 'site';
    case Crm = 'crm';
    case Park = 'park';
    case Garage = 'garage';

    public static function fromHost(string $host): self
    {
        return match ($host) {
            config('xcar.crm_host') => self::Crm,
            config('xcar.park_host') => self::Park,
            config('xcar.garage_host') => self::Garage,
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
            self::Garage => config('xcar.garage_host'),
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
     * Кому хост открыт: CRM — сотрудникам, стоянка — по разделу, гараж — сотрудникам и
     * менеджерам (машины в гараже есть только у них). Одна дверь на ResolveSurface и вход.
     */
    public function opensFor(?User $user): bool
    {
        return match ($this) {
            self::Site => true,
            self::Crm => (bool) $user?->isStaff(),
            self::Park => (bool) $user?->canAccess(Section::Park),
            self::Garage => (bool) ($user?->isStaff() || $user?->isManager()),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Site => 'XCar',
            self::Crm => 'CRM XCar',
            self::Park => 'Park XCar',
            self::Garage => 'Гараж XCar',
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
