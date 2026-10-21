// Форма слова по числу — одна на все контроллеры: plural(5, ['номер', 'номера', 'номеров']) → «номеров».
export function plural(n, [one, few, many]) {
    const a = Math.abs(n) % 10, b = Math.abs(n) % 100;
    return a === 1 && b !== 11 ? one : a >= 2 && a <= 4 && (b < 12 || b > 14) ? few : many;
}
