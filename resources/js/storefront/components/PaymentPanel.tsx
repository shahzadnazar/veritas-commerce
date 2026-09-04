import { Elements, PaymentElement, useElements, useStripe } from '@stripe/react-stripe-js';
import { loadStripe, type Stripe } from '@stripe/stripe-js';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Button } from '../../design-system/primitives/Button';

/**
 * What the platform believes about a payment. Never what the browser
 * believes: this shape only ever arrives from the server.
 */
export interface PaymentState {
    state: string;
    headline: string;
    detail: string;
    canPay: boolean;
    canRetry: boolean;
    isPaid: boolean;
    attemptStatus: string | null;
    attemptLabel: string | null;
    expiresAt: string | null;
}

interface PreparedPayment {
    provider: string;
    publishableKey: string;
    clientSecret: string | null;
    attemptPublicId: string;
    amount: { minor: number; currency: string; formatted: string };
    returnUrl: string;
    payment: PaymentState;
}

interface Endpoints {
    prepare: string;
    status: string;
}

const csrf = () =>
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

/** Stripe's own loader is cached per key: never call it twice for one. */
const stripeCache = new Map<string, Promise<Stripe | null>>();

function stripeFor(key: string): Promise<Stripe | null> {
    const cached = stripeCache.get(key);

    if (cached) {
        return cached;
    }

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
export function PaymentPanel({
    payment,
    endpoints,
    reference,
}: {
    payment: PaymentState;
    endpoints: Endpoints;
    reference: string;
}) {
    const [state, setState] = useState<PaymentState>(payment);
    const [prepared, setPrepared] = useState<PreparedPayment | null>(null);
    const [stripe, setStripe] = useState<Promise<Stripe | null> | null>(null);
    const [problem, setProblem] = useState<string | null>(null);
    const [preparing, setPreparing] = useState(false);
    /*
     * Starts true when the server already says the payment is in flight,
     * or when the browser came back from a provider redirect. Derived at
     * first render rather than set from an effect, so the page never
     * renders a "pay now" button for a payment already under way.
     */
    const [waiting, setWaiting] = useState(
        () =>
            payment.state === 'processing' ||
            (typeof window !== 'undefined' && window.location.search.includes('payment_intent')),
    );

    const poll = useRef<number | null>(null);

    const readStatus = useCallback(async (): Promise<PaymentState | null> => {
        try {
            const response = await fetch(endpoints.status, {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                return null;
            }

            const body = (await response.json()) as { payment: PaymentState };
            setState(body.payment);

            return body.payment;
        } catch {
            // A dropped connection is not an answer about the payment.
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

            if (next?.isPaid || next?.state === 'failed' || next?.state === 'cancelled') {
                setWaiting(false);

                return;
            }

            if (elapsed >= 120) {
                setWaiting(false);
                setProblem(
                    'We have not heard back yet. Your order is still held — reload this page in a ' +
                        'moment, and do not pay again.',
                );

                return;
            }

            poll.current = window.setTimeout(() => void tick(), 2_000);
        };

        poll.current = window.setTimeout(() => void tick(), 1_000);
    }, [readStatus]);

    const waitForOutcome = useCallback(() => {
        setWaiting(true);
        runPolling();
    }, [runPolling]);

    /*
     * A customer returning from a 3-D Secure redirect lands here with the
     * outcome still in flight, and so does one who reloaded mid-payment.
     * Stripe puts its own verdict in the query string; this reads none of
     * it beyond "something happened", and asks the server for the answer.
     */
    useEffect(() => {
        if (!waiting) {
            return;
        }

        runPolling();

        return () => {
            if (poll.current !== null) {
                window.clearTimeout(poll.current);
            }
        };
        // Started once, on arrival; every later poll is driven by an action.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const prepare = useCallback(async () => {
        setPreparing(true);
        setProblem(null);

        try {
            const response = await fetch(endpoints.prepare, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
                // Deliberately empty. There is nothing this page could send
                // that the server would read: the amount comes from the
                // order, every time.
                body: '{}',
            });

            const body = (await response.json()) as Partial<PreparedPayment> & {
                message?: string;
                payment?: PaymentState;
            };

            if (body.payment) {
                setState(body.payment);
            }

            if (!response.ok) {
                setProblem(body.message ?? 'Payment could not be started. Please try again.');

                return;
            }

            const ready = body as PreparedPayment;
            setPrepared(ready);

            if (ready.provider === 'stripe' && ready.publishableKey && ready.clientSecret) {
                setStripe(stripeFor(ready.publishableKey));
            }
        } catch {
            setProblem(
                'We could not reach the payment service. Your order and items are still held — ' +
                    'please try again in a moment.',
            );
        } finally {
            setPreparing(false);
        }
    }, [endpoints.prepare]);

    if (state.isPaid) {
        return <Outcome tone="settled" state={state} reference={reference} />;
    }

    if (!state.canPay) {
        return <Outcome tone="closed" state={state} reference={reference} />;
    }

    return (
        <section aria-labelledby="payment-heading" className="border-2 border-[var(--vc-text)] p-6">
            <h2 id="payment-heading" className="mb-1 text-[22px]">
                {state.canRetry ? 'Try another payment method' : 'Pay for this order'}
            </h2>

            <p role="status" className="mb-5 text-[14px] text-[var(--vc-neutral-700)]">
                {waiting ? 'Checking with your bank…' : state.detail}
            </p>

            {problem ? (
                <p
                    role="alert"
                    className="mb-5 border-2 border-[var(--vc-accent)] px-4 py-3 text-[14px]"
                >
                    {problem}
                </p>
            ) : null}

            {prepared && stripe && prepared.clientSecret ? (
                <Elements
                    stripe={stripe}
                    options={{
                        clientSecret: prepared.clientSecret,
                        appearance: { variables: { borderRadius: '0px', fontFamily: 'inherit' } },
                    }}
                >
                    <CardForm
                        amount={prepared.amount.formatted}
                        returnUrl={prepared.returnUrl}
                        onSubmitted={waitForOutcome}
                        onProblem={setProblem}
                        disabled={waiting}
                    />
                </Elements>
            ) : (
                <>
                    {prepared && !prepared.clientSecret ? (
                        <p className="mb-4 text-[14px]">
                            Card payments are not configured for this environment.
                        </p>
                    ) : null}

                    <Button
                        variant="primary"
                        block
                        loading={preparing}
                        loadingLabel="Preparing payment…"
                        onClick={() => void prepare()}
                    >
                        {state.canRetry ? 'Try again' : 'Continue to payment'}
                    </Button>
                </>
            )}
        </section>
    );
}

/**
 * The card fields, and the one button that talks to Stripe.
 *
 * `redirect: 'if_required'` keeps the customer on the page unless their
 * bank insists on a challenge. Either way the result Stripe hands back is
 * used for one thing only — deciding whether to show an error — and the
 * order's status comes from the poll that follows.
 */
function CardForm({
    amount,
    returnUrl,
    onSubmitted,
    onProblem,
    disabled,
}: {
    amount: string;
    returnUrl: string;
    onSubmitted: () => void;
    onProblem: (message: string) => void;
    disabled: boolean;
}) {
    const stripe = useStripe();
    const elements = useElements();
    const [submitting, setSubmitting] = useState(false);

    const submit = async () => {
        if (!stripe || !elements) {
            return;
        }

        setSubmitting(true);

        const result = await stripe.confirmPayment({
            elements,
            confirmParams: { return_url: returnUrl },
            redirect: 'if_required',
        });

        setSubmitting(false);

        if (result.error) {
            /*
             * Stripe's message is shown here and only here, because at this
             * point it is about the form in front of the customer — an
             * incomplete field, a card the browser rejected. A decline that
             * reached the provider is recorded server-side and comes back
             * through the poll in the platform's own words.
             */
            onProblem(result.error.message ?? 'Those payment details could not be used.');

            return;
        }

        // Not "paid". Submitted. The difference is the whole design.
        onSubmitted();
    };

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                void submit();
            }}
        >
            <div className="mb-5">
                <PaymentElement />
            </div>

            <Button
                type="submit"
                variant="primary"
                block
                loading={submitting || disabled}
                loadingLabel="Confirming with your bank…"
                disabled={!stripe}
            >
                {`Pay ${amount}`}
            </Button>

            <p className="mt-3 text-[13px] text-[var(--vc-neutral-600)]">
                Your card details go straight to our payment provider. This shop never sees or
                stores them.
            </p>
        </form>
    );
}

function Outcome({
    tone,
    state,
    reference,
}: {
    tone: 'settled' | 'closed';
    state: PaymentState;
    reference: string;
}) {
    return (
        <section
            role="status"
            className={[
                'border-2 p-6',
                tone === 'settled' ? 'border-[var(--vc-text)]' : 'border-[var(--vc-neutral-400)]',
            ].join(' ')}
        >
            <h2 className="mb-1 text-[22px]">{state.headline}</h2>
            <p className="text-[14px] text-[var(--vc-neutral-700)]">{state.detail}</p>

            {tone === 'settled' ? (
                <p className="mt-4 text-[13px]">
                    <a
                        href={`/account/orders/${reference}`}
                        className="underline underline-offset-4"
                    >
                        Track this order
                    </a>
                </p>
            ) : null}
        </section>
    );
}
