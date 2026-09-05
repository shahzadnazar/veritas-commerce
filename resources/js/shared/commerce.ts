/**
 * The shapes the commerce screens are given.
 *
 * Written by hand against the PHP read models rather than generated,
 * because these are the contract between two languages and a contract
 * nobody reads is not one. Every money field arrives twice: `formatted`
 * for the page and `minor` for anything that has to compare.
 *
 * Nothing here is recalculated in React. If a total is wrong, it is wrong
 * on the server, which is where it can be tested.
 */

export interface Money {
    formatted: string;
    minor: number;
}

/** Codes the domain raises. The UI branches on these; it never prints them. */
export type CartIssueCode =
    | 'PRICE_CHANGED'
    | 'OUT_OF_STOCK'
    | 'QUANTITY_REDUCED'
    | 'OFFER_UNAVAILABLE'
    | 'SELLER_UNAVAILABLE'
    | 'PRODUCT_UNAVAILABLE'
    | 'VARIANT_UNAVAILABLE'
    | 'CURRENCY_MISMATCH';

export interface CartIssue {
    code: CartIssueCode;
    label: string;
    blocking: boolean;
    lineIdentity?: string;
    available?: number;
    previousMinor?: number;
    currentMinor?: number;
}

export interface CartLine {
    lineIdentity: string;
    offerPublicId: string;
    productTitle: string;
    productSlug: string;
    brand: string | null;
    variantName: string | null;
    storeName: string;
    storeSlug: string;
    sellerSku: string;
    quantity: number;
    unitPrice: string;
    unitPriceMinor: number;
    lineTotal: string;
    lineTotalMinor: number;
    available: number;
    maxQuantity: number;
    isBuyable: boolean;
    imageUrl: string | null;
    issues: CartIssue[];
}

export interface CartSellerGroup {
    sellerAccountId: number;
    storeName: string;
    storeSlug: string;
    lines: CartLine[];
    subtotal: string;
    subtotalMinor: number;
}

export interface CartView {
    groups: CartSellerGroup[];
    issues: CartIssue[];
    subtotal: string;
    subtotalMinor: number;
    itemCount: number;
    quantityCount: number;
    currency: string;
    hasBlockingIssues: boolean;
}

export interface CheckoutQuote {
    cart: CartView;
    itemsTotal: string;
    itemsTotalMinor: number;
    shippingTotal: string;
    shippingTotalMinor: number;
    taxTotal: string;
    taxTotalMinor: number;
    grandTotal: string;
    grandTotalMinor: number;
    currency: string;
    buyable: boolean;
    issues: CartIssue[];
}

/** A cart issue as the customer reads it — written by the server, not here. */
export interface IssueMessage {
    code: CartIssueCode;
    blocking: boolean;
    title: string;
    detail: string;
}

export interface ShippingPolicy {
    perSellerOrderMinor: number;
    label: string;
    note: string;
    taxNote: string;
}

export interface SavedAddress {
    publicId: string;
    label: string | null;
    name: string;
    line1: string;
    line2: string | null;
    city: string;
    state: string | null;
    postcode: string;
    country: string;
    phone: string | null;
    isDefault: boolean;
}

export interface AddressSnapshot {
    name: string;
    line1: string;
    line2: string | null;
    city: string;
    state: string | null;
    postcode: string;
    country: string;
    phone: string | null;
}

export interface OrderItemView {
    publicId: string;
    productTitle: string;
    brand: string | null;
    storeName: string | null;
    productSlug: string | null;
    variantName: string | null;
    sellerSku: string;
    quantity: number;
    unitPrice: Money;
    lineTotal: Money;
    /** Present only when the reader holds the finance permission. */
    commissionRate?: string;
    commission?: Money;
    sellerEarning?: Money;
}

export interface SellerOrderView {
    reference: string;
    position: number;
    status: string;
    currency: string;
    storeName: string | null;
    itemsTotal: Money;
    shippingTotal: Money;
    orderTotal: Money;
    itemCount: number;
    quantity: number;
    items: OrderItemView[];
    commissionTotal?: Money;
    sellerEarningTotal?: Money;
}

export interface MarketplaceOrderView {
    reference: string;
    status: string;
    currency: string;
    placedAt: string | null;
    cancelledAt: string | null;
    paymentExpiresAt: string | null;
    email: string;
    itemsTotal: Money;
    shippingTotal: Money;
    taxTotal: Money;
    discountTotal: Money;
    grandTotal: Money;
    shippingAddress: AddressSnapshot;
    sellerOrders: SellerOrderView[];
}

