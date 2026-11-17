<?php

namespace Tests\Feature;

use App\Mail\Events\MessageSent;
use App\Mail\Message;
use App\Mail\Template;
use App\Mail\Thread;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\PlaceBid;
use App\Offers\DealScheme;
use App\Offers\ExtraRequest;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Position;
use App\Workflow\Preset;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Доп. запрос поставщику (17.11.2026, CTTM Cargoline 5491/046/00861/26): фразы галок одним письмом, и — молчаливое и дорогое —
 * запрос не считается письмом этапа: иначе «Подтверждаем актуальность» стояло бы «ушло», а ушёл только запрос ПТС.
 */
class ExtraRequestTest extends TestCase
{
    use RefreshDatabase;

    private function pick(string ...$names)
    {
        return Template::requests()->whereIn('name', $names)->get();
    }

    public function test_phrases_join_into_one_request(): void
    {
        $this->assertSame('прислать ПТС и согласовать осмотр', ExtraRequest::phrase($this->pick('Согласовать осмотр', 'Запросить ПТС')));
        $this->assertSame('прислать ПТС, справку о закрытии лизинга и согласовать осмотр', ExtraRequest::phrase($this->pick('Запросить ПТС', 'Справка о закрытии лизинга', 'Согласовать осмотр')));
        $this->assertSame('снять залог и ограничения на регистрационные действия', ExtraRequest::phrase($this->pick('Снять залог', 'Ограничения рег. действий')));
        $this->assertSame('записать на подписание документов в Москве', ExtraRequest::phrase($this->pick('Подписание в городе'), 'в Москве'));
    }

    public function test_request_letter_is_not_the_stage_letter(): void
    {
        Notification::fake();
        Queue::fake();
        config(['xcar.mercure.hub' => '']);
        $admin = User::create(['name' => 'Андрей', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $manager = User::create(['name' => 'Роман', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Альфа тест']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Alfa);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'claim_ref' => '5491/046/00861/26', 'floor_price' => 900000, 'asking_price' => 1000000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        app(AcceptBid::class)(app(PlaceBid::class)($offer, $manager, 1000000), $admin, scheme: DealScheme::Prime);
        $position = fn () => Position::where('offer_id', $offer->id)->where('track', Track::Sale)->with('stage')->firstOrFail();
        $this->assertSame('unsent', $position()->letterStatus());

        DB::table('mail_accounts')->insert(['slug' => 'deal', 'title' => 'deal', 'email' => 'deal@xcar.ru', 'scope' => 'offers', 'is_active' => true,
            'imap_host' => 'x', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'x', 'smtp_username' => 'x', 'smtp_password' => 'x']);
        $picked = $this->pick('Запросить ПТС', 'Согласовать осмотр');
        $this->actingAs($admin)->post('http://crm.xcar.localhost/work/mail', ['account' => 'deal', 'to' => 'implementation@alfastrah.ru', 'subject' => $offer->claim_ref,
            'body' => '<div>Просьба прислать ПТС и согласовать осмотр перед оплатой.</div>', 'offer' => $offer->id, 'extra' => $picked->pluck('id')->all()])->assertRedirect();

        $letter = Message::where('direction', 'out')->latest('id')->firstOrFail();
        $this->assertSame('прислать ПТС и согласовать осмотр', $letter->requests['text']);
        $this->assertSame($offer->id, Thread::find($letter->thread_id)->offer_id);
        $this->assertTrue($offer->events()->where('type', OfferEventType::ExtraRequest)->exists());
        // Менеджеру сделки — в чат: он знает, что ПТС и осмотр уже запросили.
        $this->assertTrue(\App\Chats\Message::where('text', 'Попросили поставщика прислать ПТС и согласовать осмотр')->exists());

        // Запрос ушёл — письмо этапа по-прежнему не отправлено.
        MessageSent::dispatch($letter);
        $this->assertSame('unsent', $position()->letterStatus());

        // Обычное письмо поставщику — оно и есть письмо этапа.
        $this->actingAs($admin)->post('http://crm.xcar.localhost/work/mail', ['account' => 'deal', 'to' => 'implementation@alfastrah.ru', 'subject' => $offer->claim_ref,
            'body' => '<div>Подтверждаем актуальность</div>', 'offer' => $offer->id])->assertRedirect();
        $plain = Message::where('direction', 'out')->latest('id')->firstOrFail();
        $this->assertNull($plain->requests);
        MessageSent::dispatch($plain);
        $this->assertSame('sent', $position()->letterStatus());

        // Блок над письмами: последний запрос, ответа пока нет.
        $last = ExtraRequest::last(Message::whereIn('id', [$letter->id, $plain->id])->get());
        $this->assertSame('прислать ПТС и согласовать осмотр', $last['text']);
        $this->assertNull($last['answered']);
    }
}
