# Multi-Vendor Marketplace — Phase 1 Product Specification

A design and development reference for the Phase 1 marketplace pilot. This document
describes the product: what it does, who uses it, the screens and modules involved,
the detailed functionality of each part, and the technology it runs on.

It is intended as a handoff document for UI/UX design (Figma / Claude Design) and, later,
for development.

---

## 1. Overview

A focused multi-vendor marketplace where customers can buy, sellers can sell, and the
platform earns and controls a commission on each sale. It is built as a real, hosted
product with three connected areas, not a set of disconnected demo screens.

### The three parts

| Part | Who uses it | What it does |
|------|-------------|--------------|
| **Customer storefront** | Shoppers | Browse and search products, add to cart, checkout, track orders. |
| **Seller store** | Marketplace sellers | Set up a branded store, list products, manage stock, process orders, track sales and earnings. |
| **Admin portal** | Platform team | Approve sellers and products, set commission, view orders, revenue and payouts. |

### System shape

One application serves three different audiences, sitting behind a CDN/security layer:

```
              Visitors · Sellers · Admin team
                          │
                 Cloudflare (security · CDN · DNS)
        ┌─────────────────┼──────────────────┐
   Customer Storefront   Seller Portal      Admin Panel
   React + TypeScript    React + TypeScript  (control panel)
        └─────────────────┼──────────────────┘
              One backend application
   (accounts · products · inventory · cart · orders ·
    payments · commission · earnings · payouts · emails)
        ┌─────────────────┼──────────────────┐
    PostgreSQL       Cloud object storage   Payments · Email
   (all data)        (product images)       (external services)
```

---

## 2. How the platform earns (commission model)

Phase 1 uses a commission on each sale.

- The admin sets a default commission percentage (changeable at any time in the admin panel).
- When an order is completed, the system records three numbers on that order: **order total**,
  **platform commission**, and **seller earning**.
- The seller sees their sales, the commission deducted, their available balance, and can
  request a payout.
- The admin sees total sales, platform revenue, seller earnings, refunds and payout activity.

**Important data rule:** each amount (order total, commission, seller earning) is stored as
its own record and snapshotted onto the order. History never changes when prices or the
commission rate change later.

### Order-to-revenue flow

```
Customer places order → Payment captured → Order recorded with price snapshot
   → Seller notified, stock reduced → order completed
        → System splits order total using the commission rate saved on that order
             ├─ Platform commission → recorded as revenue → admin dashboard
             └─ Seller earning → added to seller ledger → seller can request payout
```

---

## 3. Detailed functionality

### 3.1 Customer storefront

**Account**
- Register, sign in, reset password, manage profile

**Browsing**
- Home page, categories, product listing and product detail pages
- Seller store pages — each with the seller's branding and their full product range
- Product images, price, stock status, and simple variants such as size or colour
- Search, sorting and basic filters

**Buying**
- Shopping cart
- Shipping address and checkout
- Payment at checkout (test mode for demos)
- Order confirmation, order history and order status tracking

---

### 3.2 Seller store

Each seller runs their own store inside the marketplace — they join, set up their store,
list products, manage stock, handle orders and track earnings without the platform team
doing it for them.

**Store setup**
- Apply to join the marketplace; platform team reviews and approves
- Store name, store web address (slug), logo, banner image and store description
- Business details, contact information, return/shipping policy text
- A public store page showing the seller's branding and all of their products

**Product listing**
- Add, edit, deactivate and archive products
- Product title, description, category, brand, SKU code and condition
- Multiple product images with a chosen main image
- Price and optional compare-at price (to show a discount)
- Variants such as size or colour, each with its own price and stock
- Draft and published states, so a listing can be prepared before going live

**Inventory**
- Stock quantity per product and per variant
- Stock reduced automatically when an order is placed, restored when cancelled
- Manual stock adjustments with a reason recorded
- Full stock movement history, so any change can be traced
- Low-stock and out-of-stock alerts on the seller dashboard

**Orders**
- Receive orders for their own products only
- Order list with filters by status and date, and full order detail
- Update order status through packing, shipped and delivered
- Add carrier name and tracking number, visible to the customer

**Sales and earnings**
- Dashboard with revenue, order count, available balance and recent orders
- Order counts and totals broken down by status
- Commission deducted shown on every order
- Earnings statement listing each amount earned, deducted or paid out
- Request a payout, and see the status and history of past requests