export interface Paginated<Row> {
    data: Row[];
    currentPage: number;
    lastPage: number;
    total: number;
}

/**
 * Fulfilment, as the server computes it.
 *
 * These numbers are never recomputed in React. §64 puts the arithmetic in
 * one place on the server precisely so three screens cannot disagree about
 * one order, and a `remaining = ordered - shipped` written in a component
 * would be the fourth answer.
 */
export interface ItemFulfilmentView {
    orderItemId: number;
    publicId: string;
    title: string;
    variantName: string | null;
    sku: string;
    ordered: number;
    refunded: number;
    allocated: number;
    shipped: number;
    delivered: number;
    remainingToShip: number;
    fulfilable: number;
}

export interface ShipmentHistoryEntry {
    from: string | null;
    to: string;
    actorType: string;
    reason: string | null;
    carrierName: string | null;
    trackingNumber: string | null;
    at: string;
}

export interface ShipmentView {
    publicId: string;
    reference: string;
    status: string;
    carrierName: string | null;
    carrierCode: string | null;
    trackingNumber: string | null;
    trackingUrl: string | null;
    shippedAt: string | null;
    deliveredAt: string | null;
    createdAt: string | null;
    notes: string | null;
    canShip: boolean;
    canDeliver: boolean;
    items: { orderItemId: number; title: string; variantName: string | null; quantity: number }[];
    history?: ShipmentHistoryEntry[];
}

export interface FulfilmentIssueView {
    publicId: string;
    reason: string;
    note: string;
    reportedByType: string;
    reportedAt: string | null;
    resolvedAt: string | null;
    resolutionNote: string | null;
}

export interface SellerFulfilmentView {
    actionable: boolean;
    reason: string | null;
    canConfirm: boolean;
    canProcess: boolean;
    canPack: boolean;
    canManage: boolean;
    /** Phase 1 has no carrier integration: a person records delivery. */
    deliveryIsManual: boolean;
    remainingUnits: number;
    items: ItemFulfilmentView[];
    shipments: ShipmentView[];
    issues: FulfilmentIssueView[];
    carriers: { code: string; name: string }[];
}

/** One seller's part of a customer order, as the customer sees it. */
export interface CustomerTrackingShipment {
    reference: string;
    status: string;
    carrierName: string | null;
    trackingNumber: string | null;
    trackingUrl: string | null;
    shippedAt: string | null;
    deliveredAt: string | null;
    items: { title: string; variantName: string | null; quantity: number }[];
}

export interface CustomerTrackingGroup {
    reference: string;
    storeName: string;
    status: string;
    confirmedAt: string | null;
    shippedAt: string | null;
    deliveredAt: string | null;
    shipments: CustomerTrackingShipment[];
}

export interface OrderFulfilmentSummary {
    state: string;
    label: string;
    detail: string;
    sellerCount: number;
    deliveredCount: number;
}

export interface CustomerFulfilmentView {
    summary: OrderFulfilmentSummary;
    groups: CustomerTrackingGroup[];
}

/* ------------------------------------------------------------------ */
/* M7 — seller finance and payouts                                     */
/* ------------------------------------------------------------------ */

/**
 * A seller's money, as the backend computed it.
 *
 * Every figure arrives both as minor units and as a formatted string.
 * Nothing on this shape is recalculated in the browser: `withdrawable` is
 * NOT `available - reserved` worked out here, it is what
 * SellerFinancialPosition::withdrawableMinor() returned, which also caps
 * it at the seller's overall net position. Two implementations of that
 * rule would disagree on the day it matters.
 */
export interface SellerFinancialPositionView {
    currency: string;
    pendingMinor: number;
    clearingMinor: number;
    availableMinor: number;
    reservedMinor: number;
    paidOutMinor: number;
    netBalanceMinor: number;
    withdrawableMinor: number;
    pending: string;
    clearing: string;
    available: string;
    reserved: string;
    paidOut: string;
    netBalance: string;
    withdrawable: string;
    isNegative: boolean;
}

/** Whether a payout may be requested, decided on the server. */
export interface PayoutEligibilityView {
    canRequest: boolean;
    /** Machine-readable, for linking to a remedy. Never rendered. */
    reason: string | null;
    /** What the seller reads. Composed server-side. */
    message: string;
    withdrawableMinor: number;
    withdrawable: string;
    minimumMinor: number;
    minimum: string;
    currency: string;
    openPayoutReference: string | null;
}

