import { Head, Link, createInertiaApp, useForm, usePage } from "@inertiajs/react";
import { Fragment, jsx, jsxs } from "react/jsx-runtime";
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
//#region resources/js/storefront/pages/Store/Show.tsx
var Show_exports = /* @__PURE__ */ __exportAll({ default: () => Show });
/**
* The public store page.
*
* The catalogue belongs to M2, so the product area carries an honest empty
* state rather than placeholder cards — a page that looks finished but
* shows nothing real is worse than one that says what it is.
*/
function Show() {
	const { store, seo } = usePage().props;
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
				children: [/* @__PURE__ */ jsxs("section", { children: [/* @__PURE__ */ jsx("h2", {
					className: "mb-5 text-[24px]",
					children: "Products"
				}), /* @__PURE__ */ jsx(EmptyState, {
					title: "No products listed yet",
					body: "This seller has not published anything to the marketplace catalogue. Their listings will appear here once they do."
				})] }), /* @__PURE__ */ jsxs("aside", {
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
			"./pages/Home.tsx": Home_exports,
			"./pages/Store/Show.tsx": Show_exports
		}))[`./pages/${name}.tsx`];
	},
	setup: ({ App, props }) => /* @__PURE__ */ jsx(App, { ...props })
}));
//#endregion
export {};
