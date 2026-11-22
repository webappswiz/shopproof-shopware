// Which refund line the deadline clock shows, as a snippet key under shopproof.withdrawal.clock,
// its values, the count for plural forms (or undefined) and its tone (ok, warn, bad, none).
// Pure, so it is tested without the admin (tests/admin/refund-line.test.mjs).
// Art. 13(3) Directive 2011/83/EU: for goods the shop may wait until they are back or the
// customer shows proof of sending, so "waiting" replaces "overdue" while the goods are out.

/**
 * @param {{ refund: string, window: string, refunded?: string, refundDue: string, daysLeft: number,
 *   goods?: boolean, goodsBack?: string }} clock
 * @param {(day: string) => string} day formats a Y-m-d day
 * @returns {{ key: string, values: Record<string, unknown>, count: number | undefined, tone: string }}
 */
export function refundLine(clock, day) {
    const back = Boolean(clock.goodsBack) && clock.goodsBack === clock.refundDue;
    switch (clock.refund) {
        case 'paid':
            return { key: 'paid', values: { date: day(clock.refunded || '') }, count: undefined, tone: 'ok' };
        case 'none':
            return { key: clock.window === 'late' ? 'none' : 'noneRight', values: {}, count: undefined, tone: 'none' };
        case 'waiting':
            return { key: 'waiting', values: {}, count: undefined, tone: 'warn' };
        case 'today':
            return { key: back ? 'todayBack' : 'today', values: { date: day(clock.refundDue) }, count: undefined, tone: 'bad' };
        case 'overdue':
            return {
                key: back ? 'overdueBack' : 'overdue',
                values: { days: -clock.daysLeft, date: day(clock.refundDue) },
                count: -clock.daysLeft,
                tone: 'bad',
            };
        default:
            return {
                key: clock.goods && !clock.goodsBack ? 'dueGoods' : 'due',
                values: { days: clock.daysLeft, date: day(clock.refundDue) },
                count: clock.daysLeft,
                tone: clock.daysLeft <= 3 ? 'warn' : 'ok',
            };
    }
}

/**
 * Whether the withdrawals list shows "Refund on the order": the withdrawal is linked to an order,
 * not yet marked Refund sent, and a refund is still owed (not paid, not "none").
 * @param {{ orderId?: string, status?: string, clock?: { refund: string } | null }} item
 */
export function refundLink(item) {
    if (!item || !item.orderId || item.status === 'refund_sent') return false;
    const refund = item.clock ? item.clock.refund : '';
    return refund !== 'paid' && refund !== 'none';
}