export interface SellerLedgerRowView {
    id: string;
    occurredAt: string;
    type: string;
    typeLabel: string;
    status: string;
    statusLabel: string;
    description: string;
    amountMinor: number;
    creditMinor: number;
    debitMinor: number;
    credit: string | null;
    debit: string | null;
    balanceAfterMinor: number;
    balanceAfter: string;
    currency: string;
    availableAt: string | null;
    reference: string | null;
    referenceKind: string | null;
}

export interface SellerStatementView {
    rows: SellerLedgerRowView[];
    page: number;
    lastPage: number;
    total: number;
}

export interface PayoutSummaryView {
    id: string;
    reference: string;
    status: string;
    statusLabel: string;
    amountMinor: number;
    amount: string;
    currency: string;
    requestedAt: string;
    decidedAt: string | null;
    paidAt: string | null;
    destinationLabel: string;
    isOpen: boolean;
    canCancel: boolean;
}

/** A queue row, which adds the seller's funding context. */
export interface AdminPayoutSummaryView extends PayoutSummaryView {
    sellerName: string;
    sellerWithdrawable: string | null;
    sellerIsNegative: boolean;
}

export interface PayoutAllocationView {
    id: string;
    amountMinor: number;
    amount: string;
    currency: string;
    status: string;
    statusLabel: string;
    earnedAt: string;
    orderReference: string | null;
    settledAt: string | null;
    releasedAt: string | null;
}

export interface PayoutSettlementAttemptView {
    id: string;
    provider: string;
    method: string | null;
    reference: string | null;
    status: string;
    statusLabel: string;
    amountMinor: number;
    amount: string;
    initiatedAt: string;
    completedAt: string | null;
    failureCode: string | null;
    failureMessage: string | null;
}

export interface PayoutHistoryEntry {
    from: string | null;
    to: string;
    toLabel: string;
    actorType: string | null;
    actorLabel: string | null;
    reason: string | null;
    at: string;
}

/**
 * The destination behind a payout.
 *
 * Only sent to admins holding payouts.view_sensitive, and even then it
 * carries no credential — the platform holds none.
 */
export interface PayoutDestinationView {
    id: string;
    type: string;
    provider: string;
    providerReference: string | null;
    last4: string | null;
    country: string | null;
    currency: string;
    status: string;
    verifiedAt: string | null;
    changedAt: string | null;
}

export interface PayoutDetailView extends PayoutSummaryView {
    sellerName: string | null;
    destinationType: string | null;
    reviewedAt: string | null;
    approvedAt: string | null;
    cancelledAt: string | null;
    failedAt: string | null;
    decisionReason: string | null;
    settlementMethod: string | null;
    settlementReference: string | null;
    allocations: PayoutAllocationView[];
    settlementAttempts: PayoutSettlementAttemptView[];
    history: PayoutHistoryEntry[];
    ledgerDebit: {
        id: string;
        amountMinor: number;
        amount: string;
        postedAt: string;
    } | null;
    destination?: PayoutDestinationView | null;
}

/** The seller's own payout destination, as they chose it. */
export interface SellerDestinationView {
    id: string;
    label: string;
    type: string;
    typeLabel: string;
    currency: string;
    country: string | null;
    verifiedAt: string | null;
}

/**
 * The platform's finance figures.
 *
 * The names are the definitions in SummarisePlatformFinance and mean
 * exactly what it says there. "Revenue" is deliberately not among them.
 */
export interface PlatformFinanceSummaryView {
    currency: string;
    from: string | null;
    to: string | null;
    flows: {
        gmvMinor: number;
        gmv: string;
        refundsMinor: number;
        refunds: string;
        netSalesMinor: number;
        netSales: string;
        commissionMinor: number;
        commission: string;
        sellerEarningsMinor: number;
        sellerEarnings: string;
        payoutsPaidMinor: number;
        payoutsPaid: string;
    };
    balances: {
        pendingMinor: number;
        pending: string;
        clearingMinor: number;
        clearing: string;
        availableMinor: number;
        available: string;
        reservedMinor: number;
        reserved: string;
        openPayoutsMinor: number;
        openPayouts: string;
        liabilityMinor: number;
        liability: string;
    };
}

export interface NegativeSellerView {
    sellerAccountId: number;
    sellerName: string;
    netMinor: number;
    net: string;
    incomingMinor: number;
    incoming: string;
}
