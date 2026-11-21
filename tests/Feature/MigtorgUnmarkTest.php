<?php

namespace Tests\Feature;

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
}
