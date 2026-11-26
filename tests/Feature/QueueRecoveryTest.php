<?php

namespace Tests\Feature;

use App\Cars\Progress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * После выкладки и сбоя — продолжать сразу, а не через сутки (26.11.2026, владелец: «после выкладки сразу же как можно скорее
 * надо продолжать очередь»). Ошибки молчаливые: задача висит взятой до `retry_after` или падает без повтора, ход «Прикрепляем
 * фото» держит чтение и черновик часами.
 */
class QueueRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_worker_start_returns_jobs_taken_by_killed_worker_of_its_queues(): void
    {
        $job = fn (string $queue, ?int $reserved, int $attempts) => DB::table('jobs')->insertGetId(['queue' => $queue, 'payload' => '{}',
            'attempts' => $attempts, 'reserved_at' => $reserved, 'available_at' => time(), 'created_at' => time()]);
        $killed = $job('scan-bg', time() - 30, 1);
        $waiting = $job('scan', null, 0);
        $other = $job('files', time() - 30, 1);

        Artisan::call('queue:release-orphans', ['queues' => 'scan,scan-bg']);

        $row = DB::table('jobs')->find($killed);
        $this->assertNull($row->reserved_at, 'убитую — сразу в очередь, а не через retry_after');
        $this->assertSame(0, (int) $row->attempts, 'убийство — не попытка');
        $this->assertNull(DB::table('jobs')->find($waiting)->reserved_at);
        $this->assertNotNull(DB::table('jobs')->find($other)->reserved_at, 'чужие очереди — их работника');
    }

    public function test_job_that_keeps_killing_its_worker_stops_being_forgiven(): void
    {
        $id = DB::table('jobs')->insertGetId(['queue' => 'scan', 'payload' => '{}', 'attempts' => 1, 'reserved_at' => time(), 'available_at' => time(), 'created_at' => time()]);
        foreach ([1, 2] as $i) {
            Artisan::call('queue:release-orphans', ['queues' => 'scan']);
            DB::table('jobs')->where('id', $id)->update(['reserved_at' => time(), 'attempts' => 1]);
        }

        Artisan::call('queue:release-orphans', ['queues' => 'scan']);

        $this->assertSame(1, (int) DB::table('jobs')->find($id)->attempts, 'третий раз — попытка засчитана, tries её снимет');
    }

    public function test_file_waiting_in_queued_read_counts_as_reading_and_new_file_does_not(): void
    {
        \App\Mail\Jobs\ScanAttachments::dispatch('v:1', ['501'], true);
        $file = fn (string $id) => \Mockery::mock(\App\Mail\Scan\ScanFile::class, ['scanId' => $id]);

        $this->assertSame(['501'], \App\Mail\Jobs\ScanAttachments::reading([$file('501'), $file('502')]), 'ждущий в очереди — занят, новый — нет (его ставят)');
    }

    public function test_queued_progress_stops_blocking_after_an_hour(): void
    {
        Progress::queue(12345, Progress::MAIL.':1:all', 'Прикрепляем фото');
        $this->assertTrue(Progress::busy(12345, Progress::MAIL));

        $this->travel(61)->minutes();

        $this->assertFalse(Progress::busy(12345, Progress::MAIL), 'не начатая задача не держит машину полдня');
    }
}
