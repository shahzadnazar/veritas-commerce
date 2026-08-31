import { Link, createInertiaApp, useForm, usePage } from "@inertiajs/react";
import { jsx, jsxs } from "react/jsx-runtime";
import { useId } from "react";
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
*/
function StorefrontLayout({ children }) {
	const { platform } = usePage().props;
	return /* @__PURE__ */ jsxs("div", {
		"data-density": "comfortable",
		className: "min-h-screen bg-[var(--vc-bg)]",
		children: [
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
								href: "/orders",
								children: "Orders"
							}),
							/* @__PURE__ */ jsx(Link, {
								href: "/cart",
								children: "Cart"
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
//#endregion
//#region resources/js/storefront/pages/Auth/Login.tsx
var Login_exports = /* @__PURE__ */ __exportAll({ default: () => Login });
/**
* Customer sign-in shell.
*
* M0 renders the form and its states; the credential flow is built in M1
* alongside registration, reset and guest-order claiming.
*/
function Login() {
	const form = useForm({
		email: "",
		password: ""
	});
	return /* @__PURE__ */ jsx(StorefrontLayout, { children: /* @__PURE__ */ jsxs("div", {
		className: "max-w-[420px]",
		children: [
			/* @__PURE__ */ jsx("h1", {
				className: "mb-3 text-[44px] leading-[1.05]",
				children: "Welcome back"
			}),
			/* @__PURE__ */ jsx("p", {
				className: "mb-7 text-[var(--vc-neutral-700)]",
				children: "Sign in to track orders, save addresses and check out faster."
			}),
			/* @__PURE__ */ jsxs("form", {
				className: "flex flex-col gap-4",
				onSubmit: (event) => event.preventDefault(),
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
					/* @__PURE__ */ jsx(Button, {
						type: "submit",
						variant: "primary",
						loading: form.processing,
						loadingLabel: "Signing in…",
						children: "Sign in"
					})
				]
			})
		]
	}) });
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
			"./pages/Auth/Login.tsx": Login_exports,
			"./pages/Home.tsx": Home_exports
		}))[`./pages/${name}.tsx`];
	},
	setup: ({ App, props }) => /* @__PURE__ */ jsx(App, { ...props })
}));
//#endregion
export {};
