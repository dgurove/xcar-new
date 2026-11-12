// Вознаграждение менеджера за гаражную Совкомбанка — то же правило, что `Deal::commissionFrom` на сервере (оно и
// записывается при «Продаю»): навар — разница минус расходы менеджера, наша часть — доля навара вверх до тысячи, ему —
// остальная разница. Доля и расходы приходят значениями контроллера, своей копии чисел здесь нет.
export function garageReward(margin, spent, share) {
    const navar = margin - spent;
    const ours = navar > 0 ? Math.ceil((navar * share) / 100 / 1000) * 1000 : 0;

    return Math.max(0, margin - ours);
}
