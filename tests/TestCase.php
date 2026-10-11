<?php

namespace Tests;

use App\Billing\Party;
use App\Http\Cabinet\PassportScanController;
use App\Offers\Actions\SyncDealInvoices;
use App\Users\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Паспорт покупателя-физлица с обеими страницами скана (11.10.2026: без скана счёт ПРАЙМ и ДКП не встают) —
     * и пересборка его сделок, как после загрузки скана. Нужен `Storage::fake('private')`.
     */
    protected function passportFor(User $user): void
    {
        $party = Party::forUser($user);
        $party->update(['kind' => 'person', 'name' => $party->name ?: $user->name, 'birth_at' => '1985-05-05', 'passport' => '11 11 111111',
            'passport_issued' => 'ГУ МВД', 'passport_issued_at' => '2010-10-10', 'reg_address' => 'г. Москва']);
        foreach (array_keys(Party::PASSPORT_PAGES) as $page) {
            $party->addMediaFromString('scan')->usingFileName($page.'.jpg')->withCustomProperties(['page' => $page, 'read' => 'done'])->toMediaCollection('passport');
        }
        PassportScanController::resyncFor($user->fresh(), $user, app(SyncDealInvoices::class));
    }
}
