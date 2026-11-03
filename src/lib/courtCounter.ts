// "Courts delivered" figure on the home page. Grows by 1 or 2 every 2 to 4 days from the anchor below.
// Each step comes from a fixed seed, so every visitor sees the same number on the same day and it never
// goes down, without a backend or a rebuild. To re-sync with the real total, set BASE and ANCHOR_DAY.
const BASE = 9594;
const ANCHOR_DAY = Date.UTC(2026, 10, 3) / 86_400_000;

const mix = (n: number) => {
  let h = Math.imul(n ^ 0x9e3779b9, 0x85ebca6b);
  h ^= h >>> 13;
  h = Math.imul(h, 0xc2b2ae35);
  return (h ^ (h >>> 16)) >>> 0;
};

export function courtsDelivered(now = Date.now()): number {
  const today = Math.floor(now / 86_400_000);
  let day = ANCHOR_DAY;
  let total = BASE;
  for (let step = 0; ; step++) {
    const r = mix(step);
    day += 2 + (r % 3);
    if (day > today) return total;
    total += 1 + ((r >>> 8) & 1);
  }
}

export const groupThousands = (n: number, sep: string) => String(n).replace(/\B(?=(\d{3})+(?!\d))/g, sep);
