import type { AddressSnapshot, Money } from '../../shared/commerce';

/**
 * The small parts every order screen repeats: a destination, a totals
 * block, a money row.
 *
 * Shared so the customer's receipt, the seller's packing view and the
 * admin's inspection screen cannot drift into three different renderings
 * of the same three numbers.
 */

/**
 * An address exactly as it was recorded.
 *
 * `state` is optional throughout — most of the world has none, and a line
 * that printed an empty one would look like missing data rather than a
 * country that does not use the field.
 */
export function AddressBlock({ address, title }: { address: AddressSnapshot; title?: string }) {
    const region = [address.city, address.state, address.postcode].filter(Boolean).join(', ');

    return (
        <div>
            {title ? (
                <h3 className="mb-2 text-[11px] font-semibold tracking-[0.08em] text-[var(--vc-neutral-600)] uppercase">
                    {title}
                </h3>
            ) : null}
            <address className="text-[14px] not-italic">
                <span className="block font-semibold">{address.name}</span>
                <span className="block">{address.line1}</span>
                {address.line2 ? <span className="block">{address.line2}</span> : null}
                <span className="block">{region}</span>
                <span className="block">{address.country}</span>
                {address.phone ? <span className="block">{address.phone}</span> : null}
            </address>
        </div>
    );
}

export function MoneyRow({
    label,
    value,
    note,
    strong = false,
}: {
    label: string;
    value: string;
    note?: string;
    strong?: boolean;
}) {
    return (
        <div
            className={[
                'flex items-baseline justify-between gap-6 py-[6px]',
                strong ? 'border-t-2 border-[var(--vc-text)] pt-3 text-[18px] font-semibold' : '',
            ].join(' ')}
        >
            <span>
                {label}
                {note ? (
                    <span className="block text-[12px] font-normal text-[var(--vc-neutral-600)]">
                        {note}
                    </span>
                ) : null}
            </span>
            <span className="vc-tabular whitespace-nowrap">{value}</span>
        </div>
    );
}

/**
 * The totals block.
 *
 * Every figure is the server's. Nothing here adds anything up — if these
 * four numbers disagree, the bug is in the order, not in the page, and a
 * React-side sum would hide it.
 */
export function OrderTotals({
    itemsTotal,
    shippingTotal,
    taxTotal,
    grandTotal,
    shippingNote,
    taxNote,
}: {
    itemsTotal: Money;
    shippingTotal: Money;
    taxTotal: Money;
    grandTotal: Money;
    shippingNote?: string;
    taxNote?: string;
}) {
    return (
        <div className="flex flex-col">
            <MoneyRow label="Items" value={itemsTotal.formatted} />
            <MoneyRow
                label={shippingTotal.minor === 0 ? 'Delivery' : 'Delivery'}
                value={shippingTotal.minor === 0 ? 'Included' : shippingTotal.formatted}
                {...(shippingNote ? { note: shippingNote } : {})}
            />
            {/*
             * Tax is shown with its policy attached. M4 runs no tax
             * engine, and a bare "$0.00" here would read as a calculated
             * figure the platform cannot stand behind.
             */}
            <MoneyRow
                label="Tax"
                value={taxTotal.minor === 0 ? 'Not calculated' : taxTotal.formatted}
                {...(taxNote ? { note: taxNote } : {})}
            />
            <MoneyRow label="Total" value={grandTotal.formatted} strong />
        </div>
    );
}
