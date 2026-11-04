<?php

namespace Tests\Feature;

use App\Support\FieldLabels;
use App\Telegram\Jobs\NotifyOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * «Не получилось» с причиной (04.11.2026): сбой фрейма доходит владельцу один раз, номер ПСБ «0207-26-06» в истории — текст,
 * а не дата (предложение падало 500).
 */
class ClientFailedTest extends TestCase
{
    use RefreshDatabase;

    public function test_frame_failure_reaches_owner_once(): void
    {
        Queue::fake();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $body = ['url' => '/mail/2697/reply/7845', 'status' => 404, 'frame' => 'reply', 'kind' => 'frame'];
        $this->postJson('/client/failed', $body)->assertNoContent();
        $this->postJson('/client/failed', [...$body, 'url' => '/mail/2698/reply/7900'])->assertNoContent();
        Queue::assertPushed(NotifyOwner::class, 1);
    }

    public function test_claim_ref_like_date_is_text(): void
    {
        $this->assertSame('0207-26-06', FieldLabels::value('claim_ref', '0207-26-06'));
        $this->assertNotSame('2026-11-04', FieldLabels::value('closes_at', '2026-11-04'));
    }
}
