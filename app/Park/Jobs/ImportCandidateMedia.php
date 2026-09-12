<?php

namespace App\Park\Jobs;

use App\Mail\Actions\PinThread;
use App\Mail\Candidate;
use App\Mail\Extraction\AttachmentImporter;
use App\Park\Vehicle;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Снимки из письма — в карточку машины; их бывает по тридцать, это минуты. */
final class ImportCandidateMedia implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1200;

    public int $tries = 1;

    public function __construct(public int $candidateId, public int $vehicleId)
    {
        $this->onConnection('database-long')->onQueue('long');
    }

    public function handle(AttachmentImporter $importer, PinThread $pin): void
    {
        $candidate = Candidate::find($this->candidateId);
        $vehicle = Vehicle::find($this->vehicleId);
        if ($candidate && $vehicle) {
            if ($candidate->thread) {
                $pin($candidate->thread);
            }
            $importer->import($vehicle, $importer->attachmentsOf($candidate->message_id, $candidate->thread_id), 'photos', 'papers');
        }
    }
}
