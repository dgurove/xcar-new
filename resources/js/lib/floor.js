// Закупочная от оценочной — то же правило, что `Offers\Pricing` на сервере: ступени (`Pricing::RULES`) приходят
// значением контроллера (`data-…-rules-value`), своей копии здесь нет. Ступень: [оценочная меньше, плюс ₽, плюс %].

export const ceilThousand = (n) => Math.ceil(Math.round(n * 100) / 100 / 1000) * 1000;

const step = (value, rules) => rules.find(([below]) => below === null || value < below) ?? rules.at(-1);

export function floorFrom(value, rules) {
    const [, add, pct] = step(value, rules);
    return ceilThousand(value + add + (value * pct) / 100);
}

export function markup(value, rules, rub) {
    const [, add, pct] = step(value, rules);
    return pct ? `+${pct}%` : `+${rub(add)}`;
}
