<?php

namespace App\Workflow;

use App\Offers\Deal;
use App\Offers\OfferState;
use App\Support\FastAttributes;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Исход — кнопка, уводящая с этапа на другой этап. */
#[Fillable(['stage_id', 'to_stage_id', 'label', 'actor', 'confirm', 'position', 'branch'])]
class Outcome extends Model
{
    use FastAttributes;

    protected $table = 'workflow_exits';

    /** Ветка гаража: машину забирают в гараж, поставщику платим мы. */
    public const GARAGE = 'garage';

    /** Ветка покупателя: всем сделкам, кроме гаражной «платим мы». */
    public const BUYER = 'buyer';

    /** Ветка передачи: автомобиль забирает сам менеджер сделки (`Deal::buyerPicksUp`). */
    public const BUYER_PICKS = 'picks';

    /** Ветка передачи: отдаём мы — со своей парковки или выдачей от поставщика. */
    public const WE_HAND = 'hands';

    /**
     * Ветка гаражной сделки любого плательщика (06.10.2026): бумаги готовы — сделка закрыта, машину не передаём —
     * её везёт вывоз к менеджеру в гараж. Передача (`picks`, `hands`) гаражным не подходит.
     */
    public const KEEPS = 'keeps';

    /** Ветка обычной сделки — не гаражной (у Каркаде передача с площадки, где развилки «кто забирает» нет). */
    public const DEAL = 'deal';

    /**
     * Согласие менеджера (10.10.2026, владелец: «Покупаю» → «Беру в работу», «Отказываюсь» → «Я передумал», приглушённой).
     * Прежние надписи узнаём тоже: маршрут в базе правится миграцией.
     */
    public const TAKES = ['беру в работу', 'покупаю'];

    /** Ветка оплаты через ООО «Р.О.С.» (Альфа с НДС, 10.10.2026): деньги пришли на Р.О.С. — сначала переводим Альфе. */
    public const ROS = 'ros';

    /** Ветка оплаты не через Р.О.С.: деньги у страховой сразу (напрямую) или у ПРАЙМ. */
    public const NOT_ROS = 'not_ros';

    /** Ветки продажи: в маршруте вывоза их не бывает (`Workflow::problems`). */
    public const SALE_BRANCHES = [self::GARAGE, self::BUYER, self::BUYER_PICKS, self::WE_HAND, self::KEEPS, self::DEAL, self::ROS, self::NOT_ROS];

