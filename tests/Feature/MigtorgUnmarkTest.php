<?php

namespace Tests\Feature;

use App\Media\Actions\UnmarkPhoto;
use App\Media\MigtorgMarks;
use App\Media\PhotoIngest;
use App\Media\Unmark;
use App\Offers\Offer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «Оригинал» Мигторга тоже проверяется на их знак (21.11.2026, Lada Vesta № 3952: все 29 кадров с «MIG TORG», приём пропускал
 * снятие у кадров с `migtorg`). Ошибка молчалива: знак уходит менеджерам и покупателям.
 */
class MigtorgUnmarkTest extends TestCase
{
    use RefreshDatabase;

    public function test_migtorg_original_goes_through_unmark(): void
    {
        $fake = new class
        {
            public array $seen = [];

            public function __invoke(string $path, ?string $mark = null): ?array
            {
                $this->seen[] = $path;

                return null;
            }
        };
        $this->app->instance(Unmark::class, $fake);
        $offer = Offer::create(['claim_ref' => '7592/046/03769/26']);
        $file = tempnam(sys_get_temp_dir(), 'mig').'.jpg';
        imagejpeg(imagecreatetruecolor(400, 300), $file);

        $media = app(PhotoIngest::class)->add($offer, 'photos', $file, 'migtorg-1.jpg', ['migtorg' => 'uuid-1']);

        $this->assertCount(1, $fake->seen, 'кадр Мигторга проверен на знак');
        $this->assertSame('uuid-1', $media->getCustomProperty('migtorg'));
    }

    /** Лот без знака: одиночное «снятие» на листе осмотра ложное — кадр возвращается как был (4370, 2611113876). */
    public function test_lone_unmark_in_clean_lot_is_undone(): void
    {
        $fake = new class
        {
            public int $calls = 0;

            public function __invoke(string $path, ?string $mark = null, bool $turns = false): ?array
            {
                if ($this->calls++ !== 0) {
                    return null;
                }
                $out = tempnam(sys_get_temp_dir(), 'clean').'.jpg';
                copy($path, $out);

                return ['path' => $out, 'mark' => 'migtorg'];
            }
        };
        $this->app->instance(Unmark::class, $fake);
        $offer = Offer::create(['claim_ref' => '4370/046/00001/26']);
        foreach (range(1, 10) as $i) {
            $file = tempnam(sys_get_temp_dir(), 'mig').'.jpg';
            imagejpeg(imagecreatetruecolor(400, 300), $file);
            app(PhotoIngest::class)->add($offer, 'photos', $file, "migtorg-{$i}.jpg", ['migtorg' => "uuid-{$i}"]);
        }
        $lone = $offer->photos()->first(fn ($m) => $m->getCustomProperty('unmarked'));
        $this->assertNotNull($lone, 'приём снял «знак» с первого кадра');
        $this->assertFileExists(UnmarkPhoto::markedPath($lone));

        $this->app->forgetInstance(Unmark::class); // откат настоящего `Unmark` не зовёт
        $result = app(MigtorgMarks::class)->settle($offer);

        $this->assertFalse($result['marked']);
        $this->assertSame(1, $result['undone']);
        $this->assertNull($lone->refresh()->getCustomProperty('unmarked'));
        $this->assertTrue(MigtorgMarks::marked(75, 78));
        $this->assertFalse(MigtorgMarks::marked(2, 101));
        $this->assertTrue(MigtorgMarks::marked(2, 5));
    }
}
