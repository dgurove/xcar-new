<?php

namespace App\Media\Console;

use App\Media\Actions\UnmarkPhoto;
use App\Media\Watermark;
use App\Media\Watermarks;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Ночью уточнить прозрачности знаков по накопленному: кадры, почищенные вручную («Заменить своим файлом»), — точные пары
 * «со знаком — без знака»; снятые автоматически — копии со знаком в `private/marked`. Новые прозрачности ложатся в
 * реестр, только если остаток на контуре стал меньше; без пяти новых кадров с прошлого раза знак не трогается.
 */
class TuneWatermarks extends Command
{
    protected $signature = 'media:unmark-tune {--force : не ждать пяти новых кадров}';

    protected $description = 'Уточнить прозрачности водяных знаков по снятым и почищенным вручную кадрам';

    private const TAKE = 40;

    public function handle(): int
    {
        $pairs = $this->latest(UnmarkPhoto::MANUAL)
            ->flatMap(fn (Media $m) => [UnmarkPhoto::markedPath($m), $m->getCustomProperty('watermarked', false) ? Watermark::cleanPath($m) : $m->getPath()])
            ->all();
        foreach (Watermarks::registry() as $name => $mark) {
            if ($mark['off']) {
                continue;
            }
            $photos = $this->latest($name)->map(fn (Media $m) => UnmarkPhoto::markedPath($m))->all();
            $seen = count($photos) + count($pairs) / 2;
            if (! $this->option('force') && $seen < ($mark['tuned'] ?? 0) + 5) {
                continue;
            }
            $result = Process::timeout(900)->run(['nice', '-n', '15', config('xcar.unmark', 'unmark'), '--tune', $name, Watermarks::dirs(),
                ...$photos, ...($pairs ? ['--pairs', ...$pairs] : [])]);
            $answer = json_decode(trim((string) collect(explode("\n", trim($result->output())))->last()), true);
            if (! is_array($answer) || empty($answer['fills'])) {
                $this->line("{$mark['title']}: ".($answer['reason'] ?? $answer['error'] ?? 'не уточнён'));

                continue;
            }
            $better = $answer['before'] !== null && $answer['after'] !== null && $answer['after'] < $answer['before'] * 0.98;
            Watermarks::set($name, ['tuned' => $seen] + ($better ? ['fills' => $answer['fills'], 'tuned_at' => now()->toDateString()] : []));
            $line = "{$mark['title']}: {$answer['source']} {$answer['used']}, остаток {$answer['before']} → {$answer['after']}".($better ? ', принято' : ', не лучше');
            $this->info($line);
            Log::info('unmark-tune', $answer);
        }

        return self::SUCCESS;
    }

    /** Последние кадры, с которых знак снят (`$unmarked` — имя знака или «вручную»), у которых есть копия со знаком. */
    private function latest(string $unmarked)
    {
        return Media::query()->where('custom_properties->unmarked', $unmarked)->latest('updated_at')->limit(self::TAKE * 2)->get()
            ->filter(fn (Media $m) => is_file(UnmarkPhoto::markedPath($m)) && is_file($m->getPath()))
            ->take(self::TAKE)->values();
    }
}
