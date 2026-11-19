<?php

namespace App\Offers\Actions;

use App\Live\RefreshDeal;
use App\Offers\ContractEdit;
use App\Offers\ContractNumber;
use App\Offers\Deal;
use App\Offers\DealContract;
use App\Offers\DealState;
use App\Offers\OfferEventType;
use App\Support\Soffice;
use App\Users\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Исправить договор» (19.11.2026, вариант Б владельца): скачать Word, поправить, приложить — менеджеру уйдёт исправленный
 * (`ContractEdit`). Править можно готовый и ещё не отправленный договор (отправленный — сначала «Отозвать»,
 * `ShowToManager::recallContract`), пока сделка идёт и подписанный не приложен.
 */
final class EditContract
{
    public static function allows(Deal $deal): bool
    {
        $contract = DealContract::for($deal);

        return $deal->hasContract() && $deal->state !== DealState::Cancelled && ! $deal->isAuction() && $contract->shown_at === null
            && $contract->isReady() && ! $deal->contract_signed_at && $deal->getMedia('signed_contract')->isEmpty();
    }

    /**
     * Word для правки: номер договора выдаётся сейчас (`ContractNumber`, тот же, что получил бы при отправке) — в исправленном
     * файле он уже стоит, и отправка его не поменяет.
     */
    public function word(Deal $deal, User $by): DealContract
    {
        if (! self::allows($deal) && ! ContractEdit::has($deal)) {
            throw ValidationException::withMessages(['file' => 'Этот договор сейчас не исправить']);
        }

        return DB::transaction(function () use ($deal) {
            $c = DealContract::whereKey(DealContract::for($deal)->id)->lockForUpdate()->firstOrFail();
            ContractNumber::assign($c);

            return $c;
        });
    }

    public function attach(Deal $deal, UploadedFile $file, User $by): void
    {
        if (! self::allows($deal)) {
            throw ValidationException::withMessages(['file' => 'Этот договор сейчас не исправить: он отправлен или уже подписан']);
        }
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, [...ContractEdit::WORD, 'pdf'], true)) {
            throw ValidationException::withMessages(['file' => 'Нужен файл Word (.docx) или PDF']);
        }
        // PDF из Word — сразу, до записи: не собрался — файл не принимаем, менеджеру нечего было бы открыть.
        $pdf = null;
        if ($ext !== 'pdf') {
            try {
                $pdf = Soffice::convert((string) file_get_contents($file->getRealPath()), $ext, 'pdf');
            } catch (\Throwable $e) {
                report($e);
                throw ValidationException::withMessages(['file' => 'Не получилось открыть файл: сохраните его в Word (.docx) и приложите снова']);
            }
        }
        $contract = $this->word($deal, $by);
        $name = 'Договор'.($contract->code ? ' № '.$contract->code : '');
        $deal->addMedia($file)->usingName($name)->usingFileName(self::fileName($contract, $ext))->toMediaCollection('dkp_edited');
        if ($pdf !== null) {
            $deal->addMediaFromString($pdf)->usingName($name)->usingFileName(self::fileName($contract, 'pdf'))->toMediaCollection('dkp_edited_pdf');
        } else {
            $deal->clearMediaCollection('dkp_edited_pdf');
        }
        $contract->update(['edited_at' => now(), 'edited_by' => $by->id, 'edited_base' => ContractEdit::fingerprint($deal->fresh())]);
        $deal->offer->log(OfferEventType::Note, $by, ['text' => $name.' заполнен вручную']);
        RefreshDeal::of($deal);
    }

    /** «Вернуть сформированный» — снова из данных сделки. Отправленный — только после «Отозвать». */
    public function revert(Deal $deal, User $by): void
    {
        $contract = DealContract::for($deal);
        if ($contract->shown_at !== null) {
            throw ValidationException::withMessages(['file' => 'Договор у менеджера: сначала отзовите его']);
        }
        $deal->clearMediaCollection('dkp_edited');
        $deal->clearMediaCollection('dkp_edited_pdf');
        $contract->update(['edited_at' => null, 'edited_by' => null, 'edited_base' => null]);
        $deal->offer->log(OfferEventType::Note, $by, ['text' => 'Договор снова сформирован из данных сделки']);
        RefreshDeal::of($deal);
    }

    private static function fileName(DealContract $contract, string $ext): string
    {
        return 'dogovor'.($contract->code ? '-'.$contract->code : '').'.'.$ext;
    }
}