    protected function casts(): array
    {
        return ['actor' => Actor::class];
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(Stage::class, 'stage_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(Stage::class, 'to_stage_id');
    }

    /**
     * Поля, что сотрудник вписывает этим исходом, — поля этапа, куда он ведёт. У «Подписания документов» два входа: запись
     * на приём (дата и время, адрес) и отправка оригиналов (трек) — каждому только свои и обязательно (18.10.2026, прогон
     * Альфы: «Приём назначен» просил и трек-номер, а менеджер не узнавал, когда и куда ехать).
     *
     * Платёжка нашей оплаты (`Stage::paysOut`, 19.10.2026) — наоборот, на выходе из этапа оплаты («Поставщику оплачено»,
     * «Переведено поставщику»), необязательно и с `out`: в позицию следующего этапа она не идёт, только в «Историю»
     * (`EnterStage`). На входе в этап оплаты («Договор получен») её не спрашиваем — платили ещё не мы.
     *
     * @return list<array{key: string, label: string, type: string, required?: bool, out?: bool, default?: string}>
     */
    public function staffFields(): array
    {
        $out = $this->actor === Actor::Staff
            // «Дата оплаты» — календарём и сегодняшним днём: чаще всего жмут в день оплаты.
            ? array_map(fn (array $f) => ['required' => false, 'out' => true]
                + (str_contains(mb_strtolower($f['label']), 'дата') ? ['type' => 'date', 'default' => now()->toDateString()] : []) + $f, $this->from?->payFields() ?? [])
            : [];
        $fields = array_values(array_filter($this->to?->staff_fields ?? [], fn (array $f) => ! Stage::isPayField($f)));
        $track = fn (array $f) => str_contains(mb_strtolower($f['label']), 'трек');
        if (! collect($fields)->contains($track) || collect($fields)->every($track)) {
            return [...$fields, ...$out];
        }
        $sending = str_contains(mb_strtolower($this->label), 'отправлен');

        return [...array_values(array_map(
            fn (array $f) => ['required' => true] + (! $sending && str_contains(mb_strtolower($f['label']), 'дата') ? ['type' => 'datetime'] + $f : $f),
            array_filter($fields, fn (array $f) => $track($f) === $sending),
        )), ...$out];
    }

    /**
     * Годится ли исход: на продаже — этой сделке (гаражный — только гаражной, где поставщику платим мы,
     * покупательский — всем остальным; нет сделки — гаражных не видно; передача — «забирает сам» только сделке, где
     * менеджер и вывозчик одно лицо, «отдаём мы» — всем остальным, кроме гаражных: те кончаются бумагами, `keeps`). Без
     * ветки — всем; незнакомая ветка (бывшие `keeper|ours` маршрута вывоза) — никому.
     */
    public function fits(?Deal $for): bool
    {
        $garage = $for instanceof Deal && $for->isGarageUs();
        $keeps = $for instanceof Deal && $for->isGarage();
        $picks = $for instanceof Deal && $for->buyerPicksUp();
        // Ветка «перевод поставщику»: за ТС заплатили нам — Р.О.С. или ПРАЙМ (18.10.2026: у ПРАЙМ Альфы шага перевода не было,
        // и Альфе уходило «счёт оплачен», хотя ей никто не платил). Имя ветки `ros` — прежнее, в маршрутах в базе.
        $ros = $for instanceof Deal && $for->schemeOf()->paysCar();

        return match ($this->branch) {
            null, '' => true,
            self::GARAGE => $garage,
            self::BUYER => ! $garage,
            self::BUYER_PICKS => $picks && ! $keeps,
            self::WE_HAND => ! $picks && ! $keeps,
            self::KEEPS => $keeps,
            self::DEAL => ! $keeps,
            self::ROS => $ros,
            self::NOT_ROS => ! $ros,
            default => false,
        };
    }

    /**
     * Что будет после нажатия — строкой под кнопками шага в CRM (03.11.2026, владелец: «что значит „Ответ поставщика
     * получен“, к чему это приведёт — сотрудникам страшно нажимать»): следующий этап и чей там ход. Из самого маршрута, своих
     * текстов у кнопок нет — редактор маршрута получает это даром.
     */
    public function consequence(): ?string
    {
        $to = $this->to;
        if (! $to) {
            return null;
        }
        if ($to->waits_for === WaitsFor::Nobody) {
            return match ($to->offer_state) {
                OfferState::Delivered, OfferState::Garage => 'сделка закроется',
                OfferState::Cancelled => 'сделка отменится',
                OfferState::Archived => 'в архив',
                default => '«'.$to->name.'»',
            };
        }
        $who = match (true) {
            $to->waits_for === WaitsFor::Manager && $to->awaitsManager() => 'менеджеру: «'.$to->managerTitle().'»',
            $to->isLetterStep() => 'письмо поставщику',
            $to->waits_for === WaitsFor::Supplier => 'ждём поставщика',
            $to->offer_state === OfferState::Open => 'приём подтверждений',
            default => 'наш ход',
        };

        return '«'.$to->name.'», '.$who;
    }

    /**
     * Подпись кнопки сотрудника на экране (03.11.2026, владелец: «сотрудникам страшно нажимать кнопки, которые не понимаешь»):
     * в маршруте подпись остаётся — по ней узнают шаг (`SyncHandover`, `SyncOffer`, `StepBack`), меняется только видимое.
     * Наша кнопка рядом с ответом менеджера («Автомобиль забрал», «Договор у покупателя») говорит, что это за него.
     */
    public function shownLabel(): string
    {
        $label = mb_strtolower(trim($this->label));
        $mirror = fn (string $manager) => $this->from?->loadedExits()->contains(fn (self $e) => $e->actor === Actor::Manager && mb_strtolower(trim($e->label)) === $manager);

        return match (true) {
            $label === 'автомобиль передан' && $mirror('автомобиль забрал') => 'Менеджер забрал автомобиль',
            $label === 'договор передан' && $mirror('договор у покупателя') => 'Покупатель получил договор',
            $label === 'покупатель от поставщика' => 'Продать покупателю поставщика',
            default => $this->label,
        };
    }

    /**
     * Ответ «оплатил» на шаге оплаты: «Оплатил, документ приложен» (10.11.2026, владелец: в кассе дают чек, бывает скан или
     * файл — не только платёжное поручение) и прежнее «Платёжное поручение приложено».
     */
    public function isPayExit(): bool
    {
        $label = mb_strtolower(trim($this->label));

        return str_starts_with($label, 'платёжное поручение') || str_starts_with($label, 'оплатил, документ');
    }

    /** Согласие менеджера взять сделку в работу. */
    public function takes(): bool
    {
        return $this->actor === Actor::Manager && in_array(mb_strtolower(trim($this->label)), self::TAKES, true);
    }

    /**
     * Отступной исход менеджера — «Я передумал»: ведёт назад в приём подтверждений (сделка отменяется). На странице сделки
     * — тихой строкой под главной кнопкой, а не второй кнопкой рядом.
     */
    public function isRetreat(): bool
    {
        return $this->actor === Actor::Manager && $this->to?->offer_state === OfferState::Open;
    }

    /**
     * «Подтверждение принято» — исход, которым маршрут догоняет «Отдать» (`Give`). Кнопкой его не жмут: сделку заводит
     * только «Отдать» или «Принять» у подтверждения, иначе предложение уходит «в сделку» без сделки и менеджера.
     */
    public function acceptsBid(): bool
    {
        return mb_strtolower(trim($this->label)) === 'подтверждение принято';
    }
}
