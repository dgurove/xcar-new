<?php

namespace App\Offers\Console;

use App\Mail\Message;
use App\Offers\Deal;
use App\Offers\Jobs\ShareReplyFiles;
use Illuminate\Console\Command;

/**
 * Разово (25.11.2026, сделка 0531/046/00572/26: Альфа прислала ПТС двумя фото .heic, почта отдала их `application/octet-stream`, и
 * они легли скрытыми кадрами — показать менеджеру было нечего): картинки писем поставщика по идущим сделкам, что лежат скрытым
 * кадром машины, — в документы этого письма (`letter`), как теперь делает `ShareReplyFiles`. Без `--apply` — список.
 */
class LetterImagesCommand extends Command
{
    protected $signature = 'deals:letter-images {--days=30} {--apply}';

    protected $description = 'Картинки писем поставщика в идущих сделках — из скрытых кадров в документы письма';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $n = 0;
        foreach (Deal::with('offer')->active()->get() as $deal) {
            $offer = $deal->offer;
            if (! $offer) {
                continue;
            }
            $car = $offer->carRecord();
            $hidden = $car->getMedia('photos')->filter(fn ($m) => $m->getCustomProperty('hidden') && ! $m->getCustomProperty('migtorg'))->keyBy(fn ($m) => $m->getCustomProperty('sha'));
            if ($hidden->isEmpty()) {
                continue;
            }
            $letters = Message::with('attachments')->whereHas('thread', fn ($q) => $q->where('offer_id', $offer->id))->where('direction', 'in')
                ->where('created_at', '>=', max($deal->created_at, now()->subDays((int) $this->option('days'))))->get();
            foreach ($letters as $m) {
                foreach ($m->attachments->filter(fn ($a) => ! $a->is_inline && $a->isImage()) as $a) {
                    $contents = $a->contents();
                    $sha = $contents !== null ? hash('sha256', $contents) : null;
                    if (! $sha || ! $hidden->has($sha)) {
                        continue;
                    }
                    $n++;
                    $this->line("сделка {$deal->id} {$offer->title()}: {$a->filename} (письмо {$m->id})");
                    $apply && ShareReplyFiles::photoToDocument($car, $sha, ['letter' => $m->id]);
                }
            }
        }
        $this->info(($apply ? 'Перенесено' : 'Перенесётся').": {$n}");

        return self::SUCCESS;
    }
}
