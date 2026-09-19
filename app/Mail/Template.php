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

    public const PARK_PLACEHOLDERS = ['ref', 'car', 'vin', 'plate', 'yard', 'address', 'date', 'days', 'damages', 'client', 'today'];

    /** Письма стоянки по делу: приём, выдача, отказ от получения, счёт. Заводятся при первом обращении, дальше правятся руками. */
    public const PARK = [
        'intake' => ['Приём на стоянку', 'Принято на хранение: {{ car }}, убыток {{ ref }}',
            "Добрый день.\n\nТС {{ car }}, VIN {{ vin }}, госномер {{ plate }} принято на хранение {{ date }} на площадку «{{ yard }}» ({{ address }}).\nПовреждения: {{ damages }}.\n\nАкт приёма и фото во вложении.\n\nС уважением,\nООО «ПРАЙМ»"],
        'release' => ['Выдача со стоянки', 'Выдано со стоянки: {{ car }}, убыток {{ ref }}',
            "Добрый день.\n\nТС {{ car }}, VIN {{ vin }}, госномер {{ plate }} выдано {{ date }}, хранение {{ days }} сут.\nАкт выдачи и фото во вложении.\n\nС уважением,\nООО «ПРАЙМ»"],
        'refusal' => ['Отказ от получения', 'Отказ от получения: {{ car }}, убыток {{ ref }}',
            "Добрый день.\n\nПолучатель ТС {{ car }}, VIN {{ vin }}, госномер {{ plate }} осмотрел ТС {{ today }} и от получения отказался.\nАкт осмотра с замечаниями и фото во вложении. ТС остаётся на хранении.\n\nС уважением,\nООО «ПРАЙМ»"],
        'invoice' => ['Счёт за хранение', 'Счёт за хранение: {{ car }}, убыток {{ ref }}',
            "Добрый день.\n\nНаправляем счёт и акт оказанных услуг за хранение ТС {{ car }}, VIN {{ vin }}, госномер {{ plate }}.\n\nС уважением,\nООО «ПРАЙМ»"],
    ];

    public static function park(string $key): self
    {
        [$name, $subject, $body] = self::PARK[$key];

        return self::firstOrCreate(['scope' => Scope::Park, 'name' => $name], ['subject' => $subject, 'body' => $body]);
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
