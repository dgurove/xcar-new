<?php

namespace App\Mail;

use App\Support\FastAttributes;
use App\Workflow\Stage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/** Заготовка письма: тема и тело с подстановками {{ number }}, {{ car }}, {{ claim_ref }}, {{ floor }}, {{ price }}, {{ manager }}, {{ insurer }}. */
#[Fillable(['name', 'scope', 'subject', 'body', 'intent', 'attach'])]
class Template extends Model
{
    use FastAttributes;

    protected $table = 'mail_templates';

    protected function casts(): array
    {
        return ['scope' => Scope::class];
    }

    public const PLACEHOLDERS = ['number', 'car', 'vin', 'claim_ref', 'floor', 'price', 'manager', 'insurer', 'today', 'buyer'];

    public const PARK_PLACEHOLDERS = ['ref', 'car', 'vin', 'plate', 'yard', 'address', 'date', 'days', 'damages', 'client', 'today', 'pickup_link', 'buyer_name', 'buyer_email', 'pickup_date',
        'month', 'count', 'invoice', 'sum'];

    /** Письма стоянки по делу: приём, выдача, отказ от получения, счёт. Заводятся при первом обращении, дальше правятся руками. */
    public const PARK = [
        'intake' => ['Приём на парковку', 'Принято на хранение: {{ car }}, убыток {{ ref }}',
            "Добрый день.\n\nТС {{ car }}, VIN {{ vin }}, госномер {{ plate }} принято на хранение {{ date }} на парковку «{{ yard }}» ({{ address }}).\nПовреждения: {{ damages }}.\n\nАкт приёма и фото во вложении.\n\nС уважением,\nООО «ПРАЙМ»"],
        'release' => ['Выдача с парковки', 'Выдано с парковки: {{ car }}, убыток {{ ref }}',
            // Как Сергей писал руками (06.11.2026, владелец: без приветствия и «С уважением, ООО «ПРАЙМ»»): ответом в ветку заявки,
            // машина и так в теме.
            'Направляю Вам закрывающие документы по выданному ТС.'],
        'refusal' => ['Отказ от получения', 'Отказ от получения: {{ car }}, убыток {{ ref }}',
            "Добрый день.\n\nПолучатель ТС {{ car }}, VIN {{ vin }}, госномер {{ plate }} осмотрел ТС {{ today }} и от получения отказался.\nАкт осмотра с замечаниями и фото во вложении. ТС остаётся на хранении.\n\nС уважением,\nООО «ПРАЙМ»"],
        // Выдача по QR: ссылку на анкету покупателя шлём сами ответом на «продано», анкету — страховой на подтверждение.
        'pickup-link' => ['Ссылка для покупателя', 'Выдача по QR-коду: {{ car }}, убыток {{ ref }}',
            "Добрый день.\n\nВыдача ТС {{ car }}, госномер {{ plate }} производится только по QR-коду. Передайте, пожалуйста, покупателю эту ссылку:\n{{ pickup_link }}\n\nПокупатель укажет свои данные и дату, когда заберёт ТС, и сразу получит QR-код. Мы попросим вас подтвердить, что это покупатель, и после подтверждения выдадим ТС по этому коду.\n\nС уважением,\nООО «ПРАЙМ»"],
        'buyer-check' => ['Подтверждение покупателя', 'Подтвердите покупателя: {{ car }}, убыток {{ ref }}',
            "Добрый день.\n\nПо ТС {{ car }}, VIN {{ vin }}, госномер {{ plate }} по ссылке для получения указаны:\nФИО: {{ buyer_name }}\nПочта: {{ buyer_email }}\nПланирует забрать: {{ pickup_date }}\n\nПодтвердите, пожалуйста, что это покупатель ТС. Без подтверждения выдачу не производим.\n\nС уважением,\nООО «ПРАЙМ»"],
        'invoice' => ['Счёт за хранение', 'Счёт за хранение: {{ car }}, убыток {{ ref }}',
            "Добрый день.\n\nНаправляем счёт и акт оказанных услуг за хранение ТС {{ car }}, VIN {{ vin }}, госномер {{ plate }}.\n\nС уважением,\nООО «ПРАЙМ»"],
        // Счёт-реестр за месяц (08.10.2026): одно письмо в бухгалтерию со счётом и актом, машины — в реестре акта.
        'registry' => ['Счёт за хранение по реестру', 'Счёт за хранение за {{ month }}: {{ count }} ТС',
            "Добрый день.\n\nНаправляем счёт {{ invoice }} на {{ sum }} и акт оказанных услуг хранения за {{ month }} с реестром транспортных средств ({{ count }} ТС).\n\nС уважением."],
        // «Отправить» спрашивает, что отправить (09.11.2026, владелец: «счёт и акт, или только счёт, или только акт»): своё
        // письмо на каждый случай, без слов о том, чего во вложении нет.
        'invoice-act' => ['Акт хранения', 'Акт оказанных услуг: {{ car }}, убыток {{ ref }}',
            "Добрый день.\n\nНаправляем акт оказанных услуг за хранение ТС {{ car }}, VIN {{ vin }}, госномер {{ plate }}.\n\nС уважением,\nООО «ПРАЙМ»"],
        'invoice-bill' => ['Счёт за хранение без акта', 'Счёт за хранение: {{ car }}, убыток {{ ref }}',
            "Добрый день.\n\nНаправляем счёт за хранение ТС {{ car }}, VIN {{ vin }}, госномер {{ plate }}.\n\nС уважением,\nООО «ПРАЙМ»"],
        'registry-act' => ['Акт хранения по реестру', 'Акт оказанных услуг хранения за {{ month }}: {{ count }} ТС',
            "Добрый день.\n\nНаправляем акт оказанных услуг хранения за {{ month }} с реестром транспортных средств ({{ count }} ТС).\n\nС уважением."],
        'registry-bill' => ['Счёт по реестру без акта', 'Счёт за хранение за {{ month }}: {{ count }} ТС',
            "Добрый день.\n\nНаправляем счёт {{ invoice }} на {{ sum }} за хранение за {{ month }} ({{ count }} ТС).\n\nС уважением."],
        // На сверку (09.11.2026, владелец: «первично — „Направляем Вам на сверку отчет-акт за октябрь 2026“, финальную — другой
        // текст, пусть спрашивает»): документ не фиксируется, письмо — его текстом.
        'registry-review' => ['Сверка: счёт и акт по реестру', 'Отчет-акт за {{ month }} на сверку',
            "Добрый день.\nНаправляем Вам на сверку счёт и отчет-акт за {{ month }}."],
        'registry-review-act' => ['Сверка: акт по реестру', 'Отчет-акт за {{ month }} на сверку',
            "Добрый день.\nНаправляем Вам на сверку отчет-акт за {{ month }}."],
        'registry-review-bill' => ['Сверка: счёт по реестру', 'Счёт за {{ month }} на сверку',
            "Добрый день.\nНаправляем Вам на сверку счёт за {{ month }}."],
        'invoice-review' => ['Сверка: счёт и акт по ТС', 'На сверку: {{ car }}, убыток {{ ref }}',
            "Добрый день.\nНаправляем Вам на сверку счёт и акт за хранение ТС {{ car }}, VIN {{ vin }}, госномер {{ plate }}."],
        'invoice-review-act' => ['Сверка: акт по ТС', 'На сверку: {{ car }}, убыток {{ ref }}',
            "Добрый день.\nНаправляем Вам на сверку акт за хранение ТС {{ car }}, VIN {{ vin }}, госномер {{ plate }}."],
        'invoice-review-bill' => ['Сверка: счёт по ТС', 'На сверку: {{ car }}, убыток {{ ref }}',
            "Добрый день.\nНаправляем Вам на сверку счёт за хранение ТС {{ car }}, VIN {{ vin }}, госномер {{ plate }}."],
    ];