---

### 3.3 Admin portal

- Secure login with role-based permissions
- Dashboard showing sales, platform revenue, orders, customers and sellers
- Approve, reject, suspend or reactivate sellers
- Review and manage seller stores and their products
- Manage categories and brands
- View all orders and their status history
- View payment and refund records
- Set the marketplace commission percentage
- View seller earnings and approve or reject payout requests
- Platform settings such as company information and operational options

---

### 3.4 Behind the scenes (system-wide)

| Capability | What it means in practice |
|------------|----------------------------|
| **Responsive design** | Works properly on desktop, tablet and mobile browsers. |
| **Email notifications** | Account and order events send automatic emails. |
| **Secure roles** | Customers, sellers and admins cannot access each other's areas. |
| **Backups** | Server and database backups configured. |
| **Error monitoring** | Problems in the live system are detected quickly. |
| **Demo data** | Realistic sample sellers, products and orders so dashboards look meaningful. |

---

## 4. Key user journeys (must work end to end)

These are the three journeys the product must complete start to finish. They connect where
noted (an order placed by a customer must reach the right seller; a payout requested by a
seller must reach the admin).

**Customer**
Register → search product → add to cart → checkout → test payment → order confirmation → track order

**Seller**
Apply → get approved → set up store → list products → receive order → ship it → see commission and earnings → request payout

**Admin**
Review seller application → approve seller & products → set commission rate → monitor orders → view revenue & reports → approve payout

---

## 5. Complete marketplace workflow

The full journey from a seller joining through to money being settled, including decision
points and exceptions.

**A. Seller joins**
Seller submits application → admin reviews (approved / rejected → seller notified) → seller
sets up store (name, logo, banner, policies) → public store page goes live → seller dashboard unlocked

**B. Product goes live**
Seller creates listing (title, category, SKU, images) → sets price and variants (size/colour,
each with stock) → sets opening stock (recorded in stock history) → publish or keep as draft
(draft stays hidden) → product visible in search, category and store page

**C. Order is placed**
Customer adds to cart and checks out → payment successful?
- **Failed** → attempt recorded, customer can retry
- **Paid** → order created, prices and commission rate saved onto the order → stock movement
  logged → seller notified, order appears in their portal → seller ships, adds carrier +
  tracking → customer tracks order until delivered
- **Cancelled or refunded** → stock restored, earning reversed

**D. Money is settled**
Order marked complete → order total split using the commission rate saved earlier
- Platform commission → revenue → admin sees total sales & revenue
- Seller earning → added to seller ledger → seller sees balance & statement → seller requests
  payout → admin reviews payout request (approved / rejected → reason recorded) → payout
  recorded, balance reduced

**Data rule across the whole workflow:** every step writes its own permanent record — stock
movements, payment attempts, order status changes, earnings and payouts. Nothing is
overwritten, so history stays accurate even when prices, stock or the commission rate change later.

---

## 6. Technology

Chosen to keep Phase 1 efficient to build while leaving a clear path to scale later. Phase 1
is deliberately one application rather than several separate systems, with the codebase
organized into internal modules.

| Area | Technology | Purpose |
|------|-----------|---------|
| **Application** | Laravel | All marketplace logic — accounts, products, orders, payments, commission and payouts. |
| **User interface** | React + TypeScript (Inertia) | A modern, responsive experience for customers and sellers. |
| **Administration** | Filament | A fast, reliable control panel for the platform team. |
| **Database** | PostgreSQL | Stores all marketplace data with strong accuracy guarantees. |
| **Background processing** | Built-in queue service | Handles emails, notifications and other work without slowing the site. |
| **Storage** | Cloud object storage (Cloudflare R2) | Product images and documents, kept off the main server. |
| **Payments** | Stripe (test mode for pilot) behind a provider-agnostic layer | Payment capture and commission split; other providers can be added without a rebuild. |
| **Hosting** | Cloud server + Cloudflare | Reliable hosting with caching, speed and basic protection. |

### Internal modules

The application is built as separate internal modules inside one deployment:
**users · sellers · catalogue · stock · orders · payments · commission**

### Search engine visibility

The customer storefront is server-rendered, so search engines receive complete page content
on first request. Clean URLs, page titles, descriptions, product structured data and an XML
sitemap are included so product and category pages can be indexed from launch.

