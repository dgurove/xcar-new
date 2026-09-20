<?php

namespace App\Park\Actions;

use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Mail\Extraction\CandidateCard;
use App\Mail\Thread;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Support\Nav;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Заведена по ошибке»: ТС и её заявки исчезают, как будто «Завести» не нажимали. Ветки отвязываются,
 * кандидат «Из писем» возвращается в «Ждут» вместе с кадрами из письма — завести можно заново.
 * Пока ТС только ожидается: принятую, едущую, с бумагами или деньгами — не отменить, только «Не привезена».
 * Заменяет прежнее «удалить без следов»: у ТС из письма следы (ветки, кадры, события) есть с первой секунды.
 */
final class UnwindVehicle
{
    public static function allowed(Vehicle $vehicle): bool
    {
        return $vehicle->state === VehicleState::Expected
            && ! $vehicle->docs()->exists() && ! $vehicle->charges()->exists() && ! $vehicle->invoices()->exists() && ! $vehicle->inspections()->exists();
    }

    /** @return ?Candidate кандидат, вернувшийся в «Ждут» */
    public function __invoke(Vehicle $vehicle, User $by): ?Candidate
    {
        if (! self::allowed($vehicle)) {
            throw ValidationException::withMessages(['vehicle' => 'У ТС уже есть приём, бумаги или деньги, отменить нельзя']);
        }
        Nav::forgetStaffCounts();

        return DB::transaction(function () use ($vehicle) {
            $candidate = Candidate::where('vehicle_id', $vehicle->id)->where('state', CandidateState::Promoted)->latest('id')->first();
            Thread::where('vehicle_id', $vehicle->id)->update(['vehicle_id' => null]);
            if ($candidate) {
                // Фото ТС уходят вместе с ней (вложения писем закреплены, при новом «Завести» приедут снова), кандидату — кадр карточки.
                $candidate->update(['state' => CandidateState::New, 'vehicle_id' => null]);
                if ($candidate->message) {
                    app(CandidateCard::class)->make($candidate, $candidate->message);
                }
            }
            // Заявки, события, осмотры — каскадом по FK; медиа — spatie при удалении модели.
            $vehicle->delete();

            return $candidate;
        });
    }
}
