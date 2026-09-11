<?php

namespace App\Mail;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

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

    public function render(array $values): array
    {
        $map = [];
        foreach ($values as $key => $value) {
            $map['{{ '.$key.' }}'] = $map['{{'.$key.'}}'] = (string) $value;
        }

        return ['subject' => strtr((string) $this->subject, $map), 'body' => strtr((string) $this->body, $map)];
    }
}
