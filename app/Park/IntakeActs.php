<?php

namespace App\Park;

use App\Mail\Attachment;
use App\Mail\Direction;
use App\Mail\Thread;
use App\Support\Docs;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Акты ТС файлами для сверки дат (05.11.2026, владелец: «у сотен тачек проверить, соответствует ли дата приёма акту; там
 * от руки, автоматически не сделаешь — но вот тачка, ставка, даты, кликнуть и посмотреть акт»). Отдельной коллекции у акта
 * нет: это бумага машины, названная актом (`report_doc` = act или `ReportDoc::ofName` — то же правило, что слот акта в
 * `IntakeReport`), или вложение письма ветки ТС в ящике парковки — входящее или наш отчёт. Акт выдачи — по слову «выдач»
 * (`ofName` их не различает). Акта по имени нет (у Альфы СПб сканы приходят «IMG_….jpg», «Image_002.pdf») — остальные
 * бумаги машины и файлы писем, кроме фото: акт среди них. Повтор по имени (`Docs::norm`) не берётся. На все ТС — два запроса.
 */
final class IntakeActs
{
    /**
     * @param  Collection<int, Vehicle>  $vehicles
     * @return array<int, array{intake: list<array>, release: list<array>, other: list<array>}> id ТС → документы для `x-ui.doc`
     */
    public static function for(Collection $vehicles): array
    {
        if ($vehicles->isEmpty()) {
            return [];
        }
        $vehicles = EloquentCollection::make($vehicles->all());
        $vehicles->loadMissing(['media' => fn ($q) => $q->where('collection_name', 'papers')]);
        $out = [];
        $seen = [];
        $put = function (int $vid, string $name, array $doc, bool $act) use (&$out, &$seen) {
            $key = Docs::norm(pathinfo($name, PATHINFO_FILENAME));
            if (isset($seen[$vid][$key])) {
                return;
            }
            $seen[$vid][$key] = true;
            $out[$vid] ??= ['intake' => [], 'release' => [], 'other' => []];
            $out[$vid][$act ? (preg_match('/выдач|возврат/u', mb_strtolower($name)) ? 'release' : 'intake') : 'other'][] = $doc;
        };
        foreach ($vehicles as $v) {
            foreach ($v->getMedia('papers') as $m) {
                /** @var Media $m */
                $act = ($m->getCustomProperty('report_doc') ?? ReportDoc::ofName($m->file_name)?->value) === ReportDoc::Act->value;
                $put($v->id, $m->file_name, Docs::media($m), $act);
            }
        }
        $threads = Thread::park()->whereIn('vehicle_id', $vehicles->modelKeys())->pluck('vehicle_id', 'id');
        if ($threads->isNotEmpty()) {
            $files = Attachment::query()->join('mail_messages as m', 'm.id', '=', 'mail_attachments.message_id')
                ->whereIn('m.thread_id', $threads->keys())->where('mail_attachments.is_inline', false)
                ->orderByRaw("case when m.direction = 'in' then 0 else 1 end")->orderBy('m.date_at')
                ->get(['mail_attachments.*', 'm.thread_id', 'm.direction as message_direction']);
            foreach ($files as $a) {
                $name = (string) $a->filename;
                $act = ReportDoc::ofName($name) === ReportDoc::Act;
                // Фото из писем — сотни кадров на машину: в «прочее» не идут, только акт по имени.
                if (! $act && Docs::type($a->mime, $name) === 'image') {
                    continue;
                }
                $doc = Docs::attachment($a, '/mail');
                // Наш отчёт — подписью, чтобы не путать с актом страховой.
                if ($a->message_direction === Direction::Out->value) {
                    $doc['label'] .= ', наш отчёт';
                }
                $put($threads[$a->thread_id], $name, $doc, $act);
            }
        }

        return $out;
    }
}
