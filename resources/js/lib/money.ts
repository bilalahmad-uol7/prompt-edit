/**
 * Money arrives from the server as exact decimal strings ("0.467343750").
 * We never turn it into a float: formatting is done on integer nanodollars with BigInt.
 */
const SCALE = 9;

export function toNanos(amount: string): bigint {
    const negative = amount.trim().startsWith('-');
    const [whole, fraction = ''] = amount.trim().replace('-', '').split('.');
    const nanos =
        BigInt(whole || '0') * 10n ** BigInt(SCALE) +
        BigInt((fraction + '0'.repeat(SCALE)).slice(0, SCALE));

    return negative ? -nanos : nanos;
}

/**
 * "$0.4673" — rounded half up to the given number of decimals.
 */
export function formatUsd(
    amount: string | null | undefined,
    decimals = 4,
): string {
    if (amount === null || amount === undefined) {
        return '—';
    }

    const nanos = toNanos(amount);
    const negative = nanos < 0n;
    const absolute = negative ? -nanos : nanos;
    const step = 10n ** BigInt(SCALE - decimals);
    const rounded = (absolute + step / 2n) / step;
    const unit = 10n ** BigInt(decimals);

    const whole = (rounded / unit).toLocaleString('en-US');
    const fraction = (rounded % unit).toString().padStart(decimals, '0');

    return `${negative ? '-' : ''}$${whole}${decimals > 0 ? `.${fraction}` : ''}`;
}

/**
 * The exact amount with all nine decimals, for tooltips: "$0.467343750".
 */
export function exactUsd(amount: string | null | undefined): string {
    if (amount === null || amount === undefined) {
        return '';
    }

    return amount.startsWith('-') ? `-$${amount.slice(1)}` : `$${amount}`;
}

export function isNegative(amount: string): boolean {
    return toNanos(amount) < 0n;
}
