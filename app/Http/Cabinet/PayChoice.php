<?php

namespace App\Http\Cabinet;

use App\Billing\Acquiring\Actions\CreatePayLink;
use App\Billing\Acquiring\Gateway;
use App\Billing\Acquiring\PayerKind;
use App\Billing\Acquiring\PayLink;
use App\Billing\Actions\ClaimPayment;
use App\Billing\Invoice;
use App\Billing\PaymentSource;
use App\Support\Money;
use App\Users\Role;
use App\Users\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Шторка «Оплатить» — одна обработка на кабинет и гараж: ссылкой (кто платит и сумма), по счёту
 * («я оплатил», платёжка по желанию) или наличными. Пустая сумма — весь остаток к оплате.
 * Возвращает тост и новую ссылку (её шторка откроется сама); счёт уже проверен вызывающим на «свой».
 */
final class PayChoice
{
    public function __construct(private CreatePayLink $link, private ClaimPayment $claim) {}

    /**
     * Покупатель менеджера по ФИО и почте (телефон ссылке не нужен — чек приходит только на почту): свой с этой почтой — он
     * же, чужой — ошибка, иначе новый без пароля.
     */
    public static function newBuyer(User $me, string $name, ?string $email): User
    {
        $email = $email ? mb_strtolower(trim($email)) : null;
        if ($email && ($known = User::whereRaw('lower(email) = ?', [$email])->first())) {
            if ($known->manager_id === $me->id) {
                return $known;
            }
            throw ValidationException::withMessages(['email' => 'С этой почтой уже есть человек в xcar']);
        }
        $name = trim($name);
        [$last, $first] = array_pad(preg_split('/\s+/u', $name, 2) ?: [], 2, null);

        return User::create(['name' => $name, 'last_name' => $last, 'first_name' => $first, 'email' => $email,
            'roles' => [Role::Buyer], 'manager_id' => $me->id, 'approved_at' => now(), 'approved_by' => $me->id, 'access' => []]);
    }

    /** @return array{string, ?PayLink} */
    public function __invoke(Request $request, Invoice $invoice, User $me): array
    {
        $request->merge(['amount' => $request->filled('amount') ? Money::parse($request->input('amount')) : null]);
        // Покупатель в шторке — строкой списка: радио «кто платит» несёт его id вместо слова.
        if (ctype_digit((string) $request->input('payer'))) {
            $request->merge(['payer_user_id' => $request->input('payer'), 'payer' => 'buyer']);
        }
        $data = $request->validate([
            'way' => ['required', Rule::in(['link', 'transfer', 'cash'])],
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'payer' => ['exclude_unless:way,link', 'required', Rule::in(['self', 'buyer', 'other'])],
            'payer_user_id' => ['exclude_unless:payer,buyer', 'required', Rule::exists('users', 'id')->where('manager_id', $me->id)],
            'name' => ['exclude_unless:payer,other', 'required', 'string', 'max:160'],
            'email' => ['exclude_unless:way,link', 'nullable', 'email', 'max:120'],
            'paid_at' => ['exclude_if:way,link', 'nullable', 'date', 'before_or_equal:today'],
            'ref' => ['exclude_unless:way,transfer', 'nullable', 'string', 'max:60'],
            'slip' => ['exclude_unless:way,transfer', 'nullable', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,heic'],
        ], [
            'payer_user_id.required' => 'Выберите покупателя', 'name.required' => 'Укажите, кто платит',
            'email.email' => 'Проверьте почту',
        ]);
        $amount = isset($data['amount']) ? (float) $data['amount'] : ($data['way'] === 'link' ? PayLink::defaultAmount($invoice) : round($invoice->remaining() - $invoice->claimed(), 2));
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'По счёту платить нечего']);
        }

        if ($data['way'] === 'link') {
            abort_unless(app(Gateway::class)->configured(), 422, 'Оплата по ссылке не подключена');
            $kind = PayerKind::from($data['payer']);
            $buyer = $kind === PayerKind::Buyer ? User::find($data['payer_user_id']) : null;
            // Новый покупатель — сразу в «Покупатели» менеджера (05.10.2026): платит он, а не безымянный «другой человек».
            if ($kind === PayerKind::Other) {
                [$buyer, $kind] = [self::newBuyer($me, $data['name'], $data['email'] ?? null), PayerKind::Buyer];
            }
            $link = ($this->link)($invoice, $me, $amount, $kind, $buyer, $data['name'] ?? null, null, $data['email'] ?? null);

            return ['Ссылка готова', $link];
        }
        $at = isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : null;
        ($this->claim)($invoice, $me, $amount, $at, $request->file('slip'), $data['ref'] ?? null, $data['way'] === 'cash' ? PaymentSource::Cash : PaymentSource::Bank);

        return [$data['way'] === 'cash' ? 'Сообщили, что отдали наличными' : 'Сообщили об оплате, ждём поступления', null];
    }
}
