import { Head, Link, createInertiaApp, router, useForm, usePage } from "@inertiajs/react";
import { Fragment, jsx, jsxs } from "react/jsx-runtime";
import { useCallback, useEffect, useId, useRef, useState } from "react";
import { Elements, PaymentElement, useElements, useStripe } from "@stripe/react-stripe-js";
import { loadStripe } from "@stripe/stripe-js";
import createServer from "@inertiajs/react/server";
import ReactDOMServer from "react-dom/server";
//#region \0rolldown/runtime.js
var __defProp = Object.defineProperty;
var __exportAll = (all, no_symbols) => {
	let target = {};
	for (var name in all) __defProp(target, name, {
		get: all[name],
		enumerable: true
	});
	if (!no_symbols) __defProp(target, Symbol.toStringTag, { value: "Module" });
	return target;
};
//#endregion
//#region resources/js/design-system/layout/Wordmark.tsx
/**
* VERITAS COMMERCE — the second word in the accent, matching the
* construction the prototype uses for its wordmark. Archivo 800.
*/
function Wordmark({ name, size = 18 }) {
	const [first, ...rest] = name.split(" ");
	return /* @__PURE__ */ jsxs("span", {
		style: { fontSize: size },
		className: "font-[family-name:var(--vc-font-heading)] font-extrabold tracking-[0.02em] whitespace-nowrap",
		children: [first, rest.length > 0 ? /* @__PURE__ */ jsxs("span", {
			className: "text-[var(--vc-accent)]",
			children: [" ", rest.join(" ")]
		}) : null]
	});
}
//#endregion
//#region resources/js/design-system/layout/StorefrontLayout.tsx
/**
* The customer surface: centred to 1280, 64px header, generous space.
*
* Runs at the comfortable density — 15px body, 24px grid gaps — which is
* the only intentional divergence from the operating portals.
*
* The title lives here rather than in the Blade shell. A page-supplied
* title alongside a shell default renders two <title> tags under SSR, and
* a crawler takes the first — so the shell has none, and this is the one
* place a storefront page gets one.
*/
function StorefrontLayout({ title, children }) {
	const { platform, cart } = usePage().props;
	const cartCount = cart?.count ?? 0;
	return /* @__PURE__ */ jsxs("div", {
		"data-density": "comfortable",
		className: "min-h-screen bg-[var(--vc-bg)]",
		children: [
			/* @__PURE__ */ jsx(Head, { title: title === void 0 ? platform.name : `${title} — ${platform.name}` }),
			/* @__PURE__ */ jsx("header", {
				className: "border-b-2 border-[var(--vc-text)]",
				children: /* @__PURE__ */ jsxs("div", {
					className: "mx-auto flex h-[var(--vc-header-storefront)] max-w-[var(--vc-container-storefront)] items-center gap-6 px-8",
					children: [/* @__PURE__ */ jsx(Link, {
						href: "/",
						"aria-label": `${platform.name} home`,
						children: /* @__PURE__ */ jsx(Wordmark, { name: platform.name })
					}), /* @__PURE__ */ jsxs("nav", {
						"aria-label": "Primary",
						className: "ml-auto flex items-center gap-5 text-[14px]",
						children: [
							/* @__PURE__ */ jsxs(Link, {
								href: "/sell",
								children: ["Sell on ", platform.name.split(" ")[0]]
							}),
							/* @__PURE__ */ jsx(Link, {
								href: "/account/orders",
								children: "Orders"
							}),
							/* @__PURE__ */ jsxs(Link, {
								href: "/cart",
								children: [
									"Cart",
									cartCount > 0 ? /* @__PURE__ */ jsxs("span", {
										className: "ml-1 vc-tabular",
										"aria-hidden": "true",
										children: [
											"(",
											cartCount,
											")"
										]
									}) : null,
									/* @__PURE__ */ jsx("span", {
										className: "sr-only",
										children: cartCount === 0 ? ", empty" : `, ${cartCount} ${cartCount === 1 ? "item" : "items"}`
									})
								]
							})
						]
					})]
				})
			}),
			/* @__PURE__ */ jsx("main", {
				className: "mx-auto max-w-[var(--vc-container-storefront)] px-8 py-14",
				children
			}),
			/* @__PURE__ */ jsx("footer", {
				className: "border-t-2 border-[var(--vc-text)]",
				children: /* @__PURE__ */ jsxs("div", {
					className: "mx-auto max-w-[var(--vc-container-storefront)] px-8 py-8 text-[13px] text-[var(--vc-neutral-700)]",
					children: [/* @__PURE__ */ jsx(Wordmark, {
						name: platform.name,
						size: 14
					}), /* @__PURE__ */ jsxs("p", {
						className: "mt-2",
						children: [
							"Prices in ",
							platform.currency,
							". Questions? ",
							platform.supportEmail
						]
					})]
				})
			})
		]
	});
}
//#endregion
//#region resources/js/design-system/patterns/States.tsx
/**
* Page states are specified per screen, not generically.
*
* Empty never dead-ends: it names the action that resolves it. Error states
* say what was NOT changed, because "something went wrong" leaves a person
* wondering whether they were charged.
*/
function EmptyState({ title, body, actions }) {
	return /* @__PURE__ */ jsxs("div", {
		className: "border-2 border-[var(--vc-divider)] px-6 py-12",
		children: [
			/* @__PURE__ */ jsx("h3", {
				className: "mb-2 text-[20px]",
				children: title
			}),
			/* @__PURE__ */ jsx("p", {
				className: "mb-5 max-w-[52ch] text-[var(--vc-neutral-700)]",
				children: body
			}),
			actions ? /* @__PURE__ */ jsx("div", {
				className: "flex flex-wrap gap-2",
				children: actions
			}) : null
		]
	});
}
function CardGridSkeleton({ count = 8 }) {
	return /* @__PURE__ */ jsx("div", {
		"aria-busy": "true",
		className: "grid gap-[var(--vc-grid-gap)] [grid-template-columns:repeat(auto-fill,minmax(220px,1fr))]",
		children: Array.from({ length: count }).map((_, index) => /* @__PURE__ */ jsxs("div", {
			className: "bg-[var(--vc-surface)]",
			children: [/* @__PURE__ */ jsx("div", { className: "aspect-square animate-pulse bg-[var(--vc-neutral-300)]" }), /* @__PURE__ */ jsxs("div", {
				className: "p-3",
				children: [/* @__PURE__ */ jsx("div", { className: "mb-2 h-[12px] w-1/2 bg-[var(--vc-neutral-300)]" }), /* @__PURE__ */ jsx("div", { className: "h-[14px] w-4/5 bg-[var(--vc-neutral-300)]" })]
			})]
		}, index))
	});
}
//#endregion
//#region resources/js/design-system/primitives/Button.tsx
/**
* Rules carried from the design system, not preferences:
*
* - Labels are flush left, including in a full-width button. Nothing is
*   centred.
* - Destructive never takes the solid accent fill at rest; it earns that
*   only on the confirm button inside its confirmation dialog.
* - Loading swaps the label to the present participle and HOLDS THE WIDTH,
*   so the layout does not jump.
* - Radius is 0. Everywhere. No exceptions.
*/
var VARIANTS = {
	primary: "bg-[var(--vc-accent)] text-white hover:bg-[var(--vc-accent-600)] active:bg-[var(--vc-accent-700)]",
	secondary: "bg-transparent text-[var(--vc-text)] border-2 border-[var(--vc-text)] hover:bg-[var(--vc-surface)]",
	ghost: "bg-transparent text-[var(--vc-text)] hover:bg-[var(--vc-surface)] underline underline-offset-4",
	destructive: "bg-transparent text-[var(--vc-accent-800)] border-2 border-[var(--vc-accent-800)] hover:bg-[var(--vc-accent-100)]"
};
function Button({ variant = "secondary", block = false, loading = false, loadingLabel, children, className = "", disabled, ...rest }) {
	return /* @__PURE__ */ jsx("button", {
		...rest,
		disabled: disabled ?? loading,
		"aria-busy": loading || void 0,
		className: [
			"inline-flex items-center justify-start gap-2 px-4 py-[10px]",
			"min-h-[44px] text-left text-[14px] font-semibold",
			"transition-colors disabled:cursor-not-allowed disabled:opacity-45",
			VARIANTS[variant],
			block ? "w-full" : "",
			className
		].join(" "),
		children: loading ? loadingLabel ?? children : children
	});
}
//#endregion
//#region resources/js/design-system/generated/statuses.ts
var STATUS_PRESENTATION = {
	"seller_application": {
		"draft": {
			"tone": "inactive",
			"label": "Draft"
		},
		"submitted": {
			"tone": "pending",
			"label": "Submitted"
		},
		"under_review": {
			"tone": "pending",
			"label": "Under review"
		},
		"changes_requested": {
			"tone": "pending",
			"label": "Changes requested"
		},
		"approved": {
			"tone": "neutral",
			"label": "Approved"
		},
		"rejected": {
			"tone": "critical",
			"label": "Rejected"
		}
	},
	"seller": {
		"pending": {
			"tone": "pending",
			"label": "Pending"
		},
		"approved": {
			"tone": "neutral",
			"label": "Approved"
		},
		"suspended": {
			"tone": "critical",
			"label": "Suspended"
		},
		"closed": {
			"tone": "inactive",
			"label": "Closed"
		}
	},
	"seller_invitation": {
		"pending": {
			"tone": "pending",
			"label": "Pending"
		},
		"accepted": {
			"tone": "neutral",
			"label": "Accepted"
		},
		"revoked": {
			"tone": "critical",
			"label": "Revoked"
		},
		"expired": {
			"tone": "inactive",
			"label": "Expired"
		}
	},
	"product": {
		"draft": {
			"tone": "inactive",
			"label": "Draft"
		},
		"pending_review": {
			"tone": "pending",
			"label": "Pending review"
		},
		"changes_requested": {
			"tone": "pending",
			"label": "Changes requested"
		},
		"approved": {
			"tone": "neutral",
			"label": "Approved"
		},
		"published": {
			"tone": "neutral",
			"label": "Published"
		},
		"rejected": {
			"tone": "critical",
			"label": "Rejected"
		},
		"suspended": {
			"tone": "critical",
			"label": "Suspended"
		},
		"archived": {
			"tone": "inactive",
			"label": "Archived"
		}
	},
	"cart": {
		"active": {
			"tone": "pending",
			"label": "Active"
		},
		"converted": {
			"tone": "neutral",
			"label": "Converted"
		},
		"merged": {
			"tone": "inactive",
			"label": "Merged"
		},
		"abandoned": {
			"tone": "inactive",
			"label": "Abandoned"
		}
	},
	"checkout": {
		"reserved": {
			"tone": "pending",
			"label": "Awaiting payment"
		},
		"completed": {
			"tone": "neutral",
			"label": "Completed"
		},
		"failed": {
			"tone": "critical",
			"label": "Failed"
		},
		"expired": {
			"tone": "inactive",
			"label": "Expired"
		}
	},
	"offer": {
		"draft": {
			"tone": "inactive",
			"label": "Draft"
		},
		"pending_review": {
			"tone": "pending",
			"label": "Pending review"
		},
		"approved": {
			"tone": "neutral",
			"label": "Approved"
		},
		"published": {
			"tone": "neutral",
			"label": "Published"
		},
		"rejected": {
			"tone": "critical",
			"label": "Rejected"
		},
		"suspended": {
			"tone": "critical",
			"label": "Suspended"
		},
		"archived": {
			"tone": "inactive",
			"label": "Archived"
		}
	},
	"marketplace_order": {
		"pending_payment": {
			"tone": "pending",
			"label": "Pending payment"
		},
		"paid": {
			"tone": "neutral",
			"label": "Paid"
		},
		"processing": {
			"tone": "pending",
			"label": "Processing"
		},
		"partially_shipped": {
			"tone": "pending",
			"label": "Partially shipped"
		},
		"shipped": {
			"tone": "pending",
			"label": "Shipped"
		},
		"partially_delivered": {
			"tone": "pending",
			"label": "Partially delivered"
		},
		"delivered": {
			"tone": "neutral",
			"label": "Delivered"
		},
		"completed": {
			"tone": "neutral",
			"label": "Completed"
		},
		"cancelled": {
			"tone": "inactive",
			"label": "Cancelled"
		},
		"partially_refunded": {
			"tone": "critical",
			"label": "Partially refunded"
		},
		"refunded": {
			"tone": "critical",
			"label": "Refunded"
		}
	},
	"seller_order": {
		"pending_payment": {
			"tone": "pending",
			"label": "Pending payment"
		},
		"paid": {
			"tone": "neutral",
			"label": "Paid"
		},
		"confirmed": {
			"tone": "pending",
			"label": "Confirmed"
		},
		"processing": {
			"tone": "pending",
			"label": "Processing"
		},
		"packed": {
			"tone": "pending",
			"label": "Packed"
		},
		"shipped": {
			"tone": "pending",
			"label": "Shipped"
		},
		"delivered": {
			"tone": "neutral",
			"label": "Delivered"
		},
		"completed": {
			"tone": "neutral",
			"label": "Completed"
		},
		"cancelled": {
			"tone": "inactive",
			"label": "Cancelled"
		},
		"partially_refunded": {
			"tone": "critical",
			"label": "Partially refunded"
		},
		"refunded": {
			"tone": "critical",
			"label": "Refunded"
		},
		"disputed": {
			"tone": "critical",
			"label": "Disputed"
		}
	},
	"payment": {
		"pending": {
			"tone": "pending",
			"label": "Pending"
		},
		"authorized": {
			"tone": "neutral",
			"label": "Authorized"
		},
		"captured": {
			"tone": "neutral",
			"label": "Captured"
		},
		"failed": {
			"tone": "critical",
			"label": "Failed"
		},
		"refunded": {
			"tone": "critical",
			"label": "Refunded"
		},
		"partially_refunded": {
			"tone": "critical",
			"label": "Partially refunded"
		}
	},
	"payment_attempt": {
		"created": {
			"tone": "pending",
			"label": "Started"
		},
		"requires_payment_method": {
			"tone": "pending",
			"label": "Awaiting payment details"
		},
		"requires_action": {
			"tone": "pending",
			"label": "Awaiting confirmation"
		},
		"processing": {
			"tone": "pending",
			"label": "Processing"
		},
		"succeeded": {
			"tone": "neutral",
			"label": "Paid"
		},
		"failed": {
			"tone": "critical",
			"label": "Failed"
		},
		"cancelled": {
			"tone": "inactive",
			"label": "Cancelled"
		}
	},
	"refund": {
		"requested": {
			"tone": "pending",
			"label": "Requested"
		},
		"processing": {
			"tone": "pending",
			"label": "Processing"
		},
		"succeeded": {
			"tone": "critical",
			"label": "Refunded"
		},
		"failed": {
			"tone": "inactive",
			"label": "Refund failed"
		}
	},
	"provider_event": {
		"received": {
			"tone": "pending",
			"label": "Received"
		},
		"processed": {
			"tone": "neutral",
			"label": "Processed"
		},
		"ignored": {
			"tone": "inactive",
			"label": "Not applicable"
		},
		"failed": {
			"tone": "critical",
			"label": "Failed"
		}
	},
	"payout": {
		"requested": {
			"tone": "pending",
			"label": "Requested"
		},
		"under_review": {
			"tone": "pending",
			"label": "Under review"
		},
		"approved": {
			"tone": "neutral",
			"label": "Approved"
		},
		"rejected": {
			"tone": "critical",
			"label": "Rejected"
		},
		"processing": {
			"tone": "pending",
			"label": "Processing"
		},
		"paid": {
			"tone": "neutral",
			"label": "Paid"
		},
		"failed": {
			"tone": "critical",
			"label": "Failed"
		},
		"cancelled": {
			"tone": "inactive",
			"label": "Cancelled"
		}
	},
	"ledger_entry_status": {
		"pending": {
			"tone": "pending",
			"label": "Pending"
		},
		"clearing": {
			"tone": "pending",
			"label": "Clearing"
		},
		"available": {
			"tone": "neutral",
			"label": "Available"
		},
		"reserved_for_payout": {
			"tone": "pending",
			"label": "Reserved for payout"
		},
		"paid": {
			"tone": "neutral",
			"label": "Paid"
		},
		"reversed": {
			"tone": "critical",
			"label": "Reversed"
		}
	},
	"ledger_entry_type": {
		"sale_earning": {
			"tone": "neutral",
			"label": "Sale earning"
		},
		"commission": {
			"tone": "inactive",
			"label": "Commission"
		},
		"refund_reversal": {
			"tone": "inactive",
			"label": "Refund reversal"
		},
		"adjustment": {
			"tone": "critical",
			"label": "Adjustment"
		},
		"payout_reservation": {
			"tone": "pending",
			"label": "Payout reservation"
		},
		"payout": {
			"tone": "inactive",
			"label": "Payout"
		},
		"reversal": {
			"tone": "neutral",
			"label": "Reversal"
		}
	},
	"inventory_movement_reason": {
		"opening_stock": {
			"tone": "neutral",
			"label": "Opening stock"
		},
		"restock_received": {
			"tone": "neutral",
			"label": "Restock received"
		},
		"count_correction": {
			"tone": "pending",
			"label": "Count correction"
		},
		"damaged": {
			"tone": "critical",
			"label": "Damaged"
		},
		"lost": {
			"tone": "critical",
			"label": "Lost"
		},
		"returned_to_supplier": {
			"tone": "critical",
			"label": "Returned to supplier"
		},
		"manual_edit": {
			"tone": "pending",
			"label": "Manual edit"
		},
		"other": {
			"tone": "pending",
			"label": "Other"
		},
		"admin_adjustment": {
			"tone": "critical",
			"label": "Platform adjustment"
		},
		"order_reservation": {
			"tone": "pending",
			"label": "Reserved for an order"
		},
		"reservation_release": {
			"tone": "neutral",
			"label": "Reservation released"
		},
		"reservation_expired": {
			"tone": "pending",
			"label": "Reservation expired"
		},
		"sale_completed": {
			"tone": "inactive",
			"label": "Sale completed"
		},
		"order_cancelled": {
			"tone": "neutral",
			"label": "Order cancelled"
		},
		"refund_restock": {
			"tone": "neutral",
			"label": "Refund restock"
		}
	},
	"inventory_reservation": {
		"held": {
			"tone": "pending",
			"label": "Held"
		},
		"consumed": {
			"tone": "neutral",
			"label": "Consumed"
		},
		"released": {
			"tone": "inactive",
			"label": "Released"
		},
		"expired": {
			"tone": "critical",
			"label": "Expired"
		}
	},
	"stock": {
		"in_stock": {
			"tone": "neutral",
			"label": "In stock"
		},
		"low_stock": {
			"tone": "pending",
			"label": "Low stock"
		},
		"out_of_stock": {
			"tone": "critical",
			"label": "Out of stock"
		}
	}
};
//#endregion
//#region resources/js/design-system/statusTone.ts
/**
* The single status → tone lookup for the whole product.
*
* Phase 6 of the design review found this mapping duplicated three times,
* once per application, and warned that the first status added after
* handoff would only reach one of them. There is now one source: the PHP
* enums, exported to `generated/statuses.ts` by `php artisan statuses:export`
* and verified in CI by StatusPresentationTest.
*
* Never write a per-screen lookup table. Never add a fifth tone — the
* system is mono, so status is carried by fill weight and label, not hue.
*/
function statusPresentation(domain, value) {
	const found = STATUS_PRESENTATION[domain]?.[value];
	if (found) return found;
	return {
		tone: "inactive",
		label: value
	};
}
//#endregion
//#region resources/js/design-system/primitives/StatusBadge.tsx
/**
* Four semantic fills, no hue.
*
* Critical is an accent tint with deep accent text (the base accent is
* chrome-only below 18px — it clears 3:1, not 4.5:1). Pending is a dashed
* outline with no fill. Neutral is ink on surface: in a mono system, done
* is quiet. Inactive drops to 45%.
*/
var TONE_CLASSES = {
	neutral: "bg-[var(--vc-surface)] text-[var(--vc-text)]",
	pending: "border border-dashed border-[var(--vc-neutral-400)] text-[var(--vc-neutral-700)]",
	critical: "bg-[var(--vc-accent-100)] text-[var(--vc-accent-800)]",
	inactive: "bg-[var(--vc-surface)] text-[var(--vc-text)] opacity-45"
};
function StatusBadge({ domain, value, className = "" }) {
	const { tone, label } = statusPresentation(domain, value);
	return /* @__PURE__ */ jsx("span", {
		"data-tone": tone,
		className: `inline-block px-2 py-[3px] text-[11px] font-semibold tracking-[0.04em] whitespace-nowrap ${TONE_CLASSES[tone]} ${className}`,
		children: label
	});
}
//#endregion
//#region resources/js/storefront/pages/Account/Orders/Index.tsx
var Index_exports$3 = /* @__PURE__ */ __exportAll({ default: () => OrdersIndex });
/**
* A customer's orders.
*
* Cards rather than a table, at every width. A purchase history read on a
* phone is the common case, and a financial table that scrolls sideways is
* not a mobile design — it is a desktop table with a scrollbar bolted on.
*/
function OrdersIndex() {
	const { orders } = usePage().props;
	return /* @__PURE__ */ jsxs(StorefrontLayout, {
		title: "Your orders",
		children: [
			/* @__PURE__ */ jsx("h1", {
				className: "mb-8 text-[42px]",
				children: "Your orders"
			}),
			orders.data.length === 0 ? /* @__PURE__ */ jsx(EmptyState, {
				title: "No orders yet",
				body: "When you buy something, it will appear here with everything you need to track it.",
				actions: /* @__PURE__ */ jsx(Link, {
					href: "/search",
					children: /* @__PURE__ */ jsx(Button, {
						variant: "primary",
						children: "Browse the marketplace"
					})
				})
			}) : /* @__PURE__ */ jsx("ul", {
				className: "border-t-2 border-[var(--vc-text)]",
				children: orders.data.map((order) => /* @__PURE__ */ jsxs("li", {
					className: "flex flex-col gap-3 border-b border-[var(--vc-divider)] py-5 sm:flex-row sm:items-center sm:justify-between",
					children: [/* @__PURE__ */ jsxs("div", { children: [/* @__PURE__ */ jsx("h2", {
						className: "text-[18px]",
						children: /* @__PURE__ */ jsx(Link, {
							href: `/account/orders/${order.reference}`,
							children: order.reference
						})
					}), /* @__PURE__ */ jsxs("p", {
						className: "text-[13px] text-[var(--vc-neutral-700)]",
						children: [
							order.placedAt ? /* @__PURE__ */ jsx("time", {
								dateTime: order.placedAt,
								children: new Date(order.placedAt).toLocaleDateString()
							}) : "Not yet placed",
							" · ",
							order.sellerOrderCount,
							" ",
							order.sellerOrderCount === 1 ? "seller" : "sellers"
						]
					})] }), /* @__PURE__ */ jsxs("div", {
						className: "flex items-center gap-4",
						children: [/* @__PURE__ */ jsx(StatusBadge, {
							domain: "marketplace_order",
							value: order.status
						}), /* @__PURE__ */ jsx("span", {
							className: "vc-tabular text-[16px] font-semibold",
							children: order.grandTotal
						})]
					})]
				}, order.reference))
			}),
			orders.lastPage > 1 ? /* @__PURE__ */ jsxs("nav", {
				"aria-label": "Pagination",
				className: "mt-8 flex gap-4 text-[14px]",
				children: [
					orders.currentPage > 1 ? /* @__PURE__ */ jsx(Link, {
						href: `/account/orders?page=${orders.currentPage - 1}`,
						children: "Previous"
					}) : null,
					/* @__PURE__ */ jsxs("span", {
						"aria-current": "page",
						children: [
							"Page ",
							orders.currentPage,
							" of ",
							orders.lastPage
						]
					}),
					orders.currentPage < orders.lastPage ? /* @__PURE__ */ jsx(Link, {
						href: `/account/orders?page=${orders.currentPage + 1}`,
						children: "Next"
					}) : null
				]
			}) : null
		]
	});
}
//#endregion
//#region resources/js/design-system/patterns/OrderPieces.tsx
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
function AddressBlock({ address, title }) {
	const region = [
		address.city,
		address.state,
		address.postcode
	].filter(Boolean).join(", ");
	return /* @__PURE__ */ jsxs("div", { children: [title ? /* @__PURE__ */ jsx("h3", {
		className: "mb-2 text-[11px] font-semibold tracking-[0.08em] text-[var(--vc-neutral-600)] uppercase",
		children: title
	}) : null, /* @__PURE__ */ jsxs("address", {
		className: "text-[14px] not-italic",
		children: [
			/* @__PURE__ */ jsx("span", {
				className: "block font-semibold",
				children: address.name
			}),
			/* @__PURE__ */ jsx("span", {
				className: "block",
				children: address.line1
			}),
			address.line2 ? /* @__PURE__ */ jsx("span", {
				className: "block",
				children: address.line2
			}) : null,
			/* @__PURE__ */ jsx("span", {
				className: "block",
				children: region
			}),
			/* @__PURE__ */ jsx("span", {
				className: "block",
				children: address.country
			}),
			address.phone ? /* @__PURE__ */ jsx("span", {
				className: "block",
				children: address.phone
			}) : null
		]
	})] });
}
function MoneyRow({ label, value, note, strong = false }) {
	return /* @__PURE__ */ jsxs("div", {
		className: ["flex items-baseline justify-between gap-6 py-[6px]", strong ? "border-t-2 border-[var(--vc-text)] pt-3 text-[18px] font-semibold" : ""].join(" "),
		children: [/* @__PURE__ */ jsxs("span", { children: [label, note ? /* @__PURE__ */ jsx("span", {
			className: "block text-[12px] font-normal text-[var(--vc-neutral-600)]",
			children: note
		}) : null] }), /* @__PURE__ */ jsx("span", {
			className: "vc-tabular whitespace-nowrap",
			children: value
		})]
	});
}
/**
* The totals block.
*
* Every figure is the server's. Nothing here adds anything up — if these
* four numbers disagree, the bug is in the order, not in the page, and a
* React-side sum would hide it.
*/
function OrderTotals({ itemsTotal, shippingTotal, taxTotal, grandTotal, shippingNote, taxNote }) {
	return /* @__PURE__ */ jsxs("div", {
		className: "flex flex-col",
		children: [
			/* @__PURE__ */ jsx(MoneyRow, {
				label: "Items",
				value: itemsTotal.formatted
			}),
			/* @__PURE__ */ jsx(MoneyRow, {
				label: shippingTotal.minor === 0 ? "Delivery" : "Delivery",
				value: shippingTotal.minor === 0 ? "Included" : shippingTotal.formatted,
				...shippingNote ? { note: shippingNote } : {}
			}),
			/* @__PURE__ */ jsx(MoneyRow, {
				label: "Tax",
				value: taxTotal.minor === 0 ? "Not calculated" : taxTotal.formatted,
				...taxNote ? { note: taxNote } : {}
			}),
			/* @__PURE__ */ jsx(MoneyRow, {
				label: "Total",
				value: grandTotal.formatted,
				strong: true
			})
		]
	});
}
//#endregion
//#region resources/js/storefront/pages/Account/Orders/Show.tsx
var Show_exports$3 = /* @__PURE__ */ __exportAll({ default: () => OrderShow });
/**
* One order, entirely from its own snapshots.
*
* Every title, price, store name and address on this page is the copy the
* order took when it was placed — not a lookup against the catalogue as it
* is today. A seller who renames their shop or reprices a listing next
* month must not be able to change what this receipt says, and the way to
* be certain of that is for the page to have no route to those tables.
*
* The parent/child shape is shown rather than flattened: a customer who
* bought from three sellers has three parcels arriving at three times, and
* a single merged list would mislead them about that.
*/
function OrderShow() {
	const { order, payment } = usePage().props;
	return /* @__PURE__ */ jsxs(StorefrontLayout, {
		title: `Order ${order.reference}`,
		children: [
			/* @__PURE__ */ jsx("p", {
				className: "mb-2 text-[13px]",
				children: /* @__PURE__ */ jsx(Link, {
					href: "/account/orders",
					className: "underline underline-offset-4",
					children: "All orders"
				})
			}),
			/* @__PURE__ */ jsxs("div", {
				className: "mb-8 flex flex-wrap items-baseline gap-x-4 gap-y-2",
				children: [/* @__PURE__ */ jsx("h1", {
					className: "text-[42px]",
					children: order.reference
				}), /* @__PURE__ */ jsx(StatusBadge, {
					domain: "marketplace_order",
					value: order.status
				})]
			}),
			/* @__PURE__ */ jsxs("p", {
				className: "mb-10 text-[14px] text-[var(--vc-neutral-700)]",
				children: [
					order.placedAt ? /* @__PURE__ */ jsxs(Fragment, { children: [
						"Placed",
						" ",
						/* @__PURE__ */ jsx("time", {
							dateTime: order.placedAt,
							children: new Date(order.placedAt).toLocaleString()
						})
					] }) : "Not yet placed",
					" · ",
					order.sellerOrders.length,
					" ",
					order.sellerOrders.length === 1 ? "seller" : "sellers"
				]
			}),
			/* @__PURE__ */ jsxs("div", {
				role: "status",
				className: "mb-10 border-2 border-[var(--vc-text)] px-5 py-4",
				children: [
					/* @__PURE__ */ jsx("p", {
						className: "text-[16px] font-semibold",
						children: payment.headline
					}),
					/* @__PURE__ */ jsx("p", {
						className: "mt-1 text-[14px] text-[var(--vc-neutral-700)]",
						children: payment.detail
					}),
					payment.canPay ? /* @__PURE__ */ jsx("p", {
						className: "mt-3 text-[13px]",
						children: /* @__PURE__ */ jsx(Link, {
							href: `/checkout/${order.reference}/payment`,
							className: "font-semibold underline underline-offset-4",
							children: payment.canRetry ? "Try paying again" : "Complete your payment"
						})
					}) : null
				]
			}),
			/* @__PURE__ */ jsxs("div", {
				className: "grid gap-14 lg:grid-cols-[1fr_320px]",
				children: [/* @__PURE__ */ jsxs("div", { children: [/* @__PURE__ */ jsx("h2", {
					className: "mb-4 text-[22px]",
					children: "Items"
				}), order.sellerOrders.map((sellerOrder) => /* @__PURE__ */ jsxs("section", {
					"aria-labelledby": `so-${sellerOrder.reference}`,
					className: "mb-10",
					children: [
						/* @__PURE__ */ jsxs("div", {
							className: "mb-2 flex flex-wrap items-baseline gap-x-3 gap-y-1",
							children: [
								/* @__PURE__ */ jsx("h3", {
									id: `so-${sellerOrder.reference}`,
									className: "text-[18px]",
									children: sellerOrder.storeName ?? "Seller"
								}),
								/* @__PURE__ */ jsx("span", {
									className: "vc-tabular text-[12px] text-[var(--vc-neutral-600)]",
									children: sellerOrder.reference
								}),
								/* @__PURE__ */ jsx(StatusBadge, {
									domain: "seller_order",
									value: sellerOrder.status
								})
							]
						}),
						/* @__PURE__ */ jsx("ul", {
							className: "border-t border-[var(--vc-divider)]",
							children: sellerOrder.items.map((item) => /* @__PURE__ */ jsxs("li", {
								className: "flex flex-col gap-1 border-b border-[var(--vc-divider)] py-3 text-[14px] sm:flex-row sm:justify-between sm:gap-4",
								children: [/* @__PURE__ */ jsxs("span", { children: [
									item.brand ? /* @__PURE__ */ jsx("span", {
										className: "block text-[11px] tracking-[0.06em] text-[var(--vc-neutral-600)] uppercase",
										children: item.brand
									}) : null,
									item.productSlug ? /* @__PURE__ */ jsx(Link, {
										href: `/products/${item.productSlug}`,
										children: item.productTitle
									}) : item.productTitle,
									item.variantName ? /* @__PURE__ */ jsx("span", {
										className: "block text-[13px] text-[var(--vc-neutral-700)]",
										children: item.variantName
									}) : null,
									/* @__PURE__ */ jsxs("span", {
										className: "block text-[12px] text-[var(--vc-neutral-600)]",
										children: [
											item.quantity,
											" × ",
											item.unitPrice.formatted
										]
									})
								] }), /* @__PURE__ */ jsx("span", {
									className: "vc-tabular whitespace-nowrap sm:text-right",
									children: item.lineTotal.formatted
								})]
							}, item.publicId))
						}),
						/* @__PURE__ */ jsxs("p", {
							className: "pt-2 text-right text-[13px] vc-tabular",
							children: ["Seller subtotal ", sellerOrder.itemsTotal.formatted]
						})
					]
				}, sellerOrder.reference))] }), /* @__PURE__ */ jsxs("aside", {
					className: "flex flex-col gap-8",
					children: [
						/* @__PURE__ */ jsxs("div", { children: [/* @__PURE__ */ jsx("h2", {
							className: "mb-3 text-[20px]",
							children: "Total"
						}), /* @__PURE__ */ jsx(OrderTotals, {
							itemsTotal: order.itemsTotal,
							shippingTotal: order.shippingTotal,
							taxTotal: order.taxTotal,
							grandTotal: order.grandTotal,
							taxNote: "Tax was not calculated for this order."
						})] }),
						/* @__PURE__ */ jsx(AddressBlock, {
							address: order.shippingAddress,
							title: "Delivered to"
						}),
						/* @__PURE__ */ jsxs("div", { children: [/* @__PURE__ */ jsx("h3", {
							className: "mb-2 text-[11px] font-semibold tracking-[0.08em] text-[var(--vc-neutral-600)] uppercase",
							children: "Payment"
						}), /* @__PURE__ */ jsx(StatusBadge, {
							domain: "marketplace_order",
							value: order.status
						})] })
					]
				})]
			})
		]
	});
}
//#endregion
//#region resources/js/design-system/primitives/Field.tsx
/**
* Validation is on blur, then live once a field has errored.
*
* An error is a 2px accent border AND a message beneath — never a tooltip,
* and never colour alone, which would leave the error invisible to anyone
* who cannot distinguish it.
*/
function Field({ label, error, hint, children }) {
	const id = useId();
	const errorId = `${id}-error`;
	const hintId = `${id}-hint`;
	return /* @__PURE__ */ jsxs("div", {
		className: "flex flex-col gap-[6px]",
		children: [
			/* @__PURE__ */ jsx("label", {
				htmlFor: id,
				className: "text-[12px] text-[var(--vc-neutral-700)]",
				children: label
			}),
			children({
				id,
				describedBy: error ? errorId : hint ? hintId : void 0,
				invalid: Boolean(error)
			}),
			error ? /* @__PURE__ */ jsx("p", {
				id: errorId,
				role: "alert",
				className: "text-[12px] text-[var(--vc-accent-800)]",
				children: error
			}) : hint ? /* @__PURE__ */ jsx("p", {
				id: hintId,
				className: "text-[12px] text-[var(--vc-neutral-600)]",
				children: hint
			}) : null
		]
	});
}
var CONTROL_BASE = "w-full bg-[var(--vc-surface)] px-3 py-[10px] text-[14px] text-[var(--vc-text)] border-2 min-h-[44px] disabled:opacity-45 disabled:cursor-not-allowed";
function borderFor(invalid) {
	return invalid ? "border-[var(--vc-accent)]" : "border-transparent focus:border-[var(--vc-neutral-500)]";
}
function Input({ invalid = false, className = "", ...rest }) {
	return /* @__PURE__ */ jsx("input", {
		...rest,
		"aria-invalid": invalid || void 0,
		className: `${CONTROL_BASE} ${borderFor(invalid)} ${className}`
	});
}
function Select({ invalid = false, className = "", children, ...rest }) {
	return /* @__PURE__ */ jsx("select", {
		...rest,
		"aria-invalid": invalid || void 0,
		className: `${CONTROL_BASE} ${borderFor(invalid)} ${className}`,
		children
	});
}
//#endregion
//#region resources/js/storefront/pages/Account/Profile.tsx
var Profile_exports = /* @__PURE__ */ __exportAll({ default: () => Profile });
function Profile() {
	const { profile, status } = usePage().props;
	const details = useForm({
		first_name: profile.firstName,
		last_name: profile.lastName,
		email: profile.email,
		phone: profile.phone ?? "",
		marketing_opt_in: profile.marketingOptIn
	});
	const password = useForm({
		current_password: "",
		password: "",
		password_confirmation: ""
	});
	return /* @__PURE__ */ jsxs(StorefrontLayout, {
		title: "Your account",
		children: [
			/* @__PURE__ */ jsx("h1", {
				className: "mb-8 text-[42px]",
				children: "Your account"
			}),
			status ? /* @__PURE__ */ jsx("p", {
				role: "status",
				className: "mb-8 border-2 border-[var(--vc-text)] px-4 py-3 text-[14px]",
				children: status
			}) : null,
			/* @__PURE__ */ jsxs("div", {
				className: "grid max-w-[880px] gap-14 md:grid-cols-2",
				children: [/* @__PURE__ */ jsxs("form", {
					className: "flex flex-col gap-4",
					onSubmit: (event) => {
						event.preventDefault();
						details.put("/account");
					},
					children: [
						/* @__PURE__ */ jsx("h2", {
							className: "text-[22px]",
							children: "Your details"
						}),
						/* @__PURE__ */ jsx(Field, {
							label: "First name",
							error: details.errors.first_name,
							children: ({ id, describedBy, invalid }) => /* @__PURE__ */ jsx(Input, {
								id,
								"aria-describedby": describedBy,
								invalid,
								value: details.data.first_name,
								onChange: (event) => details.setData("first_name", event.target.value)
							})
						}),
						/* @__PURE__ */ jsx(Field, {
							label: "Last name",
							error: details.errors.last_name,
							children: ({ id, describedBy, invalid }) => /* @__PURE__ */ jsx(Input, {
								id,
								"aria-describedby": describedBy,
								invalid,
								value: details.data.last_name,
								onChange: (event) => details.setData("last_name", event.target.value)
							})
						}),
						/* @__PURE__ */ jsx(Field, {
							label: "Email",
							error: details.errors.email,
							hint: profile.emailVerified ? "Changing this sends a fresh verification link to the new address." : "This address is not verified yet.",
							children: ({ id, describedBy, invalid }) => /* @__PURE__ */ jsx(Input, {
								id,
								type: "email",
								"aria-describedby": describedBy,
								invalid,
								value: details.data.email,
								onChange: (event) => details.setData("email", event.target.value)
							})
						}),
						/* @__PURE__ */ jsx(Field, {
							label: "Phone — used for delivery updates",
							error: details.errors.phone,
							children: ({ id, describedBy, invalid }) => /* @__PURE__ */ jsx(Input, {
								id,
								type: "tel",
								"aria-describedby": describedBy,
								invalid,
								value: details.data.phone,
								onChange: (event) => details.setData("phone", event.target.value)
							})
						}),
						/* @__PURE__ */ jsxs("label", {
							className: "flex items-start gap-2 text-[13px]",
							children: [/* @__PURE__ */ jsx("input", {
								type: "checkbox",
								className: "mt-1",
								checked: details.data.marketing_opt_in,
								onChange: (event) => details.setData("marketing_opt_in", event.target.checked)
							}), /* @__PURE__ */ jsx("span", { children: "New arrivals and seller news. Order emails are transactional and always sent." })]
						}),
						/* @__PURE__ */ jsx(Button, {
							type: "submit",
							variant: "primary",
							loading: details.processing,
							loadingLabel: "Saving…",
							children: "Save changes"
						})
					]
				}), /* @__PURE__ */ jsxs("form", {
					className: "flex flex-col gap-4",
					onSubmit: (event) => {
						event.preventDefault();
						password.put("/account/password", { onSuccess: () => password.reset() });
					},
					children: [
						/* @__PURE__ */ jsx("h2", {
							className: "text-[22px]",
							children: "Password"
						}),
						/* @__PURE__ */ jsx(Field, {
							label: "Current password",
							error: password.errors.current_password,
							children: ({ id, describedBy, invalid }) => /* @__PURE__ */ jsx(Input, {
								id,
								type: "password",
								autoComplete: "current-password",
								"aria-describedby": describedBy,
								invalid,
								value: password.data.current_password,
								onChange: (event) => password.setData("current_password", event.target.value)
							})
						}),
						/* @__PURE__ */ jsx(Field, {
							label: "New password",
							error: password.errors.password,
							children: ({ id, describedBy, invalid }) => /* @__PURE__ */ jsx(Input, {
								id,
								type: "password",
								autoComplete: "new-password",
								"aria-describedby": describedBy,
								invalid,
								value: password.data.password,
								onChange: (event) => password.setData("password", event.target.value)
							})
						}),
						/* @__PURE__ */ jsx(Field, {
							label: "Confirm new password",
							children: ({ id }) => /* @__PURE__ */ jsx(Input, {
								id,
								type: "password",
								autoComplete: "new-password",
								value: password.data.password_confirmation,
								onChange: (event) => password.setData("password_confirmation", event.target.value)
							})
						}),
						/* @__PURE__ */ jsx(Button, {
							type: "submit",
							variant: "secondary",
							loading: password.processing,
							loadingLabel: "Updating…",
							children: "Update password"
						})
					]
				})]
			})
		]
	});
}
//#endregion
//#region resources/js/design-system/patterns/AuthCard.tsx
/**
* The shell every credential screen sits in.
*
* One component so registration, sign-in, reset and verification cannot
* drift apart in spacing, heading scale or focus behaviour.
*/
function AuthCard({ title, lede, status, children, footer }) {
	return /* @__PURE__ */ jsxs("div", {
		className: "max-w-[420px]",
		children: [
			/* @__PURE__ */ jsx("h1", {
				className: "mb-3 text-[44px] leading-[1.05]",
				children: title
			}),
			/* @__PURE__ */ jsx("p", {
				className: "mb-7 text-[var(--vc-neutral-700)]",
				children: lede
			}),
			status ? /* @__PURE__ */ jsx("p", {
				role: "status",
				className: "mb-6 border-2 border-[var(--vc-text)] px-4 py-3 text-[14px]",
				children: status
			}) : null,
			children,
			footer ? /* @__PURE__ */ jsx("div", {
				className: "mt-6 text-[13px] text-[var(--vc-neutral-700)]",
				children: footer
			}) : null
		]
	});
}
//#endregion
//#region resources/js/storefront/pages/Auth/ForgotPassword.tsx
var ForgotPassword_exports = /* @__PURE__ */ __exportAll({ default: () => ForgotPassword });
function ForgotPassword() {
	const { status } = usePage().props;
	const form = useForm({ email: "" });
	return /* @__PURE__ */ jsx(StorefrontLayout, {
		title: "Reset your password",
		children: /* @__PURE__ */ jsx(AuthCard, {
			title: "Reset your password",
			lede: "Enter the email on your account and we'll send a link to set a new one.",
			status,
			footer: /* @__PURE__ */ jsxs(Fragment, { children: [
				"For security, the confirmation is the same whether or not an account exists.",
				" ",
				/* @__PURE__ */ jsx(Link, {
					href: "/login",
					className: "underline",
					children: "Back to sign in"
				})
			] }),
			children: /* @__PURE__ */ jsxs("form", {
				className: "flex flex-col gap-4",
				onSubmit: (event) => {
					event.preventDefault();
					form.post("/forgot-password");
				},
				children: [/* @__PURE__ */ jsx(Field, {
					label: "Email",
					error: form.errors.email,
					children: ({ id, describedBy, invalid }) => /* @__PURE__ */ jsx(Input, {
						id,
						type: "email",
						autoComplete: "username",
						"aria-describedby": describedBy,
						invalid,
						value: form.data.email,
						onChange: (event) => form.setData("email", event.target.value)
					})
				}), /* @__PURE__ */ jsx(Button, {
					type: "submit",
					variant: "primary",
					loading: form.processing,
					loadingLabel: "Sending…",
					children: "Send reset link"
				})]
			})
		})
	});
}
//#endregion
//#region resources/js/storefront/pages/Auth/Login.tsx
var Login_exports = /* @__PURE__ */ __exportAll({ default: () => Login });
function Login() {
	const { status } = usePage().props;
	const form = useForm({
		email: "",
		password: "",
		remember: false
	});
	return /* @__PURE__ */ jsx(StorefrontLayout, {
		title: "Sign in",
		children: /* @__PURE__ */ jsx(AuthCard, {
			title: "Welcome back",
			lede: "Sign in to track orders, save addresses and check out faster.",
			status,
			footer: /* @__PURE__ */ jsxs(Fragment, { children: [
				"New here?",
				" ",
				/* @__PURE__ */ jsx(Link, {
					href: "/register",
					className: "underline",
					children: "Create an account"
				}),
				" ",
				"— it takes about a minute."
			] }),
			children: /* @__PURE__ */ jsxs("form", {
				className: "flex flex-col gap-4",
				onSubmit: (event) => {
					event.preventDefault();
					form.post("/login");
				},
				children: [
					/* @__PURE__ */ jsx(Field, {
						label: "Email",
						error: form.errors.email,
						children: ({ id, describedBy, invalid }) => /* @__PURE__ */ jsx(Input, {
							id,
							type: "email",
							autoComplete: "username",
							"aria-describedby": describedBy,
							invalid,
							value: form.data.email,
							onChange: (event) => form.setData("email", event.target.value)
						})
					}),
					/* @__PURE__ */ jsx(Field, {
						label: "Password",
						error: form.errors.password,
						children: ({ id, describedBy, invalid }) => /* @__PURE__ */ jsx(Input, {
							id,
							type: "password",
							autoComplete: "current-password",
							"aria-describedby": describedBy,
							invalid,
							value: form.data.password,
							onChange: (event) => form.setData("password", event.target.value)
						})
					}),
					/* @__PURE__ */ jsxs("div", {
						className: "flex items-center justify-between text-[13px]",
						children: [/* @__PURE__ */ jsxs("label", {
							className: "flex items-center gap-2",
							children: [/* @__PURE__ */ jsx("input", {
								type: "checkbox",
								checked: form.data.remember,
								onChange: (event) => form.setData("remember", event.target.checked)
							}), "Stay signed in"]
						}), /* @__PURE__ */ jsx(Link, {
							href: "/forgot-password",
							className: "underline",
							children: "Forgot your password?"
						})]
					}),
					/* @__PURE__ */ jsx(Button, {
						type: "submit",
						variant: "primary",
						loading: form.processing,
						loadingLabel: "Signing in…",
						children: "Sign in"
					})
				]
			})
		})
	});
}
//#endregion
//#region resources/js/storefront/pages/Auth/Register.tsx
var Register_exports = /* @__PURE__ */ __exportAll({ default: () => Register });
function Register() {
	const form = useForm({
		first_name: "",
		last_name: "",
		email: "",
		password: "",
		password_confirmation: "",
		marketing_opt_in: false
	});
	return /* @__PURE__ */ jsx(StorefrontLayout, {
		title: "Create an account",
		children: /* @__PURE__ */ jsx(AuthCard, {
			title: "Create your account",
			lede: "One account for every store on the marketplace. You can check out as a guest too — an account just keeps your orders and addresses.",
			footer: /* @__PURE__ */ jsxs(Fragment, { children: [
				"Already have one?",
				" ",
				/* @__PURE__ */ jsx(Link, {
					href: "/login",
					className: "underline",
					children: "Sign in"
				})
			] }),
			children: /* @__PURE__ */ jsxs("form", {
				className: "flex flex-col gap-4",
				onSubmit: (event) => {
					event.preventDefault();
					form.post("/register");
				},
				children: [
					/* @__PURE__ */ jsxs("div", {
						className: "grid grid-cols-2 gap-4",
						children: [/* @__PURE__ */ jsx(Field, {
							label: "First name",
							error: form.errors.first_name,
							children: ({ id, describedBy, invalid }) => /* @__PURE__ */ jsx(Input, {
								id,
								autoComplete: "given-name",
								"aria-describedby": describedBy,
								invalid,
								value: form.data.first_name,
								onChange: (event) => form.setData("first_name", event.target.value)
							})
						}), /* @__PURE__ */ jsx(Field, {
							label: "Last name",
							error: form.errors.last_name,
							children: ({ id, describedBy, invalid }) => /* @__PURE__ */ jsx(Input, {
								id,
								autoComplete: "family-name",
								"aria-describedby": describedBy,
								invalid,
								value: form.data.last_name,
								onChange: (event) => form.setData("last_name", event.target.value)
							})
						})]
					}),
					/* @__PURE__ */ jsx(Field, {
						label: "Email",
						error: form.errors.email,
						children: ({ id, describedBy, invalid }) => /* @__PURE__ */ jsx(Input, {
							id,
							type: "email",
							autoComplete: "username",
							"aria-describedby": describedBy,
							invalid,
							value: form.data.email,
							onChange: (event) => form.setData("email", event.target.value)
						})
					}),
					/* @__PURE__ */ jsx(Field, {
						label: "Password",
						error: form.errors.password,
						hint: "At least eight characters, and not one that has appeared in a known breach.",
						children: ({ id, describedBy, invalid }) => /* @__PURE__ */ jsx(Input, {
							id,
							type: "password",
							autoComplete: "new-password",
							"aria-describedby": describedBy,
							invalid,
							value: form.data.password,
							onChange: (event) => form.setData("password", event.target.value)
						})
					}),
					/* @__PURE__ */ jsx(Field, {
						label: "Confirm password",
						children: ({ id }) => /* @__PURE__ */ jsx(Input, {
							id,
							type: "password",
							autoComplete: "new-password",
							value: form.data.password_confirmation,
							onChange: (event) => form.setData("password_confirmation", event.target.value)
						})
					}),
					/* @__PURE__ */ jsxs("label", {
						className: "flex items-start gap-2 text-[13px]",
						children: [/* @__PURE__ */ jsx("input", {
							type: "checkbox",
							className: "mt-1",
							checked: form.data.marketing_opt_in,
							onChange: (event) => form.setData("marketing_opt_in", event.target.checked)
						}), /* @__PURE__ */ jsx("span", { children: "Email me new arrivals and seller news. Order emails are sent either way." })]
					}),
					/* @__PURE__ */ jsx(Button, {
						type: "submit",
						variant: "primary",
						loading: form.processing,
						loadingLabel: "Creating account…",
						children: "Create account"
					})
				]
			})
		})
	});
}
//#endregion
//#region resources/js/storefront/pages/Auth/ResetPassword.tsx
var ResetPassword_exports = /* @__PURE__ */ __exportAll({ default: () => ResetPassword });
function ResetPassword() {
	const { token, email } = usePage().props;
	const form = useForm({
		token,
		email,
		password: "",
		password_confirmation: ""
	});
	return /* @__PURE__ */ jsx(StorefrontLayout, {
		title: "Choose a new password",
		children: /* @__PURE__ */ jsx(AuthCard, {
			title: "Choose a new password",
			lede: "This link works once, and expires after 60 minutes.",
			children: /* @__PURE__ */ jsxs("form", {
				className: "flex flex-col gap-4",
				onSubmit: (event) => {
					event.preventDefault();
					form.post("/reset-password");
				},
				children: [
					/* @__PURE__ */ jsx(Field, {
						label: "Email",
						error: form.errors.email,
						children: ({ id, describedBy, invalid }) => /* @__PURE__ */ jsx(Input, {
							id,
							type: "email",
							autoComplete: "username",
							"aria-describedby": describedBy,
							invalid,
							value: form.data.email,
							onChange: (event) => form.setData("email", event.target.value)
						})
					}),
					/* @__PURE__ */ jsx(Field, {
						label: "New password",
						error: form.errors.password,
						hint: "At least eight characters, and not one that has appeared in a known breach.",
						children: ({ id, describedBy, invalid }) => /* @__PURE__ */ jsx(Input, {
							id,
							type: "password",
							autoComplete: "new-password",
							"aria-describedby": describedBy,
							invalid,
							value: form.data.password,
							onChange: (event) => form.setData("password", event.target.value)
						})
					}),
					/* @__PURE__ */ jsx(Field, {
						label: "Confirm new password",
						children: ({ id }) => /* @__PURE__ */ jsx(Input, {
							id,
							type: "password",
							autoComplete: "new-password",
							value: form.data.password_confirmation,
							onChange: (event) => form.setData("password_confirmation", event.target.value)
						})
					}),
					/* @__PURE__ */ jsx(Button, {
						type: "submit",
						variant: "primary",
						loading: form.processing,
						loadingLabel: "Saving…",
						children: "Set new password"
					})
				]
			})
		})
	});
}
//#endregion
//#region resources/js/storefront/pages/Auth/VerifyEmail.tsx
var VerifyEmail_exports = /* @__PURE__ */ __exportAll({ default: () => VerifyEmail });
function VerifyEmail() {
	const { status, auth } = usePage().props;
	const resend = useForm({});
	const logout = useForm({});
	return /* @__PURE__ */ jsx(StorefrontLayout, {
		title: "Verify your email",
		children: /* @__PURE__ */ jsx(AuthCard, {
			title: "Check your email",
			lede: `We've sent a verification link to ${auth.user?.email ?? "your address"}. It expires in 60 minutes and can only be used once.`,
			status,
			children: /* @__PURE__ */ jsxs("div", {
				className: "flex flex-wrap gap-2",
				children: [/* @__PURE__ */ jsx(Button, {
					variant: "primary",
					loading: resend.processing,
					loadingLabel: "Sending…",
					onClick: () => resend.post("/verify-email/send"),
					children: "Send it again"
				}), /* @__PURE__ */ jsx(Button, {
					variant: "ghost",
					onClick: () => logout.post("/logout"),
					children: "Sign out"
				})]
			})
		})
	});
}
//#endregion
//#region resources/js/design-system/patterns/IssueNotice.tsx
function IssueNotice({ messages, heading, live = "status" }) {
	if (messages.length === 0) return null;
	const blocking = messages.some((message) => message.blocking);
	return /* @__PURE__ */ jsxs("section", {
		role: live,
		"aria-live": live === "alert" ? "assertive" : "polite",
		className: ["mb-8 px-5 py-4", blocking ? "border-2 border-[var(--vc-accent)]" : "border-2 border-[var(--vc-neutral-400)]"].join(" "),
		children: [/* @__PURE__ */ jsx("h2", {
			className: "mb-3 text-[16px]",
			children: heading
		}), /* @__PURE__ */ jsx("ul", {
			className: "flex flex-col gap-3",
			children: messages.map((message, index) => /* @__PURE__ */ jsxs("li", {
				className: "text-[14px]",
				children: [
					/* @__PURE__ */ jsx("span", {
						className: "font-semibold",
						children: message.title
					}),
					/* @__PURE__ */ jsx("span", {
						className: "sr-only",
						children: message.blocking ? " — must be resolved before checkout" : " — for your information"
					}),
					/* @__PURE__ */ jsx("span", {
						"aria-hidden": "true",
						className: "text-[var(--vc-neutral-600)]",
						children: message.blocking ? " · action needed" : " · for information"
					}),
					/* @__PURE__ */ jsx("p", {
						className: "text-[var(--vc-neutral-700)]",
						children: message.detail
					})
				]
			}, `${message.code}-${index}`))
		})]
	});
}
/**
* A single line's issues, shown inline against the line they belong to.
*
* Short form: the notice at the top of the page carries the explanation,
* this is the marker that says which row it was about.
*/
function LineIssues({ issues }) {
	if (issues.length === 0) return null;
	return /* @__PURE__ */ jsx("ul", {
		className: "mt-2 flex flex-col gap-1",
		children: issues.map((issue, index) => /* @__PURE__ */ jsxs("li", {
			className: ["text-[12px]", issue.blocking ? "font-semibold text-[var(--vc-accent-800)]" : "text-[var(--vc-neutral-700)]"].join(" "),
			children: [issue.blocking ? "▲ " : "• ", issue.label]
		}, `${issue.code}-${index}`))
	});
}
//#endregion
//#region resources/js/design-system/patterns/QuantityStepper.tsx
/**
* A quantity control that never pretends to know the inventory.
*
* The number here is a request. It is sent to the server, the server locks
* the row and decides, and whatever comes back is the truth — so this
* component holds no optimistic count and reconciles to the prop whenever
* it changes. A stepper that quietly kept its own idea of "3" after the
* server said 2 would be the most expensive kind of lie a shop can tell.
*
* `max` disables the increment early as a courtesy. It is not the control:
* a customer who edits the field, or whose stock moved a second ago, is
* refused by the action.
*
* Keyboard: the two buttons are buttons and the field is a number input,
* so tab, arrows and typing all work without any handler of ours.
*/
function QuantityStepper({ value, max, disabled = false, busy = false, label, onChange }) {
	const [draft, setDraft] = useState(String(value));
	const [settled, setSettled] = useState(value);
	if (value !== settled) {
		setSettled(value);
		setDraft(String(value));
	}
	const commit = (next) => {
		if (!Number.isFinite(next) || next === value) {
			setDraft(String(value));
			return;
		}
		onChange(Math.max(0, Math.trunc(next)));
	};
	return /* @__PURE__ */ jsxs("div", {
		className: "inline-flex items-stretch border-2 border-[var(--vc-text)]",
		children: [
			/* @__PURE__ */ jsx("button", {
				type: "button",
				className: "px-3 text-[16px] leading-none disabled:opacity-45",
				disabled: disabled || busy || value <= 1,
				"aria-label": `Decrease quantity of ${label}`,
				onClick: () => commit(value - 1),
				children: "−"
			}),
			/* @__PURE__ */ jsx("input", {
				type: "number",
				inputMode: "numeric",
				min: 1,
				max: Math.max(max, 1),
				value: draft,
				disabled: disabled || busy,
				"aria-label": `Quantity of ${label}`,
				"aria-busy": busy || void 0,
				className: "vc-tabular w-[3.5rem] border-x-2 border-[var(--vc-text)] bg-transparent py-2 text-center text-[14px] disabled:opacity-45",
				onChange: (event) => setDraft(event.target.value),
				onBlur: () => commit(Number(draft)),
				onKeyDown: (event) => {
					if (event.key === "Enter") {
						event.preventDefault();
						commit(Number(draft));
					}
				}
			}),
			/* @__PURE__ */ jsx("button", {
				type: "button",
				className: "px-3 text-[16px] leading-none disabled:opacity-45",
				disabled: disabled || busy || value >= max,
				"aria-label": `Increase quantity of ${label}`,
				onClick: () => commit(value + 1),
				children: "+"
			})
		]
	});
}
//#endregion
//#region resources/js/storefront/pages/Cart/Index.tsx
var Index_exports$2 = /* @__PURE__ */ __exportAll({ default: () => CartIndex });
/**
* The basket.
*
* Every number on this page came from the server, which rebuilt it from
* the live offers a moment ago. React does not add up a subtotal here and
* does not decide whether a line can be bought — both are facts about
* inventory, and a page that computed them would be guessing at data it
* cannot see.
*
* Grouped by seller because that is what the customer is actually doing:
* buying from two or three businesses at once, which will become two or
* three orders and two or three parcels. Showing that now is more honest
* than revealing it on the confirmation screen.
*/
function CartIndex() {
	const page = usePage().props;
	const cart = page.cartView;
	const [pending, setPending] = useState(null);
	const submit = (line, quantity) => {
		setPending(line);
		router.patch(`/cart/${encodeURIComponent(line)}`, { quantity }, {
			preserveScroll: true,
			onFinish: () => setPending(null)
		});
	};
	const remove = (line) => {
		setPending(line);
		router.delete(`/cart/${encodeURIComponent(line)}`, {
			preserveScroll: true,
			onFinish: () => setPending(null)
		});
	};
	return /* @__PURE__ */ jsxs(StorefrontLayout, {
		title: "Your basket",
		children: [
			/* @__PURE__ */ jsx("h1", {
				className: "mb-8 text-[42px]",
				children: "Your basket"
			}),
			page.mergeNotices.length > 0 ? /* @__PURE__ */ jsx(IssueNotice, {
				live: "alert",
				heading: "Your basket changed when you signed in",
				messages: page.mergeNotices.map(toMessage)
			}) : null,
			page.errors.quantity ? /* @__PURE__ */ jsx("p", {
				role: "alert",
				className: "mb-8 border-2 border-[var(--vc-accent)] px-4 py-3 text-[14px]",
				children: page.errors.quantity
			}) : null,
			cart.itemCount === 0 ? /* @__PURE__ */ jsx(EmptyState, {
				title: "Nothing in your basket yet",
				body: "Browse the marketplace and add something from a seller you like the look of.",
				actions: /* @__PURE__ */ jsx(Link, {
					href: "/search",
					children: /* @__PURE__ */ jsx(Button, {
						variant: "primary",
						children: "Browse the marketplace"
					})
				})
			}) : /* @__PURE__ */ jsxs("div", {
				className: "grid gap-14 lg:grid-cols-[1fr_320px]",
				children: [/* @__PURE__ */ jsx("div", { children: cart.groups.map((group) => /* @__PURE__ */ jsxs("section", {
					className: "mb-10",
					children: [
						/* @__PURE__ */ jsx("h2", {
							className: "mb-1 text-[20px]",
							children: /* @__PURE__ */ jsx(Link, {
								href: `/stores/${group.storeSlug}`,
								children: group.storeName
							})
						}),
						/* @__PURE__ */ jsxs("p", {
							className: "mb-4 text-[13px] text-[var(--vc-neutral-600)]",
							children: [
								"Sold and delivered by ",
								group.storeName,
								" · ",
								group.subtotal
							]
						}),
						/* @__PURE__ */ jsx("ul", {
							className: "border-t-2 border-[var(--vc-text)]",
							children: group.lines.map((line) => /* @__PURE__ */ jsx(CartRow, {
								line,
								busy: pending === line.lineIdentity,
								onQuantity: (quantity) => submit(line.lineIdentity, quantity),
								onRemove: () => remove(line.lineIdentity)
							}, line.lineIdentity))
						})
					]
				}, group.sellerAccountId)) }), /* @__PURE__ */ jsxs("aside", {
					className: "lg:sticky lg:top-8 lg:self-start",
					children: [
						/* @__PURE__ */ jsx("h2", {
							className: "mb-4 text-[20px]",
							children: "Summary"
						}),
						/* @__PURE__ */ jsxs("div", {
							className: "flex items-baseline justify-between border-t-2 border-[var(--vc-text)] pt-3 text-[18px] font-semibold",
							children: [/* @__PURE__ */ jsx("span", { children: "Subtotal" }), /* @__PURE__ */ jsx("span", {
								className: "vc-tabular",
								children: cart.subtotal
							})]
						}),
						/* @__PURE__ */ jsxs("p", {
							className: "mt-1 mb-5 text-[12px] text-[var(--vc-neutral-600)]",
							children: [
								cart.quantityCount,
								" ",
								cart.quantityCount === 1 ? "item" : "items",
								" ",
								"across ",
								cart.groups.length,
								" ",
								cart.groups.length === 1 ? "seller" : "sellers",
								". Delivery and tax are shown at checkout."
							]
						}),
						cart.hasBlockingIssues ? /* @__PURE__ */ jsxs(Fragment, { children: [/* @__PURE__ */ jsx(Button, {
							variant: "primary",
							block: true,
							disabled: true,
							children: "Continue to checkout"
						}), /* @__PURE__ */ jsx("p", {
							role: "status",
							className: "mt-2 text-[12px] text-[var(--vc-accent-800)]",
							children: "Resolve the items marked above before continuing."
						})] }) : /* @__PURE__ */ jsx(Link, {
							href: "/checkout",
							className: "block",
							children: /* @__PURE__ */ jsx(Button, {
								variant: "primary",
								block: true,
								children: "Continue to checkout"
							})
						})
					]
				})]
			})
		]
	});
}
/**
* One line.
*
* Stacks on a phone and lays out in columns from `sm` up — a financial
* table forced to scroll sideways on a 375px screen is not a mobile
* solution, it is a desktop table with a scrollbar.
*/
function CartRow({ line, busy, onQuantity, onRemove }) {
	return /* @__PURE__ */ jsxs("li", {
		className: "flex flex-col gap-4 border-b border-[var(--vc-divider)] py-5 sm:flex-row sm:items-start",
		children: [
			/* @__PURE__ */ jsx("div", {
				className: "h-[88px] w-[88px] shrink-0 bg-[var(--vc-surface)]",
				children: line.imageUrl ? /* @__PURE__ */ jsx("img", {
					src: line.imageUrl,
					alt: "",
					className: "h-full w-full object-cover",
					loading: "lazy"
				}) : null
			}),
			/* @__PURE__ */ jsxs("div", {
				className: "min-w-0 flex-1",
				children: [
					line.brand ? /* @__PURE__ */ jsx("p", {
						className: "text-[12px] tracking-[0.06em] text-[var(--vc-neutral-600)] uppercase",
						children: line.brand
					}) : null,
					/* @__PURE__ */ jsx("h3", {
						className: "text-[16px]",
						children: /* @__PURE__ */ jsx(Link, {
							href: `/products/${line.productSlug}`,
							children: line.productTitle
						})
					}),
					line.variantName ? /* @__PURE__ */ jsx("p", {
						className: "text-[13px] text-[var(--vc-neutral-700)]",
						children: line.variantName
					}) : null,
					/* @__PURE__ */ jsxs("p", {
						className: "mt-1 text-[13px] text-[var(--vc-neutral-700)]",
						children: [
							"Sold by ",
							/* @__PURE__ */ jsx(Link, {
								href: `/stores/${line.storeSlug}`,
								children: line.storeName
							}),
							/* @__PURE__ */ jsxs("span", {
								className: "text-[var(--vc-neutral-600)]",
								children: [
									" · ",
									line.unitPrice,
									" each"
								]
							})
						]
					}),
					/* @__PURE__ */ jsx("p", {
						className: "mt-1 text-[12px] text-[var(--vc-neutral-600)]",
						children: line.available <= 0 ? "Out of stock" : `${line.available} available from this seller`
					}),
					/* @__PURE__ */ jsx(LineIssues, { issues: line.issues })
				]
			}),
			/* @__PURE__ */ jsxs("div", {
				className: "flex items-center gap-4 sm:flex-col sm:items-end sm:gap-2",
				children: [
					/* @__PURE__ */ jsx(QuantityStepper, {
						value: line.quantity,
						max: line.maxQuantity,
						busy,
						disabled: line.available <= 0,
						label: line.productTitle,
						onChange: onQuantity
					}),
					/* @__PURE__ */ jsx("span", {
						className: "vc-tabular text-[16px] font-semibold sm:mt-1",
						children: line.lineTotal
					}),
					/* @__PURE__ */ jsxs("button", {
						type: "button",
						onClick: onRemove,
						disabled: busy,
						className: "text-[13px] underline underline-offset-4 disabled:opacity-45",
						children: ["Remove", /* @__PURE__ */ jsxs("span", {
							className: "sr-only",
							children: [
								" ",
								line.productTitle,
								" from your basket"
							]
						})]
					})
				]
			})
		]
	});
}
/**
* A raw issue, rendered as a sentence.
*
* The cart page's merge notices arrive as codes rather than as prose,
* because they were stored in the session before there was a page to
* write them for. Everything else on the checkout side gets its sentence
* from the server.
*/
function toMessage(issue) {
	const detail = {
		PRICE_CHANGED: "The price of this item changed since you added it.",
		OUT_OF_STOCK: "This item had sold out, so it was not added to your basket.",
		QUANTITY_REDUCED: issue.available === void 0 ? "The quantity was reduced to what the seller has available." : `Quantity updated because only ${issue.available} ${issue.available === 1 ? "item is" : "items are"} currently available.`,
		OFFER_UNAVAILABLE: "This offer is no longer available and was removed from your basket.",
		SELLER_UNAVAILABLE: "The seller is not trading at the moment, so this item was removed from your basket.",
		PRODUCT_UNAVAILABLE: "This product has been withdrawn, so it was removed from your basket.",
		VARIANT_UNAVAILABLE: "The option you chose is no longer offered, so it was removed.",
		CURRENCY_MISMATCH: "This item is priced in a different currency and was removed."
	};
	return {
		code: issue.code,
		blocking: issue.blocking,
		title: issue.label,
		detail: detail[issue.code]
	};
}
//#endregion
//#region resources/js/design-system/patterns/ProductCard.tsx
/**
* One product, everywhere it is listed.
*
* Search, category pages and store pages all render this — §29 forbids
* three implementations that drift. It computes nothing: the price string,
* the range and the stock state all arrive decided by the server, so a
* card and the product page it links to cannot quote different numbers.
*
* Commerce photography stays in full colour; the Modernist system's
* greyscale applies to chrome, not to the goods.
*/
function ProductCard({ product, position, onSelect }) {
	const outOfStock = product.stockState === "out_of_stock";
	return /* @__PURE__ */ jsx("article", {
		className: "flex flex-col",
		children: /* @__PURE__ */ jsxs(Link, {
			href: `/products/${product.slug}`,
			className: "group flex flex-col gap-3",
			onClick: () => {
				if (onSelect && position !== void 0) onSelect(product, position);
			},
			children: [/* @__PURE__ */ jsx("div", {
				className: "aspect-square border-2 border-[var(--vc-text)] bg-[var(--vc-surface)]",
				children: product.imageUrl ? /* @__PURE__ */ jsx("img", {
					src: product.imageUrl,
					alt: product.imageAlt ?? "",
					loading: "lazy",
					className: "h-full w-full object-cover"
				}) : /* @__PURE__ */ jsx("div", {
					className: "flex h-full w-full items-center justify-center text-[12px] text-[var(--vc-neutral-600)]",
					children: "No photograph yet"
				})
			}), /* @__PURE__ */ jsxs("div", {
				className: "flex flex-col gap-1",
				children: [
					product.brand ? /* @__PURE__ */ jsx("span", {
						className: "text-[11px] tracking-[0.08em] text-[var(--vc-neutral-600)] uppercase",
						children: product.brand
					}) : null,
					/* @__PURE__ */ jsx("h3", {
						className: "text-[15px] leading-snug font-semibold underline-offset-4 group-hover:underline",
						children: product.title
					}),
					product.price === "" ? /* @__PURE__ */ jsx("span", {
						className: "text-[13px] text-[var(--vc-neutral-600)]",
						children: "No sellers yet"
					}) : /* @__PURE__ */ jsxs("span", {
						className: "vc-tabular text-[15px]",
						children: [product.hasPriceRange ? "From " : "", product.price]
					}),
					/* @__PURE__ */ jsxs("div", {
						className: "mt-1 flex flex-wrap items-center gap-2",
						children: [outOfStock ? /* @__PURE__ */ jsx(StatusBadge, {
							domain: "stock",
							value: product.stockState
						}) : null, product.offerCount > 1 ? /* @__PURE__ */ jsxs("span", {
							className: "text-[12px] text-[var(--vc-neutral-600)]",
							children: [product.offerCount, " sellers"]
						}) : null]
					})
				]
			})]
		})
	});
}
/** The grid every listing page lays its cards out on. */
function ProductGrid({ products, onSelect }) {
	return /* @__PURE__ */ jsx("div", {
		className: "grid grid-cols-2 gap-x-6 gap-y-10 md:grid-cols-3 lg:grid-cols-4",
		children: products.map((product, index) => /* @__PURE__ */ jsx(ProductCard, {
			product,
			position: index + 1,
			...onSelect ? { onSelect } : {}
		}, product.slug))
	});
}
//#endregion
//#region resources/js/design-system/patterns/StructuredData.tsx
/**
* Emits JSON-LD the server assembled.
*
* The shape is built in PHP from database rows and passed through
* untouched — deliberately, so no component can add a rating or a price
* the catalogue cannot support. Nothing here decides what to claim.
*/
function StructuredData({ documents }) {
	if (documents.length === 0) return null;
	return /* @__PURE__ */ jsx(Head, { children: documents.map((document, index) => /* @__PURE__ */ jsx("script", {
		type: "application/ld+json",
		dangerouslySetInnerHTML: { __html: JSON.stringify(document) }
	}, index)) });
}
//#endregion
//#region resources/js/storefront/components/DiscoveryFilters.tsx
/**
* The filter rail, driven entirely by the URL.
*
* Every control is a link in disguise: changing one pushes a new query
* string and the server decides what it means. Nothing is filtered in the
* browser, so a result set can be shared, bookmarked and crawled — which a
* client-side filter cannot.
*
* The facets come from the server too: which attributes a category offers
* is the catalogue's decision, not this component's.
*/
function DiscoveryFilters({ url, facets, applied }) {
	const push = (changes) => {
		const next = {
			q: applied.q,
			brand: applied.brand,
			condition: applied.condition,
			attributes: applied.attributes,
			in_stock: applied.in_stock,
			min_price: applied.min_price,
			max_price: applied.max_price,
			sort: applied.sort,
			...changes,
			page: "1"
		};
		router.get(url, next, {
			preserveScroll: true,
			preserveState: true
		});
	};
	const toggle = (list, value) => list.includes(value) ? list.filter((item) => item !== value) : [...list, value];
	return /* @__PURE__ */ jsxs("aside", {
		className: "flex flex-col gap-8",
		"aria-label": "Filters",
		children: [
			applied.hasFilters ? /* @__PURE__ */ jsx(Button, {
				variant: "ghost",
				onClick: () => router.get(url, { q: applied.q }),
				children: "Clear all filters"
			}) : null,
			/* @__PURE__ */ jsxs("section", { children: [/* @__PURE__ */ jsx("h2", {
				className: "mb-3 text-[13px] tracking-[0.08em] uppercase",
				children: "Price"
			}), /* @__PURE__ */ jsxs("form", {
				className: "flex items-end gap-2",
				onSubmit: (event) => {
					event.preventDefault();
					const data = new FormData(event.currentTarget);
					push({
						min_price: String(data.get("min_price") ?? ""),
						max_price: String(data.get("max_price") ?? "")
					});
				},
				children: [
					/* @__PURE__ */ jsx(Field, {
						label: "From",
						children: ({ id }) => /* @__PURE__ */ jsx(Input, {
							id,
							name: "min_price",
							defaultValue: applied.min_price,
							inputMode: "decimal"
						})
					}),
					/* @__PURE__ */ jsx(Field, {
						label: "To",
						children: ({ id }) => /* @__PURE__ */ jsx(Input, {
							id,
							name: "max_price",
							defaultValue: applied.max_price,
							inputMode: "decimal"
						})
					}),
					/* @__PURE__ */ jsx(Button, {
						type: "submit",
						variant: "secondary",
						children: "Go"
					})
				]
			})] }),
			facets.availability && facets.availability.length > 0 ? /* @__PURE__ */ jsxs("section", { children: [/* @__PURE__ */ jsx("h2", {
				className: "mb-3 text-[13px] tracking-[0.08em] uppercase",
				children: "Availability"
			}), facets.availability.map((option) => /* @__PURE__ */ jsxs("label", {
				className: "flex items-center gap-2 py-1 text-[14px]",
				children: [
					/* @__PURE__ */ jsx("input", {
						type: "checkbox",
						checked: option.selected,
						onChange: () => push({ in_stock: !applied.in_stock })
					}),
					option.label,
					/* @__PURE__ */ jsxs("span", {
						className: "text-[12px] text-[var(--vc-neutral-600)]",
						children: [
							"(",
							option.count,
							")"
						]
					})
				]
			}, option.value))] }) : null,
			facets.brand && facets.brand.length > 0 ? /* @__PURE__ */ jsxs("section", { children: [/* @__PURE__ */ jsx("h2", {
				className: "mb-3 text-[13px] tracking-[0.08em] uppercase",
				children: "Brand"
			}), facets.brand.map((option) => /* @__PURE__ */ jsxs("label", {
				className: "flex items-center gap-2 py-1 text-[14px]",
				children: [
					/* @__PURE__ */ jsx("input", {
						type: "checkbox",
						checked: option.selected,
						onChange: () => push({ brand: toggle(applied.brand, option.value) })
					}),
					option.label,
					/* @__PURE__ */ jsxs("span", {
						className: "text-[12px] text-[var(--vc-neutral-600)]",
						children: [
							"(",
							option.count,
							")"
						]
					})
				]
			}, option.value))] }) : null,
			facets.condition && facets.condition.length > 0 ? /* @__PURE__ */ jsxs("section", { children: [/* @__PURE__ */ jsx("h2", {
				className: "mb-3 text-[13px] tracking-[0.08em] uppercase",
				children: "Condition"
			}), facets.condition.map((option) => /* @__PURE__ */ jsxs("label", {
				className: "flex items-center gap-2 py-1 text-[14px]",
				children: [
					/* @__PURE__ */ jsx("input", {
						type: "checkbox",
						checked: option.selected,
						onChange: () => push({ condition: toggle(applied.condition, option.value) })
					}),
					option.label,
					/* @__PURE__ */ jsxs("span", {
						className: "text-[12px] text-[var(--vc-neutral-600)]",
						children: [
							"(",
							option.count,
							")"
						]
					})
				]
			}, option.value))] }) : null,
			(facets.attributes ?? []).map((facet) => /* @__PURE__ */ jsxs("section", { children: [/* @__PURE__ */ jsxs("h2", {
				className: "mb-3 text-[13px] tracking-[0.08em] uppercase",
				children: [facet.name, facet.unit ? ` (${facet.unit})` : ""]
			}), facet.options.map((option) => /* @__PURE__ */ jsxs("label", {
				className: "flex items-center gap-2 py-1 text-[14px]",
				children: [/* @__PURE__ */ jsx("input", {
					type: "checkbox",
					checked: (applied.attributes[facet.code] ?? []).includes(option.value),
					onChange: () => push({ attributes: {
						...applied.attributes,
						[facet.code]: toggle(applied.attributes[facet.code] ?? [], option.value)
					} })
				}), option.label]
			}, option.value))] }, facet.code))
		]
	});
}
/** The sort control, shared by every listing page. */
function SortSelect({ url, applied, sorts }) {
	return /* @__PURE__ */ jsx(Field, {
		label: "Sort by",
		children: ({ id }) => /* @__PURE__ */ jsx(Select, {
			id,
			value: applied.sort,
			onChange: (event) => router.get(url, {
				q: applied.q,
				brand: applied.brand,
				condition: applied.condition,
				attributes: applied.attributes,
				in_stock: applied.in_stock,
				min_price: applied.min_price,
				max_price: applied.max_price,
				sort: event.target.value
			}, { preserveScroll: true }),
			children: sorts.map((sort) => /* @__PURE__ */ jsx("option", {
				value: sort.value,
				children: sort.label
			}, sort.value))
		})
	});
}
/** Page links, server-side paged. */
function Pagination({ url, applied, page, lastPage }) {
	if (lastPage <= 1) return null;
	const go = (target) => {
		router.get(url, {
			q: applied.q,
			brand: applied.brand,
			condition: applied.condition,
			attributes: applied.attributes,
			in_stock: applied.in_stock,
			min_price: applied.min_price,
			max_price: applied.max_price,
			sort: applied.sort,
			page: String(target)
		}, { preserveScroll: false });
	};
	return /* @__PURE__ */ jsxs("nav", {
		className: "mt-12 flex items-center gap-4",
		"aria-label": "Pagination",
		children: [
			/* @__PURE__ */ jsx(Button, {
				variant: "secondary",
				disabled: page <= 1,
				onClick: () => go(page - 1),
				children: "Previous"
			}),
			/* @__PURE__ */ jsxs("span", {
				className: "vc-tabular text-[13px] text-[var(--vc-neutral-600)]",
				children: [
					"Page ",
					page,
					" of ",
					lastPage
				]
			}),
			/* @__PURE__ */ jsx(Button, {
				variant: "secondary",
				disabled: page >= lastPage,
				onClick: () => go(page + 1),
				children: "Next"
			})
		]
	});
}
//#endregion
//#region resources/js/storefront/pages/Category/Show.tsx
var Show_exports$2 = /* @__PURE__ */ __exportAll({ default: () => Show$2 });
/**
* A category as a real discovery page.
*
* Filters and sorting are URL state, so every view is linkable — but only
* the clean first page is indexable. Six hundred crawlable permutations of
* one category is how a catalogue disappears from search results, which is
* why the robots directive comes from the server rather than from here.
*/
function Show$2() {
	const { category, breadcrumbs, children, results, facets, applied, sorts, seo } = usePage().props;
	const url = `/categories/${category.slug}`;
	return /* @__PURE__ */ jsxs(StorefrontLayout, {
		title: seo.title,
		children: [
			/* @__PURE__ */ jsxs(Head, { children: [
				seo.description ? /* @__PURE__ */ jsx("meta", {
					name: "description",
					content: seo.description
				}) : null,
				/* @__PURE__ */ jsx("meta", {
					name: "robots",
					content: seo.robots
				}),
				/* @__PURE__ */ jsx("link", {
					rel: "canonical",
					href: seo.canonical
				})
			] }),
			/* @__PURE__ */ jsx(StructuredData, { documents: [{
				"@context": "https://schema.org",
				"@type": "BreadcrumbList",
				itemListElement: breadcrumbs.map((crumb, index) => ({
					"@type": "ListItem",
					position: index + 1,
					name: crumb.name,
					item: crumb.url
				}))
			}] }),
			/* @__PURE__ */ jsx("nav", {
				"aria-label": "Breadcrumb",
				className: "mb-6 text-[13px] text-[var(--vc-neutral-600)]",
				children: /* @__PURE__ */ jsx("ol", {
					className: "flex flex-wrap items-center gap-2",
					children: breadcrumbs.map((crumb, index) => /* @__PURE__ */ jsxs("li", {
						className: "flex items-center gap-2",
						children: [index > 0 ? /* @__PURE__ */ jsx("span", {
							"aria-hidden": "true",
							children: "/"
						}) : null, /* @__PURE__ */ jsx(Link, {
							href: crumb.url,
							className: "underline underline-offset-4",
							children: crumb.name
						})]
					}, crumb.url))
				})
			}),
			/* @__PURE__ */ jsxs("header", {
				className: "mb-8",
				children: [/* @__PURE__ */ jsx("h1", {
					className: "text-[32px] leading-tight",
					children: category.name
				}), category.description ? /* @__PURE__ */ jsx("p", {
					className: "mt-2 max-w-[68ch] text-[15px] text-[var(--vc-neutral-700)]",
					children: category.description
				}) : null]
			}),
			children.length > 0 ? /* @__PURE__ */ jsx("nav", {
				"aria-label": "Subcategories",
				className: "mb-10 flex flex-wrap gap-2",
				children: children.map((child) => /* @__PURE__ */ jsx(Link, {
					href: `/categories/${child.slug}`,
					className: "border-2 border-[var(--vc-text)] px-3 py-2 text-[14px] hover:bg-[var(--vc-surface)]",
					children: child.name
				}, child.slug))
			}) : null,
			/* @__PURE__ */ jsxs("div", {
				className: "grid gap-10 lg:grid-cols-[240px_minmax(0,1fr)]",
				children: [/* @__PURE__ */ jsx(DiscoveryFilters, {
					url,
					facets,
					applied
				}), /* @__PURE__ */ jsxs("div", { children: [
					/* @__PURE__ */ jsxs("div", {
						className: "mb-6 flex flex-wrap items-end justify-between gap-4",
						children: [/* @__PURE__ */ jsxs("p", {
							className: "vc-tabular text-[13px] text-[var(--vc-neutral-600)]",
							children: [
								results.total,
								" ",
								results.total === 1 ? "product" : "products"
							]
						}), /* @__PURE__ */ jsx("div", {
							className: "min-w-[200px]",
							children: /* @__PURE__ */ jsx(SortSelect, {
								url,
								applied,
								sorts
							})
						})]
					}),
					results.data.length === 0 ? /* @__PURE__ */ jsx(EmptyState, {
						title: "Nothing here yet",
						body: applied.hasFilters ? "No products match these filters. Removing one may help." : "No products are listed in this category at the moment."
					}) : /* @__PURE__ */ jsx(ProductGrid, { products: results.data }),
					/* @__PURE__ */ jsx(Pagination, {
						url,
						applied,
						page: results.page,
						lastPage: results.lastPage
					})
				] })]
			})
		]
	});
}
//#endregion
//#region resources/js/storefront/pages/Checkout/Index.tsx
var Index_exports$1 = /* @__PURE__ */ __exportAll({ default: () => CheckoutIndex });
/**
* Review, then hand off to payment.
*
* Nothing priced on this page is posted back. The form carries an address,
* an email and an idempotency key; the server rebuilds the quote from the
* live offers when the button is pressed and refuses if it does not match
* what the customer was shown. That is why there is no hidden total field
* here — there is nowhere for a tampered price to enter.
*
* The button says "Continue to payment" because that is what it does. M4
* produces an order awaiting payment; nothing has been charged and no card
* details have been asked for, and a button labelled "Pay now" would be
* claiming otherwise.
*/
function CheckoutIndex() {
	const page = usePage().props;
	const quote = page.quote;
	const cart = quote.cart;
	const errorSummary = useRef(null);
	const [useSaved, setUseSaved] = useState(page.addresses.length > 0);
	const defaultAddress = page.addresses.find((address) => address.isDefault) ?? page.addresses[0];
	const form = useForm({
		idempotency_key: newKey(),
		saved_address: defaultAddress?.publicId ?? "",
		email: page.contact.email ?? "",
		name: defaultAddress?.name ?? page.contact.name ?? "",
		line1: "",
		line2: "",
		city: "",
		state: "",
		postcode: "",
		country: "GB",
		phone: "",
		save_address: false
	});
	const errorCount = Object.keys(form.errors).length;
	useEffect(() => {
		if (errorCount > 0) errorSummary.current?.focus();
	}, [errorCount]);
	const blocking = quote.issues.filter((issue) => issue.blocking);
	return /* @__PURE__ */ jsxs(StorefrontLayout, {
		title: "Checkout",
		children: [
			/* @__PURE__ */ jsx("h1", {
				className: "mb-2 text-[42px]",
				children: "Checkout"
			}),
			/* @__PURE__ */ jsx("p", {
				className: "mb-8 text-[var(--vc-neutral-700)]",
				children: "Nothing is charged at this step. You will be taken to payment once your order is prepared."
			}),
			page.issueMessages.length > 0 ? /* @__PURE__ */ jsx(IssueNotice, {
				live: blocking.length > 0 ? "alert" : "status",
				heading: blocking.length > 0 ? "Some items need attention before you can continue" : "Your basket changed since you added these items",
				messages: page.issueMessages
			}) : null,
			errorCount > 0 ? /* @__PURE__ */ jsxs("div", {
				ref: errorSummary,
				tabIndex: -1,
				role: "alert",
				className: "mb-8 border-2 border-[var(--vc-accent)] px-5 py-4",
				children: [/* @__PURE__ */ jsx("h2", {
					className: "mb-2 text-[16px]",
					children: "We could not complete this checkout"
				}), /* @__PURE__ */ jsx("ul", {
					className: "flex flex-col gap-1 text-[14px]",
					children: Object.entries(form.errors).map(([field, message]) => /* @__PURE__ */ jsx("li", { children: message }, field))
				})]
			}) : null,
			/* @__PURE__ */ jsxs("form", {
				className: "grid gap-14 lg:grid-cols-[1fr_360px]",
				onSubmit: (event) => {
					event.preventDefault();
					form.post("/checkout", { preserveScroll: true });
				},
				children: [/* @__PURE__ */ jsxs("div", {
					className: "flex flex-col gap-10",
					children: [
						/* @__PURE__ */ jsxs("section", {
							"aria-labelledby": "contact-heading",
							children: [/* @__PURE__ */ jsx("h2", {
								id: "contact-heading",
								className: "mb-4 text-[22px]",
								children: "Contact"
							}), /* @__PURE__ */ jsx(Field, {
								label: "Email",
								error: form.errors.email,
								hint: "Your receipt and delivery updates go here.",
								children: ({ id, describedBy, invalid }) => /* @__PURE__ */ jsx(Input, {
									id,
									type: "email",
									autoComplete: "email",
									"aria-describedby": describedBy,
									invalid,
									value: form.data.email,
									onChange: (event) => form.setData("email", event.target.value)
								})
							})]
						}),
						/* @__PURE__ */ jsxs("section", {
							"aria-labelledby": "address-heading",
							children: [
								/* @__PURE__ */ jsx("h2", {
									id: "address-heading",
									className: "mb-4 text-[22px]",
									children: "Delivery address"
								}),
								page.addresses.length > 0 ? /* @__PURE__ */ jsxs("div", {
									className: "mb-4 flex flex-col gap-2",
									children: [/* @__PURE__ */ jsxs("label", {
										className: "flex items-center gap-2 text-[14px]",
										children: [/* @__PURE__ */ jsx("input", {
											type: "radio",
											name: "address-mode",
											checked: useSaved,
											onChange: () => setUseSaved(true)
										}), "Use a saved address"]
									}), /* @__PURE__ */ jsxs("label", {
										className: "flex items-center gap-2 text-[14px]",
										children: [/* @__PURE__ */ jsx("input", {
											type: "radio",
											name: "address-mode",
											checked: !useSaved,
											onChange: () => {
												setUseSaved(false);
												form.setData("saved_address", "");
											}
										}), "Enter a new address"]
									})]
								}) : null,
								useSaved && page.addresses.length > 0 ? /* @__PURE__ */ jsx(Field, {
									label: "Saved address",
									error: form.errors.saved_address,
									children: ({ id, describedBy, invalid }) => /* @__PURE__ */ jsx(Select, {
										id,
										"aria-describedby": describedBy,
										invalid,
										value: form.data.saved_address,
										onChange: (event) => form.setData("saved_address", event.target.value),
										children: page.addresses.map((address) => /* @__PURE__ */ jsx("option", {
											value: address.publicId,
											children: [
												address.label,
												address.name,
												address.line1,
												address.city,
												address.postcode
											].filter(Boolean).join(" · ")
										}, address.publicId))
									})
								}) : /* @__PURE__ */ jsxs("div", {
									className: "flex flex-col gap-4",
									children: [/* @__PURE__ */ jsx(AddressFields, {
										data: form.data,
										errors: form.errors,
										set: form.setData
									}), !page.contact.isGuest ? /* @__PURE__ */ jsxs("label", {
										className: "flex items-center gap-2 text-[14px]",
										children: [/* @__PURE__ */ jsx("input", {
											type: "checkbox",
											checked: form.data.save_address,
											onChange: (event) => form.setData("save_address", event.target.checked)
										}), "Save this address for next time"]
									}) : null]
								})
							]
						}),
						/* @__PURE__ */ jsxs("section", {
							"aria-labelledby": "review-heading",
							children: [
								/* @__PURE__ */ jsx("h2", {
									id: "review-heading",
									className: "mb-1 text-[22px]",
									children: "Your order"
								}),
								/* @__PURE__ */ jsx("p", {
									className: "mb-4 text-[13px] text-[var(--vc-neutral-600)]",
									children: cart.groups.length === 1 ? "One seller, one delivery." : `${cart.groups.length} sellers, so ${cart.groups.length} separate deliveries.`
								}),
								cart.groups.map((group) => /* @__PURE__ */ jsxs("div", {
									className: "mb-6",
									children: [
										/* @__PURE__ */ jsx("h3", {
											className: "mb-2 text-[16px]",
											children: group.storeName
										}),
										/* @__PURE__ */ jsx("ul", {
											className: "border-t border-[var(--vc-divider)]",
											children: group.lines.map((line) => /* @__PURE__ */ jsxs("li", {
												className: "flex justify-between gap-4 border-b border-[var(--vc-divider)] py-3 text-[14px]",
												children: [/* @__PURE__ */ jsxs("span", { children: [
													line.productTitle,
													line.variantName ? ` — ${line.variantName}` : "",
													/* @__PURE__ */ jsxs("span", {
														className: "block text-[12px] text-[var(--vc-neutral-600)]",
														children: [
															line.quantity,
															" × ",
															line.unitPrice
														]
													})
												] }), /* @__PURE__ */ jsx("span", {
													className: "vc-tabular whitespace-nowrap",
													children: line.lineTotal
												})]
											}, line.lineIdentity))
										}),
										/* @__PURE__ */ jsxs("p", {
											className: "pt-2 text-right text-[13px] vc-tabular",
											children: ["Seller subtotal ", group.subtotal]
										})
									]
								}, group.sellerAccountId))
							]
						})
					]
				}), /* @__PURE__ */ jsxs("aside", {
					className: "lg:sticky lg:top-8 lg:self-start",
					children: [
						/* @__PURE__ */ jsx("h2", {
							className: "mb-4 text-[20px]",
							children: "Total"
						}),
						/* @__PURE__ */ jsx(MoneyRow, {
							label: "Items",
							value: quote.itemsTotal
						}),
						/* @__PURE__ */ jsx(MoneyRow, {
							label: "Delivery",
							value: quote.shippingTotalMinor === 0 ? "Included" : quote.shippingTotal,
							note: page.shippingPolicy.note
						}),
						/* @__PURE__ */ jsx(MoneyRow, {
							label: "Tax",
							value: quote.taxTotalMinor === 0 ? "Not calculated" : quote.taxTotal,
							note: page.shippingPolicy.taxNote
						}),
						/* @__PURE__ */ jsx(MoneyRow, {
							label: "Total",
							value: quote.grandTotal,
							strong: true
						}),
						/* @__PURE__ */ jsx("div", {
							className: "mt-6",
							children: /* @__PURE__ */ jsx(Button, {
								type: "submit",
								variant: "primary",
								block: true,
								loading: form.processing,
								loadingLabel: "Preparing your order…",
								disabled: !quote.buyable,
								children: "Continue to payment"
							})
						}),
						!quote.buyable ? /* @__PURE__ */ jsx("p", {
							role: "status",
							className: "mt-2 text-[12px] text-[var(--vc-accent-800)]",
							children: "Resolve the items above before continuing."
						}) : null,
						/* @__PURE__ */ jsxs("p", {
							className: "mt-3 text-[12px] text-[var(--vc-neutral-600)]",
							children: [
								"No payment is taken at this step.",
								" ",
								/* @__PURE__ */ jsx(Link, {
									href: "/cart",
									className: "underline underline-offset-4",
									children: "Back to basket"
								})
							]
						})
					]
				})]
			})
		]
	});
}
/**
* The address form.
*
* Takes the three things it needs rather than the whole form object, so
* it is typed end to end and a renamed field is a compile error rather
* than a field that silently stops saving.
*/
function AddressFields({ data, errors, set }) {
	return /* @__PURE__ */ jsxs(Fragment, { children: [
		/* @__PURE__ */ jsx(Field, {
			label: "Full name",
			error: errors.name,
			children: ({ id, describedBy, invalid }) => /* @__PURE__ */ jsx(Input, {
				id,
				autoComplete: "name",
				"aria-describedby": describedBy,
				invalid,
				value: data.name,
				onChange: (event) => set("name", event.target.value)
			})
		}),
		/* @__PURE__ */ jsx(Field, {
			label: "Address line 1",
			error: errors.line1,
			children: ({ id, describedBy, invalid }) => /* @__PURE__ */ jsx(Input, {
				id,
				autoComplete: "address-line1",
				"aria-describedby": describedBy,
				invalid,
				value: data.line1,
				onChange: (event) => set("line1", event.target.value)
			})
		}),
		/* @__PURE__ */ jsx(Field, {
			label: "Address line 2",
			error: errors.line2,
			children: ({ id, describedBy, invalid }) => /* @__PURE__ */ jsx(Input, {
				id,
				autoComplete: "address-line2",
				"aria-describedby": describedBy,
				invalid,
				value: data.line2,
				onChange: (event) => set("line2", event.target.value)
			})
		}),
		/* @__PURE__ */ jsx(Field, {
			label: "Town or city",
			error: errors.city,
			children: ({ id, describedBy, invalid }) => /* @__PURE__ */ jsx(Input, {
				id,
				autoComplete: "address-level2",
				"aria-describedby": describedBy,
				invalid,
				value: data.city,
				onChange: (event) => set("city", event.target.value)
			})
		}),
		/* @__PURE__ */ jsx(Field, {
			label: "State or province (optional)",
			error: errors.state,
			hint: "Leave blank if your country does not use one.",
			children: ({ id, describedBy, invalid }) => /* @__PURE__ */ jsx(Input, {
				id,
				autoComplete: "address-level1",
				"aria-describedby": describedBy,
				invalid,
				value: data.state,
				onChange: (event) => set("state", event.target.value)
			})
		}),
		/* @__PURE__ */ jsx(Field, {
			label: "Postcode or ZIP",
			error: errors.postcode,
			children: ({ id, describedBy, invalid }) => /* @__PURE__ */ jsx(Input, {
				id,
				autoComplete: "postal-code",
				"aria-describedby": describedBy,
				invalid,
				value: data.postcode,
				onChange: (event) => set("postcode", event.target.value)
			})
		}),
		/* @__PURE__ */ jsx(Field, {
			label: "Country",
			error: errors.country,
			hint: "Two-letter country code.",
			children: ({ id, describedBy, invalid }) => /* @__PURE__ */ jsx(Input, {
				id,
				autoComplete: "country",
				maxLength: 2,
				"aria-describedby": describedBy,
				invalid,
				value: data.country,
				onChange: (event) => set("country", event.target.value.toUpperCase())
			})
		}),
		/* @__PURE__ */ jsx(Field, {
			label: "Phone (optional)",
			error: errors.phone,
			children: ({ id, describedBy, invalid }) => /* @__PURE__ */ jsx(Input, {
				id,
				autoComplete: "tel",
				"aria-describedby": describedBy,
				invalid,
				value: data.phone,
				onChange: (event) => set("phone", event.target.value)
			})
		})
	] });
}
function newKey() {
	if (typeof crypto !== "undefined" && "randomUUID" in crypto) return crypto.randomUUID().replace(/-/g, "");
	return `k${Date.now()}${Math.random().toString(36).slice(2, 10)}`;
}
//#endregion
//#region resources/js/storefront/components/PaymentPanel.tsx
var csrf = () => document.querySelector("meta[name=\"csrf-token\"]")?.content ?? "";
/** Stripe's own loader is cached per key: never call it twice for one. */
var stripeCache = /* @__PURE__ */ new Map();
function stripeFor(key) {
	const cached = stripeCache.get(key);
	if (cached) return cached;
	const loading = loadStripe(key);
	stripeCache.set(key, loading);
	return loading;
}
/**
* The card form, and the machinery that refuses to believe it.
*
* The important part of this component is what it does after Stripe says
* a payment succeeded: nothing. It polls the platform's own status
* endpoint and shows what that says. A confirmation rendered from
* Stripe's client-side result would be a claim built on a value a
* customer can rewrite in a console, and the moment it is wrong is the
* moment a marketplace ships goods for free.
*
* So there are two clocks here. Stripe's, which tells the customer their
* card details were accepted, and the platform's, which tells them their
* order is paid — and only the second one produces the word "confirmed".
*/
function PaymentPanel({ payment, endpoints, reference }) {
	const [state, setState] = useState(payment);
	const [prepared, setPrepared] = useState(null);
	const [stripe, setStripe] = useState(null);
	const [problem, setProblem] = useState(null);
	const [preparing, setPreparing] = useState(false);
	const [waiting, setWaiting] = useState(() => payment.state === "processing" || typeof window !== "undefined" && window.location.search.includes("payment_intent"));
	const poll = useRef(null);
	const readStatus = useCallback(async () => {
		try {
			const response = await fetch(endpoints.status, { headers: { Accept: "application/json" } });
			if (!response.ok) return null;
			const body = await response.json();
			setState(body.payment);
			return body.payment;
		} catch {
			return null;
		}
	}, [endpoints.status]);
	/**
	* Ask the server, repeatedly, until it has something terminal to say.
	*
	* The webhook that decides the outcome arrives on its own schedule, so
	* the page waits rather than guessing. It gives up after two minutes
	* and says so plainly instead of spinning forever — the order is still
	* held, and the customer can reload.
	*/
	const runPolling = useCallback(() => {
		let elapsed = 0;
		const tick = async () => {
			const next = await readStatus();
			elapsed += 2;
			if (next?.isPaid || next?.state === "failed" || next?.state === "cancelled") {
				setWaiting(false);
				return;
			}
			if (elapsed >= 120) {
				setWaiting(false);
				setProblem("We have not heard back yet. Your order is still held — reload this page in a moment, and do not pay again.");
				return;
			}
			poll.current = window.setTimeout(() => void tick(), 2e3);
		};
		poll.current = window.setTimeout(() => void tick(), 1e3);
	}, [readStatus]);
	const waitForOutcome = useCallback(() => {
		setWaiting(true);
		runPolling();
	}, [runPolling]);
	useEffect(() => {
		if (!waiting) return;
		runPolling();
		return () => {
			if (poll.current !== null) window.clearTimeout(poll.current);
		};
	}, []);
	const prepare = useCallback(async () => {
		setPreparing(true);
		setProblem(null);
		try {
			const response = await fetch(endpoints.prepare, {
				method: "POST",
				headers: {
					"Content-Type": "application/json",
					Accept: "application/json",
					"X-CSRF-TOKEN": csrf()
				},
				body: "{}"
			});
			const body = await response.json();
			if (body.payment) setState(body.payment);
			if (!response.ok) {
				setProblem(body.message ?? "Payment could not be started. Please try again.");
				return;
			}
			const ready = body;
			setPrepared(ready);
			if (ready.provider === "stripe" && ready.publishableKey && ready.clientSecret) setStripe(stripeFor(ready.publishableKey));
		} catch {
			setProblem("We could not reach the payment service. Your order and items are still held — please try again in a moment.");
		} finally {
			setPreparing(false);
		}
	}, [endpoints.prepare]);
	if (state.isPaid) return /* @__PURE__ */ jsx(Outcome, {
		tone: "settled",
		state,
		reference
	});
	if (!state.canPay) return /* @__PURE__ */ jsx(Outcome, {
		tone: "closed",
		state,
		reference
	});
	return /* @__PURE__ */ jsxs("section", {
		"aria-labelledby": "payment-heading",
		className: "border-2 border-[var(--vc-text)] p-6",
		children: [
			/* @__PURE__ */ jsx("h2", {
				id: "payment-heading",
				className: "mb-1 text-[22px]",
				children: state.canRetry ? "Try another payment method" : "Pay for this order"
			}),
			/* @__PURE__ */ jsx("p", {
				role: "status",
				className: "mb-5 text-[14px] text-[var(--vc-neutral-700)]",
				children: waiting ? "Checking with your bank…" : state.detail
			}),
			problem ? /* @__PURE__ */ jsx("p", {
				role: "alert",
				className: "mb-5 border-2 border-[var(--vc-accent)] px-4 py-3 text-[14px]",
				children: problem
			}) : null,
			prepared && stripe && prepared.clientSecret ? /* @__PURE__ */ jsx(Elements, {
				stripe,
				options: {
					clientSecret: prepared.clientSecret,
					appearance: { variables: {
						borderRadius: "0px",
						fontFamily: "inherit"
					} }
				},
				children: /* @__PURE__ */ jsx(CardForm, {
					amount: prepared.amount.formatted,
					returnUrl: prepared.returnUrl,
					onSubmitted: waitForOutcome,
					onProblem: setProblem,
					disabled: waiting
				})
			}) : /* @__PURE__ */ jsxs(Fragment, { children: [prepared && !prepared.clientSecret ? /* @__PURE__ */ jsx("p", {
				className: "mb-4 text-[14px]",
				children: "Card payments are not configured for this environment."
			}) : null, /* @__PURE__ */ jsx(Button, {
				variant: "primary",
				block: true,
				loading: preparing,
				loadingLabel: "Preparing payment…",
				onClick: () => void prepare(),
				children: state.canRetry ? "Try again" : "Continue to payment"
			})] })
		]
	});
}
/**
* The card fields, and the one button that talks to Stripe.
*
* `redirect: 'if_required'` keeps the customer on the page unless their
* bank insists on a challenge. Either way the result Stripe hands back is
* used for one thing only — deciding whether to show an error — and the
* order's status comes from the poll that follows.
*/
function CardForm({ amount, returnUrl, onSubmitted, onProblem, disabled }) {
	const stripe = useStripe();
	const elements = useElements();
	const [submitting, setSubmitting] = useState(false);
	const submit = async () => {
		if (!stripe || !elements) return;
		setSubmitting(true);
		const result = await stripe.confirmPayment({
			elements,
			confirmParams: { return_url: returnUrl },
			redirect: "if_required"
		});
		setSubmitting(false);
		if (result.error) {
			onProblem(result.error.message ?? "Those payment details could not be used.");
			return;
		}
		onSubmitted();
	};
	return /* @__PURE__ */ jsxs("form", {
		onSubmit: (event) => {
			event.preventDefault();
			submit();
		},
		children: [
			/* @__PURE__ */ jsx("div", {
				className: "mb-5",
				children: /* @__PURE__ */ jsx(PaymentElement, {})
			}),
			/* @__PURE__ */ jsx(Button, {
				type: "submit",
				variant: "primary",
				block: true,
				loading: submitting || disabled,
				loadingLabel: "Confirming with your bank…",
				disabled: !stripe,
				children: `Pay ${amount}`
			}),
			/* @__PURE__ */ jsx("p", {
				className: "mt-3 text-[13px] text-[var(--vc-neutral-600)]",
				children: "Your card details go straight to our payment provider. This shop never sees or stores them."
			})
		]
	});
}
function Outcome({ tone, state, reference }) {
	return /* @__PURE__ */ jsxs("section", {
		role: "status",
		className: ["border-2 p-6", tone === "settled" ? "border-[var(--vc-text)]" : "border-[var(--vc-neutral-400)]"].join(" "),
		children: [
			/* @__PURE__ */ jsx("h2", {
				className: "mb-1 text-[22px]",
				children: state.headline
			}),
			/* @__PURE__ */ jsx("p", {
				className: "text-[14px] text-[var(--vc-neutral-700)]",
				children: state.detail
			}),
			tone === "settled" ? /* @__PURE__ */ jsx("p", {
				className: "mt-4 text-[13px]",
				children: /* @__PURE__ */ jsx("a", {
					href: `/account/orders/${reference}`,
					className: "underline underline-offset-4",
					children: "Track this order"
				})
			}) : null
		]
	});
}
//#endregion
//#region resources/js/storefront/pages/Checkout/PaymentPending.tsx
var PaymentPending_exports = /* @__PURE__ */ __exportAll({ default: () => PaymentPending });
/**
* Where a customer pays, and where they find out whether it worked.
*
* Deliberately not a confirmation page until it has earned the word. The
* order exists, the totals are final and the stock is held; whether money
* moved is a separate fact, and it arrives from the server rather than
* from the payment form. Saying "thank you for your order" a moment early
* is a claim the platform cannot support, and the customer would discover
* it was untrue at the least convenient possible moment.
*
* The heading follows the same rule: it reads from the server's state, so
* a paid order does not sit under the word "Payment".
*/
function PaymentPending() {
	const { order, payment, endpoints } = usePage().props;
	return /* @__PURE__ */ jsxs(StorefrontLayout, {
		title: `Order ${order.reference}`,
		children: [
			/* @__PURE__ */ jsxs("p", {
				className: "mb-2 text-[13px] tracking-[0.08em] text-[var(--vc-neutral-600)] uppercase",
				children: ["Order ", order.reference]
			}),
			/* @__PURE__ */ jsx("h1", {
				className: "mb-3 text-[42px]",
				children: payment.isPaid ? "Order confirmed" : "Payment"
			}),
			/* @__PURE__ */ jsxs("div", {
				className: "mb-10",
				children: [/* @__PURE__ */ jsx(PaymentPanel, {
					payment,
					endpoints,
					reference: order.reference
				}), order.paymentExpiresAt && !payment.isPaid ? /* @__PURE__ */ jsxs("p", {
					className: "mt-3 text-[13px] text-[var(--vc-neutral-700)]",
					children: [
						"Your items are held until",
						" ",
						/* @__PURE__ */ jsx("time", {
							dateTime: order.paymentExpiresAt,
							className: "font-semibold",
							children: new Date(order.paymentExpiresAt).toLocaleString()
						}),
						"."
					]
				}) : null]
			}),
			/* @__PURE__ */ jsxs("div", {
				className: "grid gap-14 lg:grid-cols-[1fr_320px]",
				children: [/* @__PURE__ */ jsxs("div", { children: [/* @__PURE__ */ jsx("h2", {
					className: "mb-4 text-[22px]",
					children: "What you ordered"
				}), order.sellerOrders.map((sellerOrder) => /* @__PURE__ */ jsxs("section", {
					className: "mb-8",
					children: [/* @__PURE__ */ jsxs("div", {
						className: "mb-2 flex flex-wrap items-baseline gap-x-3 gap-y-1",
						children: [
							/* @__PURE__ */ jsx("h3", {
								className: "text-[16px]",
								children: sellerOrder.storeName ?? "Seller"
							}),
							/* @__PURE__ */ jsx("span", {
								className: "vc-tabular text-[12px] text-[var(--vc-neutral-600)]",
								children: sellerOrder.reference
							}),
							/* @__PURE__ */ jsx(StatusBadge, {
								domain: "seller_order",
								value: sellerOrder.status
							})
						]
					}), /* @__PURE__ */ jsx("ul", {
						className: "border-t border-[var(--vc-divider)]",
						children: sellerOrder.items.map((item) => /* @__PURE__ */ jsxs("li", {
							className: "flex justify-between gap-4 border-b border-[var(--vc-divider)] py-3 text-[14px]",
							children: [/* @__PURE__ */ jsxs("span", { children: [
								item.productTitle,
								item.variantName ? ` — ${item.variantName}` : "",
								/* @__PURE__ */ jsxs("span", {
									className: "block text-[12px] text-[var(--vc-neutral-600)]",
									children: [
										item.quantity,
										" × ",
										item.unitPrice.formatted
									]
								})
							] }), /* @__PURE__ */ jsx("span", {
								className: "vc-tabular whitespace-nowrap",
								children: item.lineTotal.formatted
							})]
						}, item.publicId))
					})]
				}, sellerOrder.reference))] }), /* @__PURE__ */ jsxs("aside", {
					className: "flex flex-col gap-8",
					children: [
						/* @__PURE__ */ jsxs("div", { children: [/* @__PURE__ */ jsx("h2", {
							className: "mb-3 text-[20px]",
							children: "Total"
						}), /* @__PURE__ */ jsx(OrderTotals, {
							itemsTotal: order.itemsTotal,
							shippingTotal: order.shippingTotal,
							taxTotal: order.taxTotal,
							grandTotal: order.grandTotal,
							taxNote: "Tax is not calculated at this stage."
						})] }),
						/* @__PURE__ */ jsx(AddressBlock, {
							address: order.shippingAddress,
							title: "Delivering to"
						}),
						/* @__PURE__ */ jsx("p", {
							className: "text-[13px] text-[var(--vc-neutral-700)]",
							children: /* @__PURE__ */ jsx(Link, {
								href: "/account/orders",
								className: "underline underline-offset-4",
								children: "See all your orders"
							})
						})
					]
				})]
			})
		]
	});
}
//#endregion
//#region resources/js/storefront/pages/Home.tsx
var Home_exports = /* @__PURE__ */ __exportAll({ default: () => Home });
/**
* The storefront shell.
*
* M0 delivers structure only — the catalogue rails arrive in M2. What is
* real here is the layout, the density, the tokens and the SSR path.
*/
function Home() {
	const { stats, platform } = usePage().props;
	return /* @__PURE__ */ jsxs(StorefrontLayout, { children: [
		/* @__PURE__ */ jsxs("p", {
			className: "mb-4 text-[11px] font-semibold tracking-[0.11em] text-[var(--vc-accent-700)] uppercase",
			children: [stats.sellers, " independent sellers · one checkout"]
		}),
		/* @__PURE__ */ jsx("h1", {
			className: "mb-5 max-w-[14ch] text-[60px] leading-[1.02]",
			children: "Everything, from people who make it."
		}),
		/* @__PURE__ */ jsxs("p", {
			className: "mb-7 max-w-[58ch] text-[17px] text-[var(--vc-neutral-700)]",
			children: [platform.name, " is a marketplace of independent stores — listed and shipped by the sellers themselves, bought in one cart."]
		}),
		/* @__PURE__ */ jsxs("div", {
			className: "mb-14 flex flex-wrap gap-2",
			children: [/* @__PURE__ */ jsx(Button, {
				variant: "primary",
				children: "Browse everything"
			}), /* @__PURE__ */ jsx(Button, {
				variant: "secondary",
				children: "Meet the sellers"
			})]
		}),
		/* @__PURE__ */ jsx("hr", { className: "mb-8 border-0 border-t-2 border-[var(--vc-text)]" }),
		/* @__PURE__ */ jsx("h2", {
			className: "mb-6 text-[32px]",
			children: "Featured this week"
		}),
		stats.products === 0 ? /* @__PURE__ */ jsx(EmptyState, {
			title: "The catalogue is empty",
			body: "No offers have been published yet. Sellers list products from the seller portal, and they appear here once approved.",
			actions: /* @__PURE__ */ jsx(Button, {
				variant: "secondary",
				children: "Apply to sell"
			})
		}) : /* @__PURE__ */ jsx(CardGridSkeleton, { count: 8 })
	] });
}
//#endregion
//#region resources/js/storefront/pages/Product/Show.tsx
var Show_exports$1 = /* @__PURE__ */ __exportAll({ default: () => Show$1 });
/**
* The canonical product page.
*
* Product photography is full colour — the monochrome of the design system
* is chrome, never the goods. Cart is M4, so the action is honestly
* disabled rather than pretending to work.
*/
function Show$1() {
	const { product, breadcrumbs, media, specifications, variants, offers, priceRange, seo, structuredData } = usePage().props;
	const [selectedImage, setSelectedImage] = useState(0);
	const [selectedVariant, setSelectedVariant] = useState(null);
	const visibleOffers = selectedVariant ? offers.filter((offer) => offer.variantPublicId === selectedVariant) : offers;
	return /* @__PURE__ */ jsxs(StorefrontLayout, {
		title: seo.title,
		children: [
			/* @__PURE__ */ jsxs(Head, { children: [
				/* @__PURE__ */ jsx("meta", {
					name: "description",
					content: seo.description
				}),
				/* @__PURE__ */ jsx("meta", {
					name: "robots",
					content: seo.robots
				}),
				/* @__PURE__ */ jsx("link", {
					rel: "canonical",
					href: seo.canonical
				}),
				/* @__PURE__ */ jsx("meta", {
					property: "og:title",
					content: seo.ogTitle
				}),
				/* @__PURE__ */ jsx("meta", {
					property: "og:description",
					content: seo.description
				}),
				/* @__PURE__ */ jsx("meta", {
					property: "og:type",
					content: seo.ogType
				}),
				/* @__PURE__ */ jsx("meta", {
					property: "og:url",
					content: seo.ogUrl
				}),
				seo.ogImage ? /* @__PURE__ */ jsx("meta", {
					property: "og:image",
					content: seo.ogImage
				}) : null
			] }),
			/* @__PURE__ */ jsx(StructuredData, { documents: structuredData }),
			/* @__PURE__ */ jsx("nav", {
				"aria-label": "Breadcrumb",
				className: "mb-6 text-[13px] text-[var(--vc-neutral-600)]",
				children: /* @__PURE__ */ jsx("ol", {
					className: "flex flex-wrap items-center gap-2",
					children: breadcrumbs.map((crumb, index) => /* @__PURE__ */ jsxs("li", {
						className: "flex items-center gap-2",
						children: [index < breadcrumbs.length - 1 ? /* @__PURE__ */ jsx(Link, {
							href: crumb.url,
							className: "underline underline-offset-4",
							children: crumb.name
						}) : /* @__PURE__ */ jsx("span", {
							"aria-current": "page",
							children: crumb.name
						}), index < breadcrumbs.length - 1 ? /* @__PURE__ */ jsx("span", {
							"aria-hidden": "true",
							children: "/"
						}) : null]
					}, crumb.url))
				})
			}),
			/* @__PURE__ */ jsxs("div", {
				className: "grid gap-10 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]",
				children: [/* @__PURE__ */ jsx("section", {
					"aria-label": "Product images",
					children: media.length === 0 ? /* @__PURE__ */ jsx("div", {
						className: "flex aspect-square items-center justify-center border-2 border-[var(--vc-divider)] text-[13px] text-[var(--vc-neutral-600)]",
						children: "No photographs yet"
					}) : /* @__PURE__ */ jsxs(Fragment, { children: [/* @__PURE__ */ jsx("img", {
						src: media[selectedImage]?.url ?? media[0]?.url,
						alt: media[selectedImage]?.alt ?? product.title,
						width: media[selectedImage]?.width ?? void 0,
						height: media[selectedImage]?.height ?? void 0,
						className: "aspect-square w-full bg-[var(--vc-surface)] object-contain"
					}), media.length > 1 ? /* @__PURE__ */ jsx("ul", {
						className: "mt-3 flex flex-wrap gap-2",
						children: media.map((image, index) => /* @__PURE__ */ jsx("li", { children: /* @__PURE__ */ jsx("button", {
							type: "button",
							"aria-label": `Show image ${index + 1} of ${media.length}`,
							"aria-current": index === selectedImage,
							onClick: () => setSelectedImage(index),
							className: ["h-[64px] w-[64px] border-2 p-[2px]", index === selectedImage ? "border-[var(--vc-text)]" : "border-transparent hover:border-[var(--vc-neutral-400)]"].join(" "),
							children: /* @__PURE__ */ jsx("img", {
								src: image.url,
								alt: "",
								className: "h-full w-full object-contain"
							})
						}) }, image.url))
					}) : null] })
				}), /* @__PURE__ */ jsxs("section", { children: [
					product.brand ? /* @__PURE__ */ jsx("p", {
						className: "mb-1 text-[13px] tracking-[0.08em] text-[var(--vc-neutral-600)] uppercase",
						children: product.brand.name
					}) : null,
					/* @__PURE__ */ jsx("h1", {
						className: "mb-4 text-[38px] leading-[1.1]",
						children: product.title
					}),
					priceRange ? /* @__PURE__ */ jsxs("p", {
						className: "vc-tabular mb-6 text-[28px] font-extrabold",
						children: [priceRange.isSingle ? priceRange.from : `${priceRange.from} – ${priceRange.to}`, /* @__PURE__ */ jsxs("span", {
							className: "ml-2 align-middle text-[13px] font-normal text-[var(--vc-neutral-600)]",
							children: [
								"from ",
								offers.length,
								" ",
								offers.length === 1 ? "seller" : "sellers"
							]
						})]
					}) : null,
					product.description ? /* @__PURE__ */ jsx("p", {
						className: "mb-6 max-w-[62ch] text-[var(--vc-neutral-700)]",
						children: product.description
					}) : null,
					variants.length > 0 ? /* @__PURE__ */ jsxs("fieldset", {
						className: "mb-6",
						children: [/* @__PURE__ */ jsx("legend", {
							className: "mb-2 text-[12px] text-[var(--vc-neutral-700)]",
							children: "Choose an option"
						}), /* @__PURE__ */ jsx("div", {
							className: "flex flex-wrap gap-2",
							children: variants.map((variant) => /* @__PURE__ */ jsxs("button", {
								type: "button",
								disabled: !variant.hasOffer,
								"aria-pressed": selectedVariant === variant.publicId,
								onClick: () => setSelectedVariant(selectedVariant === variant.publicId ? null : variant.publicId),
								className: [
									"min-h-[44px] border-2 px-4 py-2 text-[14px]",
									selectedVariant === variant.publicId ? "border-[var(--vc-text)] bg-[var(--vc-surface)]" : "border-[var(--vc-divider)]",
									variant.hasOffer ? "hover:border-[var(--vc-text)]" : "cursor-not-allowed opacity-45"
								].join(" "),
								children: [variant.name, variant.hasOffer ? "" : " — unavailable"]
							}, variant.publicId))
						})]
					}) : null,
					/* @__PURE__ */ jsx("h2", {
						className: "mb-3 text-[20px]",
						children: visibleOffers.length === 1 ? "Seller" : "Sellers"
					}),
					visibleOffers.length === 0 ? /* @__PURE__ */ jsx(EmptyState, {
						title: "No seller is listing this right now",
						body: "Nobody currently offers this product. It stays in the catalogue, so a listing can appear again at any time."
					}) : /* @__PURE__ */ jsx("ul", {
						className: "border-t-2 border-[var(--vc-text)]",
						children: visibleOffers.map((offer) => /* @__PURE__ */ jsxs("li", {
							className: "flex flex-wrap items-center gap-4 border-b border-[var(--vc-divider)] py-4",
							children: [/* @__PURE__ */ jsxs("span", {
								className: "flex-1",
								children: [/* @__PURE__ */ jsx("span", {
									className: "vc-tabular block text-[20px] font-bold",
									children: offer.price
								}), /* @__PURE__ */ jsxs("span", {
									className: "block text-[13px] text-[var(--vc-neutral-600)]",
									children: [
										offer.conditionLabel,
										" ·",
										" ",
										offer.seller.storeSlug ? /* @__PURE__ */ jsx(Link, {
											href: `/stores/${offer.seller.storeSlug}`,
											className: "underline underline-offset-4",
											children: offer.seller.storeName
										}) : offer.seller.storeName,
										" ",
										"· dispatches in ",
										offer.handlingDays,
										" ",
										offer.handlingDays === 1 ? "day" : "days"
									]
								})]
							}), /* @__PURE__ */ jsx(Button, {
								variant: "secondary",
								disabled: true,
								title: "Buying opens soon",
								children: "Buying opens soon"
							})]
						}, offer.publicId))
					})
				] })]
			}),
			specifications.length > 0 ? /* @__PURE__ */ jsxs("section", {
				className: "mt-12 max-w-[720px]",
				children: [/* @__PURE__ */ jsx("h2", {
					className: "mb-4 text-[22px]",
					children: "Specifications"
				}), /* @__PURE__ */ jsx("dl", {
					className: "border-t-2 border-[var(--vc-text)]",
					children: specifications.map((specification) => /* @__PURE__ */ jsxs("div", {
						className: "flex gap-6 border-b border-[var(--vc-divider)] py-3 text-[14px]",
						children: [/* @__PURE__ */ jsx("dt", {
							className: "w-[220px] shrink-0 text-[var(--vc-neutral-600)]",
							children: specification.name
						}), /* @__PURE__ */ jsx("dd", { children: specification.value })]
					}, specification.name))
				})]
			}) : null
		]
	});
}
//#endregion
//#region resources/js/storefront/pages/Search/Index.tsx
var Index_exports = /* @__PURE__ */ __exportAll({ default: () => Index });
/**
* Customer search.
*
* The URL is the state. Everything — the query, the filters, the sort, the
* page — round-trips through it, so a result set can be shared and
* reloaded, and the server does the searching. It is deliberately
* noindex: a search URL records what one person typed once.
*/
function Index() {
	const { results, facets, applied, sorts, suggestion, seo } = usePage().props;
	const [query, setQuery] = useState(applied.q);
	const recordClick = (product, position) => {
		fetch("/search/click", {
			method: "POST",
			headers: {
				"Content-Type": "application/json",
				"X-CSRF-TOKEN": document.querySelector("meta[name=\"csrf-token\"]")?.content ?? ""
			},
			body: JSON.stringify({
				product: product.slug,
				position,
				query: applied.q
			}),
			keepalive: true
		}).catch(() => {});
	};
	return /* @__PURE__ */ jsxs(StorefrontLayout, {
		title: seo.title,
		children: [
			/* @__PURE__ */ jsxs(Head, { children: [/* @__PURE__ */ jsx("meta", {
				name: "robots",
				content: seo.robots
			}), /* @__PURE__ */ jsx("link", {
				rel: "canonical",
				href: seo.canonical
			})] }),
			/* @__PURE__ */ jsxs("form", {
				className: "mb-8 flex max-w-[560px] items-end gap-3",
				onSubmit: (event) => {
					event.preventDefault();
					router.get("/search", { q: query });
				},
				children: [/* @__PURE__ */ jsx("div", {
					className: "flex-1",
					children: /* @__PURE__ */ jsx(Field, {
						label: "Search the catalogue",
						children: ({ id }) => /* @__PURE__ */ jsx(Input, {
							id,
							type: "search",
							value: query,
							onChange: (event) => setQuery(event.target.value)
						})
					})
				}), /* @__PURE__ */ jsx(Button, {
					type: "submit",
					variant: "primary",
					children: "Search"
				})]
			}),
			applied.q !== "" ? /* @__PURE__ */ jsxs("p", {
				className: "mb-6 text-[15px]",
				children: [
					/* @__PURE__ */ jsx("span", {
						className: "vc-tabular",
						children: results.total
					}),
					" ",
					results.total === 1 ? "result" : "results",
					" for",
					" ",
					/* @__PURE__ */ jsxs("span", {
						className: "font-semibold",
						children: [
							"“",
							applied.q,
							"”"
						]
					})
				]
			}) : null,
			results.total === 0 ? /* @__PURE__ */ jsxs("div", {
				className: "max-w-[62ch]",
				children: [
					/* @__PURE__ */ jsx(EmptyState, {
						title: applied.q === "" ? "Search the catalogue" : "Nothing matched",
						body: applied.q === "" ? "Type what you are looking for. You can also browse by category." : "No products matched that search."
					}),
					suggestion ? /* @__PURE__ */ jsxs("p", {
						className: "mt-4 text-[15px]",
						children: [
							"Did you mean",
							" ",
							/* @__PURE__ */ jsx(Link, {
								href: `/search?q=${encodeURIComponent(suggestion)}`,
								className: "font-semibold underline underline-offset-4",
								children: suggestion
							}),
							"?"
						]
					}) : null,
					applied.hasFilters ? /* @__PURE__ */ jsxs("p", {
						className: "mt-4 text-[15px]",
						children: [
							"Your filters may be the reason.",
							" ",
							/* @__PURE__ */ jsx(Link, {
								href: `/search?q=${encodeURIComponent(applied.q)}`,
								className: "font-semibold underline underline-offset-4",
								children: "Search without them"
							}),
							"."
						]
					}) : null
				]
			}) : /* @__PURE__ */ jsxs("div", {
				className: "grid gap-10 lg:grid-cols-[240px_minmax(0,1fr)]",
				children: [/* @__PURE__ */ jsx(DiscoveryFilters, {
					url: "/search",
					facets,
					applied
				}), /* @__PURE__ */ jsxs("div", { children: [
					/* @__PURE__ */ jsx("div", {
						className: "mb-6 flex justify-end",
						children: /* @__PURE__ */ jsx("div", {
							className: "min-w-[200px]",
							children: /* @__PURE__ */ jsx(SortSelect, {
								url: "/search",
								applied,
								sorts
							})
						})
					}),
					/* @__PURE__ */ jsx(ProductGrid, {
						products: results.data,
						onSelect: recordClick
					}),
					/* @__PURE__ */ jsx(Pagination, {
						url: "/search",
						applied,
						page: results.page,
						lastPage: results.lastPage
					})
				] })]
			})
		]
	});
}
//#endregion
//#region resources/js/storefront/pages/Store/Show.tsx
var Show_exports = /* @__PURE__ */ __exportAll({ default: () => Show });
/**
* The public store page.
*
* The grid is the same discovery engine, cards and sorting as search and
* category pages, scoped to this seller — so a product cannot appear here
* on different terms from the rest of the site. Another seller's offer has
* no path into this listing: the scope is applied in the query, not
* filtered afterwards.
*/
function Show() {
	const { store, results, applied, sorts, seo } = usePage().props;
	const url = `/stores/${store.slug}`;
	return /* @__PURE__ */ jsxs(StorefrontLayout, {
		title: seo.title,
		children: [
			/* @__PURE__ */ jsxs(Head, { children: [
				/* @__PURE__ */ jsx("meta", {
					name: "description",
					content: seo.description
				}),
				/* @__PURE__ */ jsx("meta", {
					name: "robots",
					content: seo.robots
				}),
				/* @__PURE__ */ jsx("link", {
					rel: "canonical",
					href: seo.canonical
				}),
				/* @__PURE__ */ jsx("meta", {
					property: "og:title",
					content: seo.ogTitle
				}),
				/* @__PURE__ */ jsx("meta", {
					property: "og:description",
					content: seo.description
				}),
				/* @__PURE__ */ jsx("meta", {
					property: "og:type",
					content: seo.ogType
				}),
				/* @__PURE__ */ jsx("meta", {
					property: "og:url",
					content: seo.ogUrl
				})
			] }),
			/* @__PURE__ */ jsxs("header", {
				className: "mb-10 border-b-2 border-[var(--vc-text)] pb-8",
				children: [
					/* @__PURE__ */ jsx("h1", {
						className: "mb-2 text-[38px]",
						children: store.name
					}),
					/* @__PURE__ */ jsxs("p", {
						className: "text-[13px] text-[var(--vc-neutral-600)]",
						children: [
							"/stores/",
							store.slug,
							store.shipsFrom ? ` · ships from ${store.shipsFrom}` : ""
						]
					}),
					store.description ? /* @__PURE__ */ jsx("p", {
						className: "mt-5 max-w-[62ch] text-[var(--vc-neutral-700)]",
						children: store.description
					}) : null,
					!store.isOpen ? /* @__PURE__ */ jsx("p", {
						className: "mt-5 border-2 border-[var(--vc-divider)] px-4 py-3 text-[14px]",
						children: "This store is not taking orders at the moment."
					}) : null
				]
			}),
			/* @__PURE__ */ jsxs("div", {
				className: "grid gap-10 md:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]",
				children: [/* @__PURE__ */ jsxs("section", { children: [
					/* @__PURE__ */ jsxs("div", {
						className: "mb-5 flex flex-wrap items-end justify-between gap-4",
						children: [/* @__PURE__ */ jsxs("h2", {
							className: "text-[24px]",
							children: ["Products", results.total > 0 ? /* @__PURE__ */ jsx("span", {
								className: "vc-tabular ml-2 text-[15px] text-[var(--vc-neutral-600)]",
								children: results.total
							}) : null]
						}), results.total > 1 ? /* @__PURE__ */ jsx("div", {
							className: "min-w-[200px]",
							children: /* @__PURE__ */ jsx(SortSelect, {
								url,
								applied,
								sorts
							})
						}) : null]
					}),
					results.data.length === 0 ? /* @__PURE__ */ jsx(EmptyState, {
						title: "No products listed yet",
						body: "This seller has not published anything to the marketplace catalogue. Their listings will appear here once they do."
					}) : /* @__PURE__ */ jsx(ProductGrid, { products: results.data }),
					/* @__PURE__ */ jsx(Pagination, {
						url,
						applied,
						page: results.page,
						lastPage: results.lastPage
					})
				] }), /* @__PURE__ */ jsxs("aside", {
					className: "flex flex-col gap-6 text-[14px]",
					children: [
						store.shippingPolicy ? /* @__PURE__ */ jsxs("div", { children: [/* @__PURE__ */ jsx("h2", {
							className: "mb-2 text-[16px]",
							children: "Shipping"
						}), /* @__PURE__ */ jsx("p", {
							className: "text-[var(--vc-neutral-700)]",
							children: store.shippingPolicy
						})] }) : null,
						store.returnPolicy ? /* @__PURE__ */ jsxs("div", { children: [/* @__PURE__ */ jsx("h2", {
							className: "mb-2 text-[16px]",
							children: "Returns"
						}), /* @__PURE__ */ jsx("p", {
							className: "text-[var(--vc-neutral-700)]",
							children: store.returnPolicy
						})] }) : null,
						store.supportEmail ? /* @__PURE__ */ jsxs("div", { children: [/* @__PURE__ */ jsx("h2", {
							className: "mb-2 text-[16px]",
							children: "Contact"
						}), /* @__PURE__ */ jsx("p", {
							className: "text-[var(--vc-neutral-700)]",
							children: store.supportEmail
						})] }) : null
					]
				})]
			})
		]
	});
}
//#endregion
//#region resources/js/storefront/ssr.tsx
/**
* Server-side rendering, storefront only.
*
* Crawlers and first paint get complete HTML — the SEO requirement in the
* specification. The seller and admin portals are behind auth and never
* crawled, so they skip SSR entirely and halve the runtime surface.
*/
createServer((page) => createInertiaApp({
	page,
	render: ReactDOMServer.renderToString,
	resolve: (name) => {
		return (/* @__PURE__ */ Object.assign({
			"./pages/Account/Orders/Index.tsx": Index_exports$3,
			"./pages/Account/Orders/Show.tsx": Show_exports$3,
			"./pages/Account/Profile.tsx": Profile_exports,
			"./pages/Auth/ForgotPassword.tsx": ForgotPassword_exports,
			"./pages/Auth/Login.tsx": Login_exports,
			"./pages/Auth/Register.tsx": Register_exports,
			"./pages/Auth/ResetPassword.tsx": ResetPassword_exports,
			"./pages/Auth/VerifyEmail.tsx": VerifyEmail_exports,
			"./pages/Cart/Index.tsx": Index_exports$2,
			"./pages/Category/Show.tsx": Show_exports$2,
			"./pages/Checkout/Index.tsx": Index_exports$1,
			"./pages/Checkout/PaymentPending.tsx": PaymentPending_exports,
			"./pages/Home.tsx": Home_exports,
			"./pages/Product/Show.tsx": Show_exports$1,
			"./pages/Search/Index.tsx": Index_exports,
			"./pages/Store/Show.tsx": Show_exports
		}))[`./pages/${name}.tsx`];
	},
	setup: ({ App, props }) => /* @__PURE__ */ jsx(App, { ...props })
}));
//#endregion
export {};
