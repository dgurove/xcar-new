<?php

namespace App\Workflow\Actions;

use App\Offers\Actions\AnswerParallel;
use App\Offers\Deal;
use App\Offers\OfferEventType;
use App\Users\User;
use App\Workflow\Actor;
use App\Workflow\Asks;
use App\Workflow\Outcome;
use App\Workflow\Requirement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Ответ менеджера: нажатая кнопка плюс поля или документ. Двигает оффер его исходом. */
final class AnswerRequirement
{
    public function __construct(private TakeExit $takeExit) {}

    public function __invoke(Requirement $requirement, Outcome $exit, User $by, array $fields = []): Requirement
    {
        return DB::transaction(function () use ($requirement, $exit, $by, $fields) {
            $requirement = Requirement::whereKey($requirement->id)->lockForUpdate()->firstOrFail();
            if ($requirement->user_id !== $by->id || ! $requirement->isOpen()) {
                throw ValidationException::withMessages(['exit' => 'Ответ уже не нужен']);
            }
            if ($exit->stage_id !== $requirement->stage_id || $exit->actor !== Actor::Manager) {
                throw ValidationException::withMessages(['exit' => 'Эта кнопка не отсюда']);
            }

            $answer = [];
            if ($requirement->asks === Asks::Fields) {
                foreach ($requirement->fields as $field) {
                    $value = trim((string) ($fields[$field['key']] ?? ''));
                    if ($value === '') {
                        throw ValidationException::withMessages(["fields.{$field['key']}" => 'Заполните']);
                    }
                    // Дата со временем (Альфа, «Время подписания») — одним видом, словами её рисует `Requirement::shown`.
                    if (($field['type'] ?? '') === 'datetime') {
                        try {
                            $value = \Illuminate\Support\Carbon::parse($value)->format('Y-m-d H:i');
                        } catch (\Throwable) {
                            throw ValidationException::withMessages(["fields.{$field['key']}" => 'Выберите дату и время']);
                        }
                    }
                    $answer[$field['key']] = $value;
                }
            }
            // Время подписания — только выбранным временем (`PickSigningTime` ставит его до ответа).
            if (\App\Offers\Signing::pickExit($exit) && ! $requirement->deal?->signing_at) {
                throw ValidationException::withMessages(['exit' => 'Сначала выберите время']);
            }
            if (Deal::closes($exit) && ($blocker = $requirement->deal?->closeBlocker())) {
                throw ValidationException::withMessages(['exit' => $blocker]);
            }
            if ($requirement->asks === Asks::Document && $requirement->getMedia('files')->isEmpty()) {
                throw ValidationException::withMessages(['files' => 'Приложите документ']);
            }
            // Данные покупателя для счёта (03.11.2026): шаг проходит сам, когда они есть, — кнопкой без них нельзя.
            if ($requirement->asks === Asks::Buyer && ! $requirement->deal?->contract?->buyer?->party?->readyAsBuyer()) {
                throw ValidationException::withMessages(['exit' => 'Сначала данные покупателя со сканом паспорта']);
            }

            // Параллельная сделка (07.11.2026): ответ двигает только её теневой шаг, машину — нет.
            if ($requirement->deal?->isParallel()) {
                app(AnswerParallel::class)($requirement, $exit, $by, $answer);

                return $requirement->refresh();
            }

            $requirement->update(['done_at' => now(), 'answer' => ['exit' => $exit->label, 'fields' => $answer]]);
            ($this->takeExit)($requirement->offer, $exit, Actor::Manager, $by, $answer);
            $requirement->offer->log(OfferEventType::RequirementAnswered, $by, ['exit' => $exit->label, 'fields' => $answer]);

            return $requirement;
        });
    }
}
