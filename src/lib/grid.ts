/**
 * Column count for a card grid that never leaves a lone card on the last row (desktop breakpoint).
 * Up to four cards sit on one row; above that the widest count whose last row is empty or holds at
 * least two cards and half a row wins (5 → 3+2, 7 → 4+3, 11 → 3+3+3+2).
 */
export function columnsFor(n: number, max: 2 | 3 | 4 = 3): 2 | 3 | 4 {
  if (n <= 4) return Math.max(2, Math.min(n, 4)) as 2 | 3 | 4;
  for (let c = max; c >= 2; c--) {
    const rest = n % c;
    if (rest === 0 || (rest > 1 && rest >= Math.ceil(c / 2))) return c as 2 | 3 | 4;
  }
  return 2;
}
