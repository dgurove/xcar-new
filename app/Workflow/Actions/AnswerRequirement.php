<?php

namespace App\Workflow\Actions;

use App\Offers\OfferEventType;
use App\Users\User;
use App\Workflow\Actor;
use App\Workflow\Asks;
use App\Workflow\Events\RequirementAnswered;
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
                    $answer[$field['key']] = $value;
                }
            }
            if ($requirement->asks === Asks::Document && $requirement->getMedia('files')->isEmpty()) {
                throw ValidationException::withMessages(['files' => 'Приложите документ']);
            }

            $requirement->update(['done_at' => now(), 'answer' => ['exit' => $exit->label, 'fields' => $answer]]);
            ($this->takeExit)($requirement->offer, $exit, Actor::Manager, $by, $answer);
            $requirement->offer->log(OfferEventType::RequirementAnswered, $by, ['exit' => $exit->label, 'fields' => $answer]);
            RequirementAnswered::dispatch($requirement, $by);

            return $requirement;
        });
    }
}
