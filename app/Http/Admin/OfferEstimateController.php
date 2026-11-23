<?php

namespace App\Http\Admin;

use App\Notifications\EstimateAskedNotice;
use App\Notifications\EstimatesPackNotice;
use App\Offers\Actions\AskEstimates;
use App\Offers\Actions\CloseEstimates;
use App\Offers\Actions\FirstLook;
use App\Offers\Actions\RejectEstimate;
use App\Offers\Actions\TakeEstimate;
use App\Offers\Estimate;
use App\Offers\EstimateState;
use App\Offers\Exclusive;
use App\Offers\Offer;
use App\Offers\PriceGuard;
use App\Support\Ago;
use App\Support\Money;
use App\Users\Role;
use App\Users\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Оценка менеджером в окошке «Оценить» вкладки «Без продажной цены» (11.10.2026, начальник): «Отдать менеджеру на оценку»,
 * под полем цены — кто оценил («Взять» ставит цену продажи, как «Оценить»; «Отклонить» — оценивает снова) и кто ещё думает
 * («×» снимает просьбу).
 */
final class OfferEstimateController
{
    public function store(Request $request, Offer $offer, AskEstimates $ask)
    {
        [$ids, $answers, $days, $exclusive] = $this->terms($request, (bool) $offer->declaredPrice());
        $created = $ask($offer, User::withRole(Role::Manager)->whereIn('id', $ids)->get(), $request->user(), self::declared($offer, $ids, $answers), $days, $exclusive);
        $exclusive = $created->first()?->exclusive_days;

        return back()->with('toast', $created->isEmpty() ? 'Уже на оценке' : 'Отдано на оценку: '.$created->map(fn (Estimate $e) => $e->user->shortName())->implode(', ')
            .' — '.Estimate::daysWord($days).($exclusive ? ', потом только ему '.Estimate::daysWord($exclusive) : ''));
    }

    /**
     * Пачкой из «Без продажной цены» (22.11.2026, начальник: «как в Оцененных — галочками»): те же менеджеры, «Показать |
     * Скрыть» заявленную (один ответ на все машины с ней), срок и эксклюзив — каждой отмеченной, что ещё там; одна не вышла —
     * остальные уходят, а она — в красный тост.
     */
    public function storeMany(Request $request, AskEstimates $ask)
    {
        $numbers = $request->validate(['offers' => ['required', 'array', 'max:500'], 'offers.*' => ['integer']])['offers'];
        [$ids, $answers, $days, $exclusive] = $this->terms($request, true);
        $managers = User::withRole(Role::Manager)->whereIn('id', $ids)->get();
        $offers = OfferController::scopeFor('unpriced', Offer::inCrm($request->user())->whereIn('offers.number', $numbers), true)
            ->orderBy('offers.number')->get();
        [$done, $failed, $created] = [0, [], collect()];
        foreach ($offers as $offer) {
            try {
                $new = $ask($offer, $managers, $request->user(), self::declared($offer, $ids, $answers), $days, $exclusive, pack: true);
                $new->isNotEmpty() && $done++;
                $created = $created->concat($new);
            } catch (ValidationException $e) {
                $failed[] = $offer->number.': '.mb_strtolower((string) (collect($e->errors())->flatten()->first() ?? 'не вышло'));
            }
        }
        // Менеджеру — одно уведомление на пачку (22.11.2026, владелец), одна машина — как по одной.
        foreach ($created->groupBy('user_id') as $mine) {
            $mine->first()->loadMissing('user')->user?->notify($mine->count() === 1 ? new EstimateAskedNotice($mine->first()) : new EstimatesPackNotice($mine->values()));
        }
        $toast = $done ? 'На оценку '.$done.' ТС: '.$managers->map->shortName()->implode(', ').', '.Estimate::daysWord($days) : null;
        $back = back(fallback: '/offers?preset=unpriced');

        return $failed
            ? $back->with('toast-danger', ($toast ? $toast.'. ' : '').'Не ушли '.count($failed).': '.implode('; ', array_slice($failed, 0, 3)))
            : $back->with('toast', $toast ?? 'Уже на оценке');
    }

    /**
     * Кому, «Показать | Скрыть» заявленную, срок и эксклюзив из шторки. Заявленная есть — за каждого ответили, показывать её
     * или нет (11.10.2026, владелец: «пусть просто спрашивает»).
     *
     * @return array{0: list<int>, 1: array<int, bool>, 2: int, 3: ?int}
     */
    private function terms(Request $request, bool $declared): array
    {
        $ids = array_map('intval', (array) $request->validate(['managers' => ['required', 'array', 'min:1'], 'managers.*' => ['integer']])['managers']);
        $answers = [];
        if ($declared) {
            $given = (array) $request->input('declared', []);
            foreach ($ids as $id) {
                if (! in_array((string) ($given[$id] ?? ''), ['0', '1'], true)) {
                    throw ValidationException::withMessages(['managers' => 'Выберите, показывать ли заявленную']);
                }
                $answers[$id] = $given[$id] === '1';
            }
        }
        // Сколько дней на оценку и эксклюзив после неё (02.11.2026, Андрей) — сегментами шторки; эксклюзив — только одному.
        $terms = $request->validate(['days' => ['nullable', Rule::in(Estimate::DAYS)], 'exclusive' => ['nullable', Rule::in(Estimate::EXCLUSIVE)]]);

        return [$ids, $answers, (int) ($terms['days'] ?? 2), (int) ($terms['exclusive'] ?? 0) ?: null];
    }

