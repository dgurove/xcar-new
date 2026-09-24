<?php

namespace App\Mail;

use App\Workflow\Stage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Заготовка письма: тема и тело с подстановками {{ number }}, {{ car }}, {{ claim_ref }}, {{ price }}, {{ manager }}, {{ insurer }}. */
#[Fillable(['name', 'scope', 'subject', 'body'])]
class Template extends Model
{
    protected $table = 'mail_templates';

    protected function casts(): array
    {
        return ['scope' => Scope::class];
    }

    public const PLACEHOLDERS = ['number', 'car', 'vin', 'claim_ref', 'price', 'manager', 'insurer', 'today'];

    public const PARK_PLACEHOLDERS = ['ref', 'car', 'vin', 'plate', 'yard', 'address', 'date', 'days', 'damages', 'client', 'today', 'pickup_link', 'buyer_name', 'buyer_email', 'pickup_date'];

    /** Письма стоянки по делу: приём, выдача, отказ от получения, счёт. Заводятся при первом обращении, дальше правятся руками. */
    public const PARK = [
        'intake' => ['Приём на парковку', 'Принято на хранение: {{ car }}, убыток {{ ref }}',
            "Добрый день.\n\nТС {{ car }}, VIN {{ vin }}, госномер {{ plate }} принято на хранение {{ date }} на парковку «{{ yard }}» ({{ address }}).\nПовреждения: {{ damages }}.\n\nАкт приёма и фото во вложении.\n\nС уважением,\nООО «ПРАЙМ»"],
        'release' => ['Выдача с парковки', 'Выдано с парковки: {{ car }}, убыток {{ ref }}',
            "Добрый день.\n\nТС {{ car }}, VIN {{ vin }}, госномер {{ plate }} выдано {{ date }}, хранение {{ days }} сут.\nАкт выдачи и фото во вложении.\n\nС уважением,\nООО «ПРАЙМ»"],
        'refusal' => ['Отказ от получения', 'Отказ от получения: {{ car }}, убыток {{ ref }}',
            "Добрый день.\n\nПолучатель ТС {{ car }}, VIN {{ vin }}, госномер {{ plate }} осмотрел ТС {{ today }} и от получения отказался.\nАкт осмотра с замечаниями и фото во вложении. ТС остаётся на хранении.\n\nС уважением,\nООО «ПРАЙМ»"],
        // Выдача по QR: ссылку на анкету покупателя шлём сами ответом на «продано», анкету — страховой на подтверждение.
        'pickup-link' => ['Ссылка для покупателя', 'Выдача по QR-коду: {{ car }}, убыток {{ ref }}',
            "Добрый день.\n\nВыдача ТС {{ car }}, госномер {{ plate }} производится только по QR-коду. Передайте, пожалуйста, покупателю эту ссылку:\n{{ pickup_link }}\n\nПокупатель укажет свои данные и дату, когда заберёт ТС, и сразу получит QR-код. Мы попросим вас подтвердить, что это покупатель, и после подтверждения выдадим ТС по этому коду.\n\nС уважением,\nООО «ПРАЙМ»"],
        'buyer-check' => ['Подтверждение покупателя', 'Подтвердите покупателя: {{ car }}, убыток {{ ref }}',
            "Добрый день.\n\nПо ТС {{ car }}, VIN {{ vin }}, госномер {{ plate }} по ссылке для получения указаны:\nФИО: {{ buyer_name }}\nПочта: {{ buyer_email }}\nПланирует забрать: {{ pickup_date }}\n\nПодтвердите, пожалуйста, что это покупатель ТС. Без подтверждения выдачу не производим.\n\nС уважением,\nООО «ПРАЙМ»"],
        'invoice' => ['Счёт за хранение', 'Счёт за хранение: {{ car }}, убыток {{ ref }}',
            "Добрый день.\n\nНаправляем счёт и акт оказанных услуг за хранение ТС {{ car }}, VIN {{ vin }}, госномер {{ plate }}.\n\nС уважением,\nООО «ПРАЙМ»"],
    ];

    public static function park(string $key): self
    {
        [$name, $subject, $body] = self::PARK[$key];

        return self::firstOrCreate(['scope' => Scope::Park, 'name' => $name], ['subject' => $subject, 'body' => $body]);
    }

    /** Абзац письма о приёме у вендора с выдачей по QR — когда в его шаблоне нет {{ pickup_link }}. */
    public const PICKUP_NOTICE = "Выдача ТС производится только по QR-коду. Когда ТС будет продано, передайте, пожалуйста, покупателю эту ссылку:\n{{ pickup_link }}";

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

        return ['subject' => strtr((string) $this->subject, $map), 'body' => strtr((string) $this->body, $map)];
    }
}
