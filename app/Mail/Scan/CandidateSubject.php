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

        return Files::of($this->candidate->messages);
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
