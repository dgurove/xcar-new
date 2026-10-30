// Дата тем же видом, что на сервере (`Support\Ago::at`, 30.10.2026, владелец: «07.10 07:40»): «07.10», в другом году —
// «07.10.2025». date — «2026-10-07».
export function day(date) {
    const [y, m, d] = date.split('-');
    return `${d}.${m}${Number(y) !== new Date().getFullYear() ? `.${y}` : ''}`;
}