    /** Что уходит письмом с документом месяца (`MailController` `?send=`): счёт и акт, только акт, только счёт. */
    public const SENDS = ['both' => '', 'act' => '-act', 'invoice' => '-bill'];

    /**
     * Что шаблон ответа парковки прикладывает сам: акт и кадры приёма (`MailController::actFiles`). К письмам о выдаче —
     * ничего (06.11.2026, владелец: «фото к письму не нужны, ничего не подтягивать» — уходили пачки webp): сканы кладут сами.
     */
    public const ATTACH = ['intake' => 'Акт и фото приёма'];

    /**
     * Готовые ответы парковки в окне ответа (02.11.2026): все её шаблоны, кроме писем шагов (`PARK` — приём, выдача, счёт
     * и прочие заводят свои окна), подходящий по смыслу письма — первым. Свои Сергей заводит в «Шаблонах» парковки.
     *
     * @return Collection<int, self>
     */
    public static function replies(?string $intent): Collection
    {
        return self::where('scope', Scope::Park)->whereNotIn('name', self::steps())->orderBy('id')->get()
            ->sortBy(fn (self $t) => $intent && $t->intent === $intent ? 0 : 1)->values();
    }

    /** Письма шагов парковки — `park()` находит их по названию, поэтому имя у них не меняется (прежние «…со стоянки» —
     * задвоенные копии от переименования 22.09.2026 — сняты миграцией `park_templates_dedupe`). */
    public static function steps(): array
    {
        return array_column(self::PARK, 0);
    }

    /** Письмо шага: правится текст, но не название и не удаляется (иначе `park()` заведёт его заново, а правка потеряется). */
    public function isStep(): bool
    {
        return $this->exists && $this->scope === Scope::Park && in_array($this->getOriginal('name'), self::steps(), true);
    }

