// Sortable нужен трём экранам CRM — грузится при первом перетаскиваемом списке,
// а не в общем бандле витрины и стоянки.
let module;
export const loadSortable = () => (module ??= import('sortablejs').then((m) => m.default));
