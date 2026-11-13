<?php

namespace Tests\Feature;

use App\Billing\Jobs\ReadPassport;
use App\Billing\Party;
use App\Users\Role;
use App\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Прочитанное со скана паспорта — в данные покупателя на сервере (13.11.2026, владелец по Мишину: «фотки прикреплены, а поля не
 * распознались, приходится скачивать и заново закидывать»): пустые поля заполняются, вписанное не трогается; «Распознать
 * заново» читает обе страницы снова и пишет поверх.
 */
class PassportScanApplyTest extends TestCase
{
    use RefreshDatabase;

    private function party(): Party
    {
        Storage::fake('private');
        Storage::fake('public');
        $party = Party::create(['kind' => 'person', 'name' => 'Мишин Родион Валерьевич', 'passport_code' => '770-999']);
        $read = [
            'main' => ['passport' => '4510 950314', 'passport_issued_at' => '2010-09-29', 'passport_code' => '770-121', 'birth_at' => '1990-09-18', 'birth_place' => 'ГОР. МОСКВА'],
            'reg' => ['reg_address' => 'ЗНАМЕНСКИЕ САДКИ УЛ., Д. 1, КВ. 56'],
        ];
        foreach ($read as $page => $fields) {
            $party->addMedia(UploadedFile::fake()->image($page.'.jpg'))->withCustomProperties(['page' => $page, 'read' => 'done', 'fields' => $fields])
                ->toMediaCollection('passport');
        }

        return $party->fresh();
    }

    public function test_read_fields_fill_empty_party_fields(): void
    {
        $party = $this->party();
        ReadPassport::apply($party->passportScan('reg'));

        $party->refresh();
        $this->assertSame('4510 950314', $party->passport);
        $this->assertSame('ЗНАМЕНСКИЕ САДКИ УЛ., Д. 1, КВ. 56', $party->reg_address);
        $this->assertSame('770-999', $party->passport_code, 'вписанное руками не перетирается');
    }

    public function test_reread_marks_pages_and_writes_over(): void
    {
        Queue::fake();
        $party = $this->party();
        $admin = User::create(['name' => 'Андрей', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);

        $this->actingAs($admin)->postJson("http://xcar.localhost/parties/{$party->id}/passport/read")->assertOk()->assertJsonPath('reading', true);
        Queue::assertPushed(ReadPassport::class, 2);

        // Дочиталось — поверх вписанного.
        foreach ($party->fresh()->getMedia('passport') as $m) {
            $m->setCustomProperty('read', 'done')->save();
        }
        ReadPassport::apply($party->fresh()->passportScan('main'));
        $this->assertSame('770-121', $party->fresh()->passport_code);
    }
}
