<?php

namespace Tests\Feature;

use App\Mail\Extraction\DocumentFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Документы ТС из бумаг страховой для ДКП (19.10.2026, владелец: «почему не спарсился ПТС, он же в договоре комиссии»):
 * акт и договор комиссии Совкомбанка (Lada Granta 319914/2026) — госномер, ЭПТС, СТС таблицей и строкой «ПТС (электронный)».
 */
class VehicleDocsParseTest extends TestCase
{
    use RefreshDatabase;

    public function test_sovcombank_act_gives_plate_pts_sts(): void
    {
        $f = DocumentFields::extract(file_get_contents(__DIR__.'/../Fixtures/docs/sovcombank-act.txt'));
        $this->assertSame('М719ТХ193', $f['plate']['value']);
        $this->assertSame('164301059905697', $f['pts']['value']);
        $this->assertSame('99 58 458318', $f['sts']['value']);
    }

    public function test_paper_pts_and_inline_forms(): void
    {
        $this->assertSame('77 ТТ 123456', DocumentFields::pts('ПТС: 77ТТ 123456, выдан МРЭО'));
        $this->assertSame('164301059905697', DocumentFields::pts('ПТС (электронный) серия: 164301059905697;'));
        $this->assertSame('99 58 458318', DocumentFields::sts('СТС (оригинал) серия: 9958 458318;'));
        $this->assertNull(DocumentFields::pts('Паспорт гражданина РФ 4512 345678'));
    }
}