    public static function park(string $key): self
    {
        [$name, $subject, $body] = self::PARK[$key];

        return self::firstOrCreate(['scope' => Scope::Park, 'name' => $name], ['subject' => $subject, 'body' => $body]);
    }

    /**
     * Заявка поставщику на выкуп пачкой (Каркаде, 09.10.2026): список — файлом во вложении (`SupplierRequest`). Шаблон
     * заводится при первой заявке, дальше правится в настройках почты.
     */
    public static function supplierRequest(): self
    {
        return self::firstOrCreate(['scope' => Scope::Offers, 'name' => 'Поставщику: заявка на выкуп'], [
            'subject' => 'Заявка на выкуп, {{ count }} ТС',
            'body' => "Здравствуйте!\n\nНаправляем заявку на выкуп {{ count }} ТС, список во вложении.\n\nС уважением,\nАндрей Кузнецов",
        ]);
    }

    /**
     * Переключились на другие реквизиты, когда письмо с прежними уже ушло (07.11.2026, владелец: «напишем в страховую: сори,
     * перевыставьте вот на эти реквизиты, а не на те», `TakeParallel`): шаг «Письмо с реквизитами» берёт этот шаблон вместо
     * своего, пока у сделки `reissue_at`. Тема — как у шаблона шага, подпись — его же. Заводится при первом переключении,
     * дальше правится в настройках почты.
     */
    public static function reissue(?self $base = null): self
    {
        $sign = $base && preg_match('/\n\s*(С уважением,.*)$/su', (string) $base->body, $m) ? trim($m[1]) : "С уважением,\nАндрей Кузнецов";

        return self::firstOrCreate(['scope' => Scope::Offers, 'name' => 'Поставщику: перевыставить счёт'], [
            'subject' => $base?->subject ?: '{{ claim_ref }}',
            'body' => "Здравствуйте!\n\nПросим перевыставить счёт на {{ car }}, VIN {{ vin }} ({{ claim_ref }}) на новые реквизиты:\n\n{{ buyer }}\n\nПрежний счёт, если уже выставлен, просим аннулировать.\n\n".$sign,
        ]);
    }

    /** Абзац письма о приёме у вендора с выдачей по QR — когда в его шаблоне нет {{ pickup_link }}. */
    public const PICKUP_NOTICE = "Выдача ТС производится только по QR-коду. Когда ТС будет реализовано, передайте, пожалуйста, покупателю эту ссылку:\n{{ pickup_link }}";

    /** Текст шаблона письмом: абзацы по пустой строке, строки — переносом, ссылки — ссылками. */
    public static function html(string $text): string
    {
        $paragraphs = preg_split('/\R{2,}/u', trim($text)) ?: [];

        return implode('', array_map(function ($p) {
            $lines = array_map(fn ($l) => preg_replace('~(https?://\S+)~u', '<a href="$1">$1</a>', e($l)), preg_split('/\R/u', $p) ?: []);

            return '<div>'.implode('<br>', $lines).'</div><div><br></div>';
        }, $paragraphs));
    }

    public function stages(): HasMany
    {
        return $this->hasMany(Stage::class, 'template_id');
    }

    public function render(array $values): array
    {
        $map = [];
        foreach ($values as $key => $value) {
            $map['{{ '.$key.' }}'] = $map['{{'.$key.'}}'] = (string) $value;
        }

        return ['subject' => strtr(self::dropEmpty((string) $this->subject, $values), $map), 'body' => strtr(self::dropEmpty((string) $this->body, $values), $map)];
    }

    /** Коды машины с подписью перед ними в тексте шаблона: пустой код уходит вместе с подписью. */
    private const LABELS = ['vin' => 'VIN', 'plate' => 'гос\.? ?номер', 'sts' => 'СТС', 'pts' => 'ПТС', 'claim_ref' => '№ убытка', 'address' => 'по адресу'];

    /**
     * Пустой код уходит со своей подписью (17.10.2026, прогон): «…Ford Kuga, VIN (ПРОГОН-Т-2) за…» — у машины без VIN
     * в письме поставщику оставалось голое «VIN», у ТС парковки без номера — «госномер ,». Подписанные коды (`LABELS`) —
     * «, VIN {{ vin }}», любой пустой в скобках — « ({{ claim_ref }})». Шаблон правят админы, поэтому чистим при подстановке.
     */
    private static function dropEmpty(string $text, array $values): string
    {
        foreach ($values as $key => $value) {
            if (filled($value) || ! is_string($key)) {
                continue;
            }
            $tag = '\{\{ ?'.preg_quote($key, '/').' ?\}\}';
            $text = (string) preg_replace('/[ \t]*\('.$tag.'\)/u', '', $text);
            if (isset(self::LABELS[$key])) {
                $text = (string) preg_replace('/,?[ \t]*'.self::LABELS[$key].':?[ \t]*'.$tag.'/iu', '', $text);
            }
        }

        return $text;
    }
}
