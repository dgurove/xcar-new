<?php

namespace App\Park\Actions;

use App\Http\Admin\MailController;
use App\Mail\Account;
use App\Mail\Composer;
use App\Mail\Direction;
use App\Mail\Message;
use App\Mail\Scope;
use App\Mail\Template;
use App\Mail\Thread;
use App\Park\Vehicle;
use App\Vendors\ContactRole;
use Illuminate\Support\Facades\Log;

/**
 * Письма парковки, которые уходят сами, без человека: с ящика стоянки (storage@), в переписку по ТС, видны в деле.
 * Вендору — ответом в ветку, где пришло его последнее письмо (или указанное), чтобы и его ответ лёг туда же.
 */
final class ParkLetter
{
    public function __construct(private Composer $composer) {}

    public static function account(): ?Account
    {
        $accounts = Account::where('scope', Scope::Park)->where('is_active', true)->orderBy('title')->get();

        return $accounts->firstWhere('slug', 'storage') ?? $accounts->first();
    }

    /** Ответ вендору по шаблону; некому или нечем писать — null (в лог). */
    public function toVendor(Vehicle $vehicle, string $templateKey, array $values = [], ?Message $parent = null): ?Message
    {
        $account = self::account();
        // Ветки писем покупателю (пропуск) — не переписка с вендором: ответ туда ушёл бы покупателю.
        $parent ??= Message::whereIn('thread_id', Thread::where('vehicle_id', $vehicle->id)->where('buyer', false)->select('id'))
            ->where('direction', Direction::In)->orderByDesc('date_at')->first();
        $vehicle->loadMissing('vendor.contacts');
        $to = $parent?->replyToAddress() ?? $vehicle->vendor?->email(ContactRole::Storage, ContactRole::Claims);
        if (! $account || ! $to) {
            Log::warning('Парковка: письмо вендору не ушло — нет ящика или адреса', ['vehicle' => $vehicle->id, 'template' => $templateKey]);

            return null;
        }
        $rendered = Template::park($templateKey)->render(MailController::vehiclePlaceholders($vehicle) + $values);
        $subject = $parent ? 'Re: '.preg_replace('/^(\s*(re|ответ|fwd?)\s*:\s*)+/iu', '', (string) $parent->subject) : $rendered['subject'];
        $message = $this->composer->create($account, [
            'to' => $to, 'subject' => $subject,
            'body' => Template::html($rendered['body']).$this->composer->fresh($account)['body'],
        ], $parent, null);
        if (! $message->thread->vehicle_id) {
            $message->thread->update(['vehicle_id' => $vehicle->id]);
        }

        return $message;
    }

    /** Письмо покупателю — своей веткой, привязанной к ТС: переписку со страховой не засоряет. */
    public function toBuyer(Vehicle $vehicle, string $to, string $subject, string $html, array $inline = []): ?Message
    {
        $account = self::account();
        if (! $account) {
            Log::warning('Парковка: письмо покупателю не ушло — нет ящика', ['vehicle' => $vehicle->id]);

            return null;
        }
        $message = $this->composer->create($account, ['to' => $to, 'subject' => $subject, 'body' => $html, 'inline' => $inline], null, null);
        $message->thread->update(['vehicle_id' => $vehicle->id, 'buyer' => true]);

        return $message;
    }
}
