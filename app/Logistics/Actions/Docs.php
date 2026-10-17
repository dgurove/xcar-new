<?php

namespace App\Logistics\Actions;

use App\Logistics\Events\TransportMoved;
use App\Logistics\Transport;
use App\Users\User;
use Illuminate\Validation\ValidationException;

/**
 * Документы исполнителю без печати (17.10.2026, владелец): их оформляет модератор (Лиза), отправляет СДЭКом и вводит
 * трек — исполнитель видит его, копирует и смотрит у СДЭКа; получил — отмечает, и только тогда «Забрал».
 */
final class Docs
{
    public function send(Transport $t, string $track, User $by): Transport
    {
        if (! $by->sendsLogisticsDocs()) {
            throw ValidationException::withMessages(['docs_track' => 'Документы отправляет модератор']);
        }
        $track = strtoupper(preg_replace('/\s+/u', '', $track));
        if (! $t->docs_by_cdek || ! $t->executor_id || ! $t->isOpen() || $t->state->picked()) {
            throw ValidationException::withMessages(['docs_track' => 'Документы этому вывозу не нужны']);
        }
        if (! preg_match('/^[A-Z0-9-]{6,40}$/', $track)) {
            throw ValidationException::withMessages(['docs_track' => 'Трек-номер СДЭК']);
        }
        $t->update(['docs_track' => $track, 'docs_sent_at' => $t->docs_sent_at ?? now(), 'docs_sent_by' => $by->id]);
        TransportMoved::dispatch($t, $by, 'docs_sent');

        return $t;
    }

    public function receive(Transport $t, User $by): Transport
    {
        if (! $t->isHandledBy($by)) {
            throw ValidationException::withMessages(['transport' => 'Задача поручена не вам']);
        }
        if (! $t->docs_sent_at || $t->docs_received_at) {
            throw ValidationException::withMessages(['transport' => $t->docs_received_at ? 'Уже отмечено' : 'Документы ещё не отправили']);
        }
        $t->update(['docs_received_at' => now()]);
        TransportMoved::dispatch($t, $by, 'docs_received');

        return $t;
    }
}
