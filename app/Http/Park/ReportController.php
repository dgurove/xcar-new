<?php

namespace App\Http\Park;

use App\Mail\Composer;
use App\Mail\Jobs\SendMessage;
use App\Mail\Message;
use App\Mail\SendState;
use App\Mail\Thread;
use App\Mail\Threads;
use App\Media\Hidden;
use App\Media\PhotoIngest;
use App\Park\Actions\MarkReportSent;
use App\Park\IntakeReport;
use App\Park\Jobs\BuildIntakeReport;
use App\Park\Report;
use App\Park\ReportDoc;
use App\Park\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Image\Image;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Отчёт о приёме вендору (12.10.2026): форма `x-park.report-form` — шаг «Письмо вендору» окна действия (после «Принять
 * и сформировать письмо» и по задаче «Отправить акт приёма»), и «Дослать фото и документы» на письмо вендора, что их
 * просит (`?parent=`). С 29.10.2026 (Никита: «работает через раз, Альфа не видит вложений») файлы грузятся по одному
 * сразу при выборе (`file`: ужатые в телефоне кадры — в «Фото при приёме», снимки и файлы — в документы ТС с видом), а
 * отправка несёт только номера: отчёт (`Park\Report`) собирает очередь (`BuildIntakeReport`), шаг «Отчёт» — когда письма
 * ушли. «Уже отправили» — отчёт ушёл с личной почты.
 */
class ReportController
{
    public function store(Request $request, Vehicle $vehicle, Composer $composer)
    {
        $kinds = [...array_map(fn (ReportDoc $k) => $k->value, IntakeReport::kinds($vehicle->loadMissing('vendor'))), ReportDoc::Other->value];
        $data = $request->validate([
            'to' => ['required', 'string', 'max:500'], 'cc' => ['nullable', 'string', 'max:500'], 'text' => ['nullable', 'string', 'max:2000'],
            'parent' => ['nullable', 'integer'],
            'photos' => ['nullable', 'array'], 'photos.*' => ['integer'],
            'docs' => ['nullable', 'array'], 'docs.*' => ['array'], 'docs.*.*' => ['integer'],
        ], ['to.required' => 'Кому отправить']);
        abort_unless($vehicle->accepted_at, 404);
        if (! $composer->emails($data['to'])) {
            return back()->withInput()->withErrors(['to' => 'Нужен адрес почты']);
        }
        $media = $vehicle->photos()->merge($vehicle->papers())->keyBy('id');
        $own = fn (array $ids, string $collection) => array_values(array_unique(array_filter(array_map('intval', $ids), fn ($id) => $media->get($id)?->collection_name === $collection)));
        $photos = $own($data['photos'] ?? [], 'photos');
        $docs = array_filter(array_map(fn ($ids) => $own($ids, 'papers'), array_intersect_key($data['docs'] ?? [], array_flip($kinds))));
        if (! $photos && ! $docs) {
            return back()->withInput()->withErrors(['report' => 'Отметьте фото или добавьте документ']);
        }
        $parent = $this->parent($vehicle, $data['parent'] ?? null);

        // Двойное нажатие и старая вкладка второй отчёт не заводят, пока первый в пути.
        $lock = Cache::lock('park-report:'.$vehicle->id, 30);
        if (! $lock->get()) {
            return $this->back($request, 'Отчёт уже отправляется');
        }
        try {
            if (Report::inFlight($vehicle)) {
                return $this->back($request, 'Отчёт уже отправляется');
            }
            $report = DB::transaction(fn () => Report::create([
                'vehicle_id' => $vehicle->id, 'user_id' => $request->user()->id, 'parent_id' => $parent?->id,
                'to' => $data['to'], 'cc' => $data['cc'] ?? null, 'text' => $data['text'] ?? null,
                'photos' => $photos, 'docs' => $docs, 'state' => Report::QUEUED, 'attempt' => 1,
            ]));
            BuildIntakeReport::dispatch($report->id, $report->attempt);
        } finally {
            $lock->release();
        }

        return $this->back($request, 'Отчёт отправляется');
    }

