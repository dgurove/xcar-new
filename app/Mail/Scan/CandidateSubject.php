<?php

namespace App\Mail\Scan;

use App\Mail\Actions\ApplyScan;
use App\Mail\Candidate;
use App\Mail\Chains\ChainBuilder;
use App\Mail\Extraction\ScanFields;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Support\Collection;

/** «✨» у цепочки «Из писем»: файлы всех её писем, выбор — в `Candidate::chosen`, после чтения — свёртка заново. */
final class CandidateSubject implements Subject
{
    public function __construct(public readonly Candidate $candidate) {}

    public function key(): string
    {
        return 'c:'.$this->candidate->id;
    }

    public function url(): string
    {
        return "/requests/from-mail/{$this->candidate->id}/scan";
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

    public function creates(): bool
    {
        return true;
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
}
