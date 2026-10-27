<?php

namespace App\Mail\Scan;

use App\Live\Topics;
use App\Mail\Actions\ApplyScan;
use App\Mail\Candidate;
use App\Mail\Chains\ChainBuilder;
use App\Mail\Extraction\ScanFields;
use App\Mail\Scope;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Support\Collection;

/**
 * «✨» у цепочки «Из писем» парковки или CRM: файлы всех её писем, выбор — в `Candidate::chosen` (оттуда его берут
 * «Завести» и разбор письма), после чтения — свёртка заново.
 */
final class CandidateSubject implements Subject
{
    public function __construct(public readonly Candidate $candidate) {}

    public function key(): string
    {
        return 'c:'.$this->candidate->id;
    }

    public function rejectKey(): string
    {
        return 'candidate:'.$this->candidate->id;
    }

    public function url(): string
    {
        return $this->park() ? "/requests/from-mail/{$this->candidate->id}/scan" : "/offers/from-mail/{$this->candidate->id}/scan";
    }

    public function mail(): string
    {
        return $this->park() ? '/mail' : '/work/mail';
    }

    public function topic(): string
    {
        return $this->park() ? Topics::PARK : Topics::STAFF;
    }

    public function fields(): array
    {
        return $this->park() ? array_keys(ScanFields::LABELS) : ScanFields::CAR;
    }

    public function title(): string
    {
        return $this->candidate->title();
    }

    public function hasCar(): bool
    {
        return $this->candidate->hasCar();
    }

    public function vendor(): ?Vendor
    {
        return $this->candidate->vendor;
    }

    public function ref(): ?string
    {
        return $this->candidate->code;
    }

    /** «Завести» из окна — разбор письма парковки; в CRM заводят кнопкой шапки цепочки, черновиком сразу. */
    public function creates(): bool
    {
        return $this->park();
    }

    public function files(): Collection
    {
        $this->candidate->loadMissing('messages.attachments');

        return self::own($this->candidate, Files::of($this->candidate->messages));
    }

    /**
     * Цепочка одного номера из письма о нескольких машинах (27.10.2026: «110927-26 и 110945-26 Мерседес Актрос + Когель»)
     * читает только свои документы: файл с номером другой машины в имени («ДОГОВОР КОМИССИИ 110945-25.docx») — не её, файл
     * без номера — той машины, чей файл с номером шёл перед ним (вендор кладёт документы машины подряд). Номер в имени —
     * цифры дела без года.
     *
     * @param  Collection<int, ScanFile>  $files
     * @return Collection<int, ScanFile>
     */
    public static function own(Candidate $candidate, Collection $files): Collection
    {
        $codes = $candidate->messages->flatMap(fn ($m) => $m->keys())->filter(fn ($k) => str_starts_with($k, 'code:'))->unique()->values();
        if ($codes->count() < 2 || ! $codes->contains($candidate->key)) {
            return $files;
        }
        // «code:1109452026» → «110945»: цифры номера без года в конце.
        $number = fn (string $key) => preg_replace('/(19|20)\d{2}$/', '', substr($key, 5));
        $mine = $number($candidate->key);
        $others = $codes->reject(fn ($k) => $k === $candidate->key)->map($number)->filter()->all();
        $owner = null;

        return $files->filter(function (ScanFile $f) use ($mine, $others, &$owner) {
            $name = $f->scanName();
            if ($mine !== '' && str_contains($name, $mine)) {
                $owner = 'mine';
            } elseif (collect($others)->contains(fn ($n) => str_contains($name, $n))) {
                $owner = 'other';
            }

            return $owner !== 'other';
        })->values();
    }

    public function current(): array
    {
        return ScanFields::ofCandidate($this->candidate);
    }

    public function apply(array $chosen, User $by): void
    {
        app(ApplyScan::class)($this->candidate, $chosen);
    }

    public function refresh(): void
    {
        app(ChainBuilder::class)->fold($this->candidate->refresh());
    }

    private function park(): bool
    {
        return $this->candidate->scope === Scope::Park;
    }
}