    /**
     * Кому показать заявленную у этой машины. Её нет вовсе (14.10.2026) — оценка «вслепую» и остаётся такой: вписали
     * закупочную, пока менеджер думает, — заявленная ему не покажется (как при «Скрыть»).
     *
     * @return array<int, bool>
     */
    private static function declared(Offer $offer, array $ids, array $answers): array
    {
        return $offer->declaredPrice() ? $answers : array_fill_keys($ids, false);
    }

    /** «Взять» — как «Оценить»: строка уходит в «Оцененные», а окно остаётся на машине с новой ценой (23.11.2026, владелец: окно
     *  сразу уезжало к следующей, и казалось, что цена не встала; `detail-stay` → `data-detail-stay` у окна). */
    public function take(Request $request, Offer $offer, Estimate $estimate, TakeEstimate $take, FirstLook $firstLook)
    {
        abort_unless($estimate->offer_id === $offer->id, 404);
        PriceGuard::assert($offer, ['asking_price' => $estimate->amount], $request->boolean('price_ok'));
        // Эксклюзив, выбранный при просьбе, — «Взять» сразу выставляет только ему (07.11.2026, владелец: оценку проверяет админ).
        if ($estimate->exclusive_days && ! $estimate->isCorrection()) {
            $published = $firstLook->open($estimate, $request->user());
            $offer->refresh();

            return back()->with('detail-stay', true)->with('detail-counts', OfferController::counts($request->user()))
                ->with('toast', $published ? 'Выставлено, видит только '.$estimate->user->shortName().' до '.Ago::at($offer->first_look_until, true, words: false)
                    : 'Оценено: '.Money::rub($estimate->amount).', в продажу не готово');
        }
        $take($estimate, $request->user());
        // Корректировка: строка остаётся в «Оцененных» с новой ценой — карточка просто перечитывается.
        if ($estimate->isCorrection()) {
            return back()->with('toast', 'Цена продажи: '.Money::rub($estimate->amount))->with('detail-counts', OfferController::counts($request->user()));
        }

        return back()->with('detail-stay', true)->with('detail-counts', OfferController::counts($request->user()))
            ->with('toast', 'Цена продажи: '.Money::rub($estimate->amount));
    }

    public function reject(Request $request, Offer $offer, Estimate $estimate, RejectEstimate $reject)
    {
        abort_unless($estimate->offer_id === $offer->id, 404);
        $reject($estimate, $request->user());

        return back()->with('toast', 'Оценка отклонена')->with('detail-counts', OfferController::counts($request->user()));
    }

    /** «Оставить свою» у корректировки: цена админа остаётся, просьбы закрываются. */
    public function keep(Request $request, Offer $offer, CloseEstimates $close)
    {
        $close($offer, $request->user(), keep: true);

        return back()->with('toast', 'Цена '.Money::rub((int) $offer->asking_price).' остаётся')
            ->with('detail-counts', OfferController::counts($request->user()));
    }

    /** «Спросить снова» у отказа: новая просьба тому же менеджеру с прежним «показать / скрыть заявленную». */
    public function again(Request $request, Offer $offer, Estimate $estimate, AskEstimates $ask)
    {
        abort_unless($estimate->offer_id === $offer->id && $estimate->state === EstimateState::Declined, 404);
        // Спросили снова — с прежними сроком и эксклюзивом (ревью 02.11.2026: уходило на двое суток и без эксклюзива).
        $days = collect(Estimate::DAYS)->sortBy(fn ($d) => abs($d * 86400 - abs($estimate->created_at->diffInSeconds($estimate->due_at))))->first();
        $ask($offer, [$estimate->user], $request->user(), [$estimate->user_id => $estimate->showsDeclared()], $days, $estimate->exclusive_days);

        return back()->with('toast', 'Спросили снова: '.$estimate->user->shortName());
    }

    public function destroy(Request $request, Offer $offer, Estimate $estimate, CloseEstimates $close)
    {
        abort_unless($estimate->offer_id === $offer->id, 404);
        // Обещан эксклюзив за оценку (`Exclusive`) — крестик снимает его только с подтверждением (`waive=1` из предупреждения).
        if ($estimate->exclusive_days > 0 && ! $estimate->withdrawn_at && ! $estimate->exclusive_until) {
            if (! $request->boolean('waive')) {
                return back()->withErrors(['estimate' => 'Обещан эксклюзив за оценку — снимите с подтверждением']);
            }
            Exclusive::waive($offer, $request->user(), collect([$estimate]));
        }
        $close($offer, $request->user(), [$estimate->id]);

        return back()->with('toast', 'Снято с оценки');
    }
}
