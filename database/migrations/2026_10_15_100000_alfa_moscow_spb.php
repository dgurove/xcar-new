<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * АльфаСтрахование — два вендора: Москва (домен `alfastrah.ru`) и СПб (адреса Колбасиной и Филипповой).
 * Правила у них разные: в Питере страховая пишет в письме, до какого числа хранение за её счёт, и
 * опоздавший покупатель платит сам, а в Москве хранение целиком на страховой. Одной строкой вендора это
 * не настроить, поэтому машины, письма, кандидаты, контакты и прайс делятся по питерской парковке и по
 * отправителю письма. `Vendor::forSender` берёт точный адрес раньше домена — новые письма разойдутся сами.
 */
return new class extends Migration
{
    /** Питерские адреса Альфы: по ним делятся и письма, и машины (решение владельца 22.09.2026). */
    private const SENDERS = ['kolbasinamp@alfastrah.ru', 'filippovaiaiu@alfastrah.ru'];

    private const YARD = 'Краснопутиловская';

    private const MOSCOW = 'АльфаСтрахование Москва';

    private const SPB = 'АльфаСтрахование СПб';

    public function up(): void
    {
        $alfa = DB::table('vendors')->whereIn('name', ['АльфаСтрахование', self::MOSCOW])->first();
        if (! $alfa || DB::table('vendors')->where('name', self::SPB)->exists()) {
            return;
        }
        $row = (array) $alfa;
        unset($row['id'], $row['party_id']);
        $spb = DB::table('vendors')->insertGetId(array_merge($row, [
            'name' => self::SPB,
            'senders' => json_encode(self::SENDERS, JSON_UNESCAPED_UNICODE),
            // Опоздавший покупатель платит только в Питере: срок вписывают на ТС из письма.
            'buyer_pays_late' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
        DB::table('vendors')->where('id', $alfa->id)->update([
            'name' => self::MOSCOW,
            'senders' => json_encode(array_values(array_diff(json_decode($alfa->senders ?: '[]', true) ?: [], self::SENDERS)), JSON_UNESCAPED_UNICODE),
            'buyer_pays_late' => false,
            'updated_at' => now(),
        ]);

        // Прайс копией: договор один, цены те же.
        foreach (DB::table('park_tariffs')->where('vendor_id', $alfa->id)->whereNull('valid_to')->get() as $tariff) {
            $copy = (array) $tariff;
            unset($copy['id']);
            DB::table('park_tariffs')->insert(array_merge($copy, ['vendor_id' => $spb, 'created_at' => now(), 'updated_at' => now()]));
        }

        $yard = DB::table('park_yards')->where('name', self::YARD)->value('id');
        $byMail = DB::table('mail_threads')->join('mail_messages', 'mail_messages.thread_id', '=', 'mail_threads.id')
            ->whereIn('mail_messages.from_email', self::SENDERS)->whereNotNull('mail_threads.vehicle_id')
            ->distinct()->pluck('mail_threads.vehicle_id')->all();
        // Питерская ТС: стоит на питерской парковке или про неё писали питерские адреса.
        if ($yard || $byMail) {
            DB::table('park_vehicles')->where('vendor_id', $alfa->id)
                ->where(function ($q) use ($yard, $byMail) {
                    $yard && $q->orWhere('yard_id', $yard);
                    $byMail && $q->orWhereIn('id', $byMail);
                })->update(['vendor_id' => $spb, 'updated_at' => now()]);
        }

        // Письма: ветки питерских машин и ветки с питерскими адресами, даже если машина ещё не заведена.
        $threads = DB::table('mail_threads')->where('vendor_id', $alfa->id)
            ->where(function ($q) use ($spb) {
                $q->whereIn('vehicle_id', DB::table('park_vehicles')->where('vendor_id', $spb)->select('id'))
                    ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('mail_messages')
                        ->whereColumn('mail_messages.thread_id', 'mail_threads.id')->whereIn('mail_messages.from_email', self::SENDERS));
            })->pluck('id')->all();
        if ($threads) {
            DB::table('mail_threads')->whereIn('id', $threads)->update(['vendor_id' => $spb]);
            DB::table('mail_candidates')->where('vendor_id', $alfa->id)->whereIn('thread_id', $threads)->update(['vendor_id' => $spb]);
        }

        // Контакт по питерскому адресу или с питерской парковкой — к своему вендору.
        DB::table('vendor_contacts')->where('vendor_id', $alfa->id)
            ->where(function ($q) use ($yard) {
                $q->whereIn('email', self::SENDERS);
                $yard && $q->orWhere('yard_id', $yard);
            })->update(['vendor_id' => $spb]);
    }

    public function down(): void
    {
        throw new RuntimeException('Обратно Альфа не склеивается: у Питера свои правила, письма и прайс');
    }
};
