import { StatusBadge } from '../primitives/StatusBadge';

export interface StockLevel {
    onHand: number;
    reserved: number;
    available: number;
    threshold: number;
    state: string;
    stateLabel: string;
}

/**
 * The three numbers, in the order they answer questions.
 *
 * Available first because it is the one a seller acts on; on hand and
 * reserved explain it. Nothing here computes anything — the server sends
 * all four values and the badge reads the same StockState the domain does,
 * so a card in the storefront and a row in the portal cannot disagree.
 */
export function StockCell({ level }: { level: StockLevel }) {
    return (
        <div className="flex flex-col gap-1">
            <div className="flex items-center gap-2">
                <span className="vc-tabular text-[15px] font-semibold">{level.available}</span>
                <StatusBadge domain="stock" value={level.state} />
            </div>
            <span className="vc-tabular text-[12px] text-[var(--vc-neutral-600)]">
                {level.onHand} on hand
                {level.reserved > 0 ? ` · ${level.reserved} reserved` : ''}
            </span>
        </div>
    );
}
