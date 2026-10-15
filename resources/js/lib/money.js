// Числа и рубли на экране из скриптов — одно правило (16.10.2026: двенадцать копий в одиннадцати контроллерах): разряды
// неразрывными пробелами, как Support\Money::nums на сервере; набранная сумма — целые рубли, копейки отбрасываются, как
// Money::rubles («1.150.000» — разряды точками).
const NBSP = ' ';

export const nums = (n, decimals = 0) => (n === null || n === undefined || n === '' ? ''
    : Number(n).toLocaleString('ru-RU', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).replace(/\s/g, NBSP));

export const rub = (n, decimals = 0) => (n === null || n === undefined || n === '' ? '' : `${nums(n, decimals)}${NBSP}₽`);

export function rubles(text) {
    const whole = String(text ?? '').split(/,|\.(?!\d{3}(?:\D|$))/)[0].replace(/\D/g, '');
    return whole === '' ? null : Number(whole);
}