### Payment layer

The marketplace is built with a flexible payment layer that does not depend on any single
provider. Stripe Connect is the recommended starting point (handles the commission split,
seller identity checks and automatic seller payouts). Other providers (e.g. PayPal Commerce
Platform, Authorize.net, ACH for payouts, Adyen at higher volume) can be swapped in or added
later without rebuilding the marketplace.

---

## 7. How it grows later (scaling path)

Built as internal modules inside one deployment, so growth is a series of controlled steps
rather than a rebuild.

| When this happens | What is done |
|-------------------|--------------|
| More visitors | Run more copies of the application behind a load balancer. |
| Database gets busy | Move to a managed database with read copies and tuned queries. |
| Large product catalogue | Add a dedicated search engine without changing how customers use the site. |
| Heavy background work | Run queue workers on their own server. |
| One module grows very large | Split just that module into its own service — only when there's a real reason. |
| Storefront traffic grows heavily | Move the storefront to its own dedicated frontend, reusing the same backend. |
| Mobile app demand is proven | Build apps on top of the existing backend. |

---

## 8. Deliberately out of scope for Phase 1

Left out to keep the first build focused. Each can be added once the business model is proven.

| Left for later | Why it can wait |
|----------------|-----------------|
| iPhone and Android apps | A responsive website is enough to prove the marketplace first. |
| Multiple warehouses | Not needed until stock volume and fulfilment become complex. |
| Courier integrations | Phase 1 uses simple shipping status and manual tracking details. |
| Automatic bank payouts | The pilot records payout requests; real bank settlement comes later. |
| Fraud detection engine | Needs real transaction history before it is useful. |
| AI recommendations / chatbot | Useful later, not needed to prove the business works. |
| Microservices / Kubernetes | Adds cost and complexity long before the traffic needs it. |
| Multi-country tax engine | Depends on the legal and tax rules of each market entered. |

### Convenience features deferred to Phase 1.1

| Item | Why it can wait |
|------|-----------------|
| Bulk product upload by spreadsheet | Sellers add products directly during a pilot. |
| Printable packing slips | The order page can be printed in the meantime. |
| Duplicate product shortcut | A convenience for sellers with many similar listings. |
| Product weight and dimensions | Only needed once live shipping rates are calculated. |
| Sales charts over time | The dashboard shows the figures; the graphs follow later. |
| Product reviews and ratings | Needs real customers before it means anything. |
| Wishlist | A convenience feature, not part of the core purchase flow. |
| Discount coupons | A marketing tool, added once the store is trading. |
| Admin activity log | Valuable once several staff use the panel daily. |

---

## 9. Definition of done (Phase 1)

Phase 1 is complete when the agreed scope is live and all of the following can be done
without editing the database by hand:

- A customer can register, find a product, add it to the cart and complete a test checkout.
- The resulting order is visible to the correct seller and to the admin.
- The seller can process the order and see sales, commission and earnings.
- The seller can request a payout and the admin can review it.
- The admin dashboard shows the main marketplace figures.
- Permissions prevent anyone accessing an area they should not.
- The main pages work correctly on modern desktop and mobile browsers.

---

## Screen inventory (for design planning)

A consolidated list of the screens implied by the scope above.

**Customer storefront**
- Home
- Category / product listing (with search, sort, filters)
- Product detail (images, variants, price, stock, add to cart)
- Seller store page (branding + full product range)
- Cart
- Checkout (shipping address + payment)
- Order confirmation
- Account: register / sign in / reset password / profile
- Order history + order detail / tracking

**Seller portal**
- Apply to join
- Seller dashboard (revenue, order count, balance, recent orders, low-stock alerts)
- Store setup / branding (name, slug, logo, banner, description, policies, business details)
- Product list
- Product create / edit (details, images, variants, draft/published)
- Inventory (stock per variant, manual adjustment, movement history)
- Order list (filters) + order detail (status updates, carrier + tracking)
- Earnings statement
- Payout request + payout history

**Admin portal**
- Login
- Dashboard (sales, revenue, orders, customers, sellers)
- Seller management (approve / reject / suspend / reactivate)
- Store & product review
- Categories & brands management
- Orders (all) + order status history
- Payments & refunds
- Commission settings
- Seller earnings + payout request review
- Platform settings
