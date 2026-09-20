<?php

namespace App\Http\Admin;

use App\Mail\Actions\ArchiveThread;
use App\Mail\Actions\PromoteCandidate;
use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Mail\Scope;
use App\Support\ListPrefs;
use App\Support\ListView;
use App\Support\Nav;
use App\Vendors\Vendor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * «Из писем» — один экран на CRM и стоянку: кандидат — одна ТС со всеми письмами о ней;
 * пресеты по состоянию, сортировка, поиск и вендор, три вида с окошком строки.
 * Стоянка наследует с `Scope::Park` и своим «Завести».
 */
class CandidateController
{
    public const PRESETS = ['new' => 'Ждут', 'rejected' => 'Архив', 'promoted' => 'Заведённые'];

    public const SORTS = ['fresh' => 'Свежие письма', 'waiting' => 'Дольше ждут', 'answer' => 'По сроку ответа'];

    public function __construct(protected Scope $scope = Scope::Offers, protected string $base = '/offers/from-mail', protected string $mail = '/work/mail') {}

    public function index(Request $request)
    {
        ListPrefs::sync($request, $this->scope->value.'-candidates');
        $preset = array_key_exists($request->query('preset', ''), self::PRESETS) ? $request->query('preset') : 'new';
        $q = trim((string) $request->query('q'));
        $vendor = $request->query('vendor');
        $sort = array_key_exists($request->query('sort', ''), self::SORTS) ? $request->query('sort') : 'fresh';
        $list = $this->query()->where('state', CandidateState::from($preset))
            ->when($vendor, fn ($w, $id) => $w->where('vendor_id', $id))
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s->where('code', 'ilike', "%{$q}%")->orWhere('key', 'ilike', '%'.strtoupper($q).'%')->orWhere('subject', 'ilike', "%{$q}%")));
        match ($sort) {
            'waiting' => $list->orderBy('last_message_at')->orderBy('id'),
            'answer' => $list->orderByRaw("(extracted->'answer_by'->>'value') asc nulls last")->orderByDesc('last_message_at'),
            default => $list->orderByDesc('last_message_at')->orderByDesc('id'),
        };
        $counts = $this->query()->selectRaw('state, count(*) as n')->groupBy('state')->pluck('n', 'state');

        return view('mail.candidates.index', [
            'candidates' => ListView::paginate($request, $list),
            'preset' => $preset, 'presets' => self::PRESETS, 'sort' => $sort, 'q' => $q, 'vendor' => $vendor,
            'counts' => $counts->all(),
            'vendors' => Vendor::whereIn('id', $this->query()->whereNotNull('vendor_id')->distinct()->pluck('vendor_id'))->orderBy('name')->pluck('name', 'id'),
        ] + $this->links());
    }

    public function peek(Candidate $candidate)
    {
        abort_if($candidate->scope !== $this->scope, 404);
        $candidate->load(['messages.attachments', 'messages.thread', 'vendor', 'offer', 'vehicle.brand', 'vehicle.model']);

        return view('mail.candidates.peek', ['c' => $candidate] + $this->links());
    }

    /** Окно писем кандидата (фрейм letters-frame): все письма целиком с «Ответить». */
    public function letters(Candidate $candidate)
    {
        abort_if($candidate->scope !== $this->scope, 404);
        $candidate->load(['messages.attachments', 'messages.addresses', 'messages.author']);

        return view('mail.candidates.letters', ['c' => $candidate, 'base' => $this->mail]);
    }

    public function promote(Request $request, Candidate $candidate)
    {
        abort_if($candidate->state === CandidateState::Promoted || $candidate->scope !== $this->scope, 404);
        $offer = app(PromoteCandidate::class)($candidate, $request->user());

        return redirect("/offers/{$offer->number}")->with('toast', $offer->wasRecentlyCreated ? 'Черновик заведён, фото подтягиваются' : 'Письма привязаны к предложению');
    }

    public function reject(Candidate $candidate, ArchiveThread $archive)
    {
        abort_if($candidate->scope !== $this->scope, 404);
        $candidate->update(['state' => $candidate->state === CandidateState::Rejected ? CandidateState::New : CandidateState::Rejected]);
        // Один архив на всё: письма кандидата уходят из «Входящих» вместе с ним и возвращаются вместе.
        $archive->candidate($candidate, $candidate->state === CandidateState::Rejected);
        Nav::forgetStaffCounts();

        return back()->with('toast', $candidate->state === CandidateState::Rejected ? 'В архиве' : 'Снова ждёт');
    }

    protected function query(): Builder
    {
        return Candidate::query()->with(['message.attachments', 'media', 'vendor', 'offer', 'vehicle.brand', 'vehicle.model', 'vehicle.media', 'vehicle.requests'])->where('scope', $this->scope);
    }

    /** Адреса поверхности для шаблонов: свой список, свой ящик, стоянка или CRM. */
    protected function links(): array
    {
        return ['base' => $this->base, 'mail' => $this->mail, 'park' => $this->scope === Scope::Park];
    }
}
