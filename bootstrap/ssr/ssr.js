import { Head, Link, createInertiaApp, router, useForm, usePage } from "@inertiajs/react";
import { Fragment, jsx, jsxs } from "react/jsx-runtime";
import { useId, useState } from "react";
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
	const { platform } = usePage().props;
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
			"./pages/Account/Profile.tsx": Profile_exports,
			"./pages/Auth/ForgotPassword.tsx": ForgotPassword_exports,
			"./pages/Auth/Login.tsx": Login_exports,
			"./pages/Auth/Register.tsx": Register_exports,
			"./pages/Auth/ResetPassword.tsx": ResetPassword_exports,
			"./pages/Auth/VerifyEmail.tsx": VerifyEmail_exports,
			"./pages/Category/Show.tsx": Show_exports$2,
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
