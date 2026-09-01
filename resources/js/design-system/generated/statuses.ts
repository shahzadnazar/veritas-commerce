// GENERATED FILE — do not edit.
// Source: app/Support/StatusRegistry.php  ·  Regenerate: php artisan statuses:export
//
// Phase 6 consistency review, finding 1: one status→tone mapping for the
// whole product. Storefront, seller and admin all read from here.

export type StatusTone = 'neutral' | 'pending' | 'critical' | 'inactive';

export interface StatusPresentation {
    tone: StatusTone;
    label: string;
}

export const STATUS_PRESENTATION = {
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
} as const satisfies Record<
    string,
    Record<string, StatusPresentation>
>;

export type StatusDomain = keyof typeof STATUS_PRESENTATION;
