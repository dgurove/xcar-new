<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Логист и исполнитель — раздельно (18.10.2026, владелец: «логист и исполнитель недостаточно ясно разделены»): логист,
 * что распределяет, — `logist_id` (было `coordinator_id`); как назначен вывоз — `channel` (было `executor`, и
 * `executor = logist` читалось «исполнитель — логист»); исполнитель — `executor_id`, как был. Логист сам не везёт: открытым
 * задачам, где он стоял исполнителем, исполнитель снимается — задача снова у логиста.
 *
 * История задачи — `transport_events`: что, кто нажал (`by_id`) и за кого (`for_id`, исполнитель, когда нажал не он).
 * Прошлое — из столбцов задач, «кто» — из строк «Вывоз: …» истории предложения в те же секунды.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transports', function (Blueprint $t) {
            $t->renameColumn('coordinator_id', 'logist_id');
            $t->renameColumn('executor', 'channel');
        });
        DB::table('transports')->whereColumn('executor_id', 'logist_id')->whereIn('state', ['logist', 'assigned', 'agreed'])
            ->update(['executor_id' => null, 'state' => 'logist', 'trip_code' => null]);

        Schema::create('transport_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('transport_id')->constrained('transports')->cascadeOnDelete();
            $t->string('type', 24);
            $t->foreignId('by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('for_id')->nullable()->constrained('users')->nullOnDelete();
            $t->jsonb('payload')->nullable();
            $t->timestamp('created_at')->index();
            $t->index(['transport_id', 'id']);
        });

        $this->backfill();
    }

    private function backfill(): void
    {
        $notes = fn (?int $offerId) => $offerId ? DB::table('offer_events')->where('offer_id', $offerId)
            ->whereIn('type', ['note', 'pickup_assigned'])->get(['created_at', 'user_id', 'type', 'payload']) : collect();
        foreach (DB::table('transports')->orderBy('id')->cursor() as $t) {
            $offerId = $t->origin === 'sale' ? DB::table('offers')->where('car_id', $t->car_id)->value('id') : null;
            $lines = $notes($offerId);
            // Кто нажал — строка «Вывоз: …» предложения в те же секунды (у назначения автора не писали).
            $who = function (?string $at, string $starts) use ($lines): ?int {
                if (! $at) {
                    return null;
                }
                $line = $lines->first(fn ($l) => abs(strtotime($l->created_at) - strtotime($at)) <= 10
                    && str_starts_with((string) (json_decode((string) $l->payload, true)['text'] ?? ''), 'Вывоз: '.$starts));

                return $line?->user_id;
            };
            $rows = [];
            $add = function (?string $at, string $type, ?int $by = null, array $payload = []) use (&$rows, $t) {
                if ($at) {
                    $rows[] = ['transport_id' => $t->id, 'type' => $type, 'by_id' => $by, 'for_id' => null,
                        'payload' => $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null, 'created_at' => $at];
                }
            };
            $add($t->created_at, 'opened', $t->created_by);
            if ($t->logist_id) {
                $add($t->assigned_at ?? $t->created_at, 'logist', null, ['logist' => $t->logist_id]);
            }
            if ($t->executor_id) {
                $add($t->assigned_at ?? $t->created_at, 'assigned', null, ['executor' => $t->executor_id]);
            }
            foreach (json_decode((string) $t->call_attempts, true) ?: [] as $try) {
                $add(date('Y-m-d H:i:s', strtotime($try['at'])), 'missed', $try['by'] ?? null);
            }
            $add($t->agreed_at, 'called', $who($t->agreed_at, 'созвонились'));
            $add($t->docs_sent_at, 'docs_sent', $t->docs_sent_by, $t->docs_track ? ['track' => $t->docs_track] : []);
            $add($t->docs_received_at, 'docs_received', $who($t->docs_received_at, 'документы получены'));
            $add($t->picked_at, 'picked', $who($t->picked_at, 'вывезена'));
            $add($t->delivered_at, 'delivered', $who($t->delivered_at, 'привёз'));
            $add($t->cancelled_at, 'cancelled', $who($t->cancelled_at, 'отменён'), $t->cancel_reason ? ['reason' => $t->cancel_reason] : []);
            // Исполнитель при шаге — тот, что стоит в задаче: нажал не он — «за него».
            foreach ($rows as &$row) {
                if ($row['by_id'] && $t->executor_id && $row['by_id'] !== $t->executor_id && in_array($row['type'], ['called', 'missed', 'docs_received', 'picked', 'delivered'], true)) {
                    $row['for_id'] = $t->executor_id;
                }
            }
            unset($row);
            usort($rows, fn ($a, $b) => strcmp($a['created_at'], $b['created_at']));
            if ($rows) {
                DB::table('transport_events')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_events');
        Schema::table('transports', function (Blueprint $t) {
            $t->renameColumn('logist_id', 'coordinator_id');
            $t->renameColumn('channel', 'executor');
        });
    }
};
