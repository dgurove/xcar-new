<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Числа, которые человек набирает обычной клавиатурой (цифровой нет нигде — на iOS в ней нет запятой):
 * «1 500 000», «1 500,50 ₽», «0,6» приходят к валидации как «1500000», «1500.50», «0.6». Только поля с этими
 * именами (на любой глубине: rows.*.price) и только когда после чистки остаётся число — иначе как было,
 * пусть валидация скажет, что не так. Объём двигателя в литрах разбирает свой `Liters::parse`.
 */
class NormalizeNumbers
{
    private const FIELDS = [
        'amount', 'price', 'cost', 'qty', 'sum', 'commission', 'sold_price', 'asking_price', 'floor_price', 'publish_price',
        'min_bid_price', 'min_bid_share', 'assigned_price', 'storage_rate', 'buyer_rate_multiplier', 'reward_value',
        'mileage', 'year', 'engine_power', 'capacity', 'distance_km', 'km_included', 'keys_count', 'limit_value',
        'payment_days', 'answer_hours', 'binding_days', 'from_day', 'from_value',
    ];

    public function handle(Request $request, Closure $next)
    {
        if (! $request->isMethod('GET')) {
            $request->merge($this->clean($request->request->all()));
        }

        return $next($request);
    }

    private function clean(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->clean($value);
            } elseif (is_string($value) && in_array($key, self::FIELDS, true)) {
                $plain = str_replace(',', '.', preg_replace('/[\s\x{00A0}\x{202F}₽]+/u', '', $value));
                if (preg_match('/^-?\d+(\.\d+)?$/', $plain)) {
                    $data[$key] = $plain;
                }
            }
        }

        return $data;
    }
}
