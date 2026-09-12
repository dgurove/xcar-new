<?php

namespace App\Support;

/**
 * Поверхность — одно из трёх приложений на своём хосте: сайт, CRM, стоянка.
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

    public static function current(): self
    {
        return app()->bound(self::class) ? app(self::class) : self::Site;
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

    /** Слово слева от логотипа в шапке; у сайта его нет. */
    public function word(): ?string
    {
        return match ($this) {
            self::Site => null,
            self::Crm => 'CRM',
            self::Park => 'PARK',
        };
    }

    public function home(): string
    {
        return '/';
    }
}
