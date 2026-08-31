import { useForm } from '@inertiajs/react';
import { Button } from '../primitives/Button';
import { Field, Textarea } from '../primitives/Field';
import { Modal } from './Modal';

interface ReasonDialogProps {
    open: boolean;
    title: string;
    consequence: string;
    confirmLabel: string;
    /** Where the decision is posted. */
    action: string;
    onClose: () => void;
}

/**
 * The dialog every negative decision goes through.
 *
 * The reason is required by the server — this is the accessible way to
 * collect it, not the thing enforcing it. Focus is trapped, Escape closes,
 * and the consequence is stated in words before the button that causes it.
 */
export function ReasonDialog({
    open,
    title,
    consequence,
    confirmLabel,
    action,
    onClose,
}: ReasonDialogProps) {
    const form = useForm({ reason: '' });

    return (
        <Modal
            open={open}
            title={title}
            consequence={consequence}
            onClose={onClose}
            actions={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        variant="primary"
                        loading={form.processing}
                        loadingLabel="Saving…"
                        onClick={() =>
                            form.post(action, {
                                preserveScroll: true,
                                onSuccess: () => {
                                    form.reset();
                                    onClose();
                                },
                            })
                        }
                    >
                        {confirmLabel}
                    </Button>
                </>
            }
        >
            <Field
                label="Reason — recorded permanently and shown to the seller"
                error={form.errors.reason}
                hint="Give them enough to act on."
            >
                {({ id, describedBy, invalid }) => (
                    <Textarea
                        id={id}
                        aria-describedby={describedBy}
                        invalid={invalid}
                        value={form.data.reason}
                        onChange={(event) => form.setData('reason', event.target.value)}
                    />
                )}
            </Field>
        </Modal>
    );
}
