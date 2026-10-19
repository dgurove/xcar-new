<?php

namespace App\Offers\Console;

use App\Mail\Extraction\DocumentFields;
use App\Mail\Extraction\DocumentText;
use App\Mail\Scan\OfferSubject;
use App\Offers\Actions\SettleContract;
use App\Offers\Deal;
use App\Offers\DealContract;
use App\Offers\DealState;
use Illuminate\Console\Command;
use Throwable;

/**
 * Разово (19.10.2026, владелец: «почему не спарсился ПТС по 319914/2026, он же в договоре комиссии»): у идущих сделок с
 * ДКП госномер, (Э)ПТС и СТС из документов предложения и писем по нему — текстовым слоем, без OCR — в пустые поля машины;
 * договор собрался — номер, дата и менеджеру «ДКП готов» (`SettleContract`). Без `--apply` — что встанет, списком.
 */
class DealCarDocsCommand extends Command
{
    protected $signature = 'deals:car-docs {--apply}';

    protected $description = 'Госномер, ПТС и СТС из документов в машины идущих сделок с ДКП';

    public function handle(SettleContract $settle): int
    {
        $apply = (bool) $this->option('apply');
        foreach (Deal::with('offer.car')->where('state', DealState::Active)->get()->filter(fn (Deal $d) => $d->hasContract()) as $deal) {
            $car = $deal->offer->car;
            if (! $car || ($car->pts && $car->sts && $car->plate)) {
                continue;
            }
            $subject = new OfferSubject($deal->offer);
            $found = [];
            try {
                foreach ($subject->files() as $file) {
                    $text = DocumentText::layer($file);
                    foreach ($text ? array_intersect_key(DocumentFields::extract($text), array_flip(['plate', 'pts', 'sts'])) : [] as $field => $item) {
                        if (blank($car->{$field}) && ! isset($found[$field])) {
                            $found[$field] = $item;
                        }
                    }
                }
            } catch (Throwable $e) {
                $this->warn("сделка {$deal->id}: {$e->getMessage()}");

                continue;
            }
            if (! $found) {
                continue;
            }
            $line = "сделка {$deal->id} {$deal->offer->titleWithYear()}: ".implode(', ', array_map(fn ($f, $i) => "{$f} {$i['value']}", array_keys($found), $found));
            if ($apply) {
                $subject->apply($found, null);
                $contract = $settle(DealContract::for($deal->fresh())->fresh(['seller', 'buyer.party', 'deal.offer', 'deal.buyer']));
                $line .= $contract->isReady() ? ' — ДКП готов '.($contract->numberLabel() ?? '') : ' — ДКП ждёт: '.implode(', ', $contract->missing());
            }
            $this->line($line);
        }

        return self::SUCCESS;
    }
}
