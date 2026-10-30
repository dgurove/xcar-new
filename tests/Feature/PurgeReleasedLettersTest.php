<?php

namespace Tests\Feature;

use App\Mail\Message;
use App\Mail\Thread;
use App\Park\Jobs\PurgeReleasedLetters;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Выдача стирает файлы из писем фоном (`PurgeReleasedLetters`, 30.10.2026: в запросе это стоило до 2 с). Тихая ошибка:
 * задача по ошибке не чистит — дела выданных пухнут; чистит вернувшуюся на парковку — письма пропадают из её дела.
 */
class PurgeReleasedLettersTest extends TestCase
{
    use RefreshDatabase;

    /** ТС с веткой ящика парковки и входящим письмом в ней. @return array{Vehicle, Message} */
    private function vehicle(VehicleState $state): array
    {
        $vehicle = Vehicle::create(['vendor_id' => Vendor::create(['name' => 'Альфа тест'])->id, 'state' => $state]);
        $account = DB::table('mail_accounts')->insertGetId(['slug' => 'storage', 'title' => 'storage', 'email' => 'storage@xcar.ru', 'scope' => 'park',
            'imap_host' => 'x', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'x', 'smtp_username' => 'x', 'smtp_password' => 'x']);
        $thread = Thread::create(['account_id' => $account, 'subject' => 'Выдача', 'vehicle_id' => $vehicle->id]);
        $letter = Message::create(['account_id' => $account, 'thread_id' => $thread->id, 'direction' => 'in', 'from_email' => 'a@alfastrah.ru', 'subject' => 'Выдача']);

        return [$vehicle, $letter];
    }

    public function test_purges_a_released_vehicle(): void
    {
        [$vehicle, $letter] = $this->vehicle(VehicleState::Released);

        app()->call([new PurgeReleasedLetters($vehicle->id), 'handle']);

        $this->assertNotNull($letter->fresh()->frozen_at);
    }

    public function test_skips_a_vehicle_whose_release_was_undone(): void
    {
        [$vehicle, $letter] = $this->vehicle(VehicleState::Stored);

        app()->call([new PurgeReleasedLetters($vehicle->id), 'handle']);

        $this->assertNull($letter->fresh()->frozen_at);
    }

    public function test_one_job_per_vehicle_in_queue(): void
    {
        $this->assertSame((new PurgeReleasedLetters(7))->uniqueId(), (new PurgeReleasedLetters(7))->uniqueId());
    }
}