    /**
     * Файл отчёта — сразу при выборе, по одному (`report_controller`): `kind=photo` — кадр, ужатый в телефоне, в «Фото при
     * приёме»; иначе вид документа (`ReportDoc`): снимок «Снять» — страница (`scan` — один выбор, `page` — порядок; одним PDF
     * их склеит отправка), файл — как есть. Ответ — номер, он и уходит в форму.
     */
    public function file(Request $request, Vehicle $vehicle, PhotoIngest $ingest)
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:40960'],
            'kind' => ['required', 'string'],
            'scan' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9-]+$/'],
            'page' => ['nullable', 'integer', 'min:0', 'max:500'],
        ]);
        abort_unless($vehicle->accepted_at, 404);
        $file = $request->file('file');
        try {
            if ($data['kind'] === 'photo') {
                $m = $ingest->fromPhone($vehicle, 'photos', $request, properties: ['stage' => 'intake', 'source' => 'app'] + Hidden::incoming($vehicle));

                return response()->json(['id' => $m->id]);
            }
            $kind = ReportDoc::tryFrom($data['kind']) ?? abort(422, 'Нет такого документа');
            $path = $this->readable($file->getRealPath(), $ingest);
            $ext = $path !== $file->getRealPath() ? 'jpg' : strtolower($file->getClientOriginalExtension() ?: ($file->guessExtension() ?: 'pdf'));
            $base = $kind === ReportDoc::Other
                ? (trim((string) preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))) ?: 'dokument')
                : $kind->value;
            $adder = $path !== $file->getRealPath() ? $vehicle->addMedia($path) : $vehicle->addMedia($file);
            $m = $adder->usingFileName(mb_substr($base, 0, 120).'-'.uniqid().'.'.$ext)
                ->withCustomProperties(array_filter(['report_doc' => $kind->value, 'source' => 'app', 'scan' => $data['scan'] ?? null, 'page' => $data['page'] ?? null], fn ($v) => $v !== null))
                ->toMediaCollection('papers');
        } catch (HttpException $e) {
            throw $e;
        } catch (Throwable $e) {
            return response()->json(['message' => 'Файл не сохранился: '.$e->getMessage()], 422);
        }

        return response()->json(['id' => $m->id]);
    }

    /**
     * Снимок документа — картинкой, которую соберёт в PDF dompdf (JPEG, PNG, GIF): HEIC с Android (телефон его не ужал) и
     * прочее — в JPEG здесь; не читается вовсе — ошибка сразу у плитки, а не пустая страница у вендора. Не картинка — как есть.
     */
    private function readable(string $path, PhotoIngest $ingest): string
    {
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! str_starts_with($mime, 'image/') && ! $ingest->isHeic($path)) {
            return $path;
        }
        if ($ingest->isHeic($path)) {
            return $ingest->toJpeg($path);
        }
        $type = @getimagesize($path)[2] ?? null;
        if (in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF], true)) {
            return $path;
        }
        $base = tempnam(sys_get_temp_dir(), 'scan');
        $jpg = $base.'.jpg';
        @unlink($base);
        try {
            Image::useImageDriver('gd')->loadFile($path)->quality(85)->save($jpg);
        } catch (Throwable) {
            @unlink($jpg);
            throw new RuntimeException('снимок не читается, снимите заново');
        }

        return $jpg;
    }

    /**
     * «Повторить»: собранные письма, что не ушли, — снова в отправку, если их вложения на месте. Не собран, или файла письма
     * уже нет (вечный повтор той же ошибки) — собирается заново из файлов ТС новой попыткой (`attempt`), неушедшие письма
     * убираются: они не уходили и не уйдут.
     */
    public function retry(Request $request, Vehicle $vehicle, Report $report, Threads $threads)
    {
        abort_unless($report->vehicle_id === $vehicle->id, 404);
        if ($report->state !== Report::FAILED) {
            return $this->back($request, $report->state === Report::SENT ? 'Отчёт уже ушёл' : 'Отчёт уже отправляется');
        }
        // Отчёт `failed`, только когда ни одна часть не в пути (`ReportDelivery::check`): неушедшие — это не ушедшие.
        $unsent = Message::with(['attachments', 'thread'])->whereKey($report->messages ?? [])->where('send_state', SendState::Failed->value)->get();
        if ($unsent->isNotEmpty() && $unsent->every(fn (Message $m) => $m->attachments->every->isOnDisk())) {
            DB::transaction(function () use ($report, $unsent) {
                $report->update(['state' => Report::QUEUED, 'error' => null]);
                Message::whereKey($unsent->modelKeys())->where('send_state', SendState::Failed->value)->update(['send_state' => SendState::Queued->value, 'send_error' => null]);
            });
            $unsent->each(fn (Message $m) => SendMessage::dispatch($m->id));

            return $this->back($request, 'Отчёт отправляется');
        }
        DB::transaction(function () use ($report, $unsent, $threads) {
            foreach ($unsent as $m) {
                $m->delete();
                $m->thread && $threads->refresh($m->thread);
            }
            $report->update(['state' => Report::QUEUED, 'error' => null, 'messages' => null, 'attempt' => $report->attempt + 1]);
        });
        BuildIntakeReport::dispatch($report->id, $report->attempt);

        return $this->back($request, 'Отчёт отправляется');
    }

    /** Письмо, на которое досылаем: только входящее из переписки по этой ТС на парковке. */
    private function parent(Vehicle $vehicle, ?int $id): ?Message
    {
        return $id ? Message::whereKey($id)->whereIn('thread_id', Thread::where('vehicle_id', $vehicle->id)->park()->where('buyer', false)->select('id'))->first() : null;
    }

    /** Из окна действия — окно закрывается (из «Заявок» — к следующей заявке); иначе дело. */
    private function back(Request $request, string $toast)
    {
        $vehicle = $request->route('vehicle');
        if ($request->header('Turbo-Frame') === RequestController::WINDOW) {
            return redirect(RequestController::window($vehicle, 'done', $request))->with('toast', $toast);
        }

        return redirect('/cars/'.$vehicle->id)->with('toast', $toast);
    }

    /** «Уже отправили»: отчёт ушёл не через нас (личная почта) — шаг сделан без письма. */
    public function sent(Request $request, Vehicle $vehicle, MarkReportSent $mark)
    {
        if (! MarkReportSent::done($vehicle)) {
            $mark($vehicle, $request->user(), 'Акт приёма', now(), null, ['manual' => true]);
        }

        return $this->back($request, 'Отчёт отмечен отправленным');
    }
}
