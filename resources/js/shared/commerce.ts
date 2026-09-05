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
