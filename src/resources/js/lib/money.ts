/** Dollars for a budget or a price: three decimals below a dollar, two above. */
export const usd = (n: number) => `$${n.toFixed(n < 1 ? 3 : 2)}`;

/** Dollars to the cent, for a plan's list price. */
export const money = (n: number) => `$${n.toFixed(2)}`;
