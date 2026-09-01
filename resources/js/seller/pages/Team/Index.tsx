import { useForm, usePage, router } from '@inertiajs/react';
import { SellerLayout } from '../../layouts/SellerLayout';
import { Button } from '../../../design-system/primitives/Button';
import { Field, Input, Select } from '../../../design-system/primitives/Field';
import { EmptyState, FlashBanner } from '../../../design-system/patterns/States';
import { Table } from '../../../design-system/patterns/Table';
import type { Column } from '../../../design-system/patterns/Table';
import type { SharedPageProps } from '../../../shared/types';

interface Member {
    id: number;
    name: string | null;
    email: string | null;
    role: string;
    roleLabel: string;
    acceptedAt: string | null;
}

interface Invitation {
    publicId: string;
    email: string;
    role: string;
    status: string;
    expiresAt: string;
}

interface TeamProps extends SharedPageProps {
    errors: Record<string, string>;
    members: Member[];
    invitations: Invitation[];
    roles: { value: string; label: string }[];
    can: { manage: boolean };
}

export default function Index() {
    const { members, invitations, roles, can, flash, errors } = usePage<TeamProps>().props;

    const form = useForm({ email: '', role: roles[0]?.value ?? '' });

    const memberColumns: Column<Member>[] = [
        {
            key: 'name',
            header: 'Member',
            render: (row) => (
                <span>
                    <span className="block font-semibold">{row.name ?? '—'}</span>
                    <span className="block text-[12px] text-[var(--vc-neutral-600)]">
                        {row.email ?? '—'}
                    </span>
                </span>
            ),
        },
        {
            key: 'role',
            header: 'Role',
            render: (row) =>
                can.manage ? (
                    <Select
                        aria-label={`Role for ${row.email ?? 'this member'}`}
                        value={row.role}
                        onChange={(event) =>
                            router.patch(
                                `/seller/team/${row.id}`,
                                { role: event.target.value },
                                { preserveScroll: true },
                            )
                        }
                    >
                        {roles.map((role) => (
                            <option key={role.value} value={role.value}>
                                {role.label}
                            </option>
                        ))}
                    </Select>
                ) : (
                    row.roleLabel
                ),
        },
        { key: 'accepted', header: 'Joined', render: (row) => row.acceptedAt ?? '—' },
        {
            key: 'action',
            header: '',
            render: (row) =>
                can.manage ? (
                    <Button
                        variant="destructive"
                        onClick={() => {
                            // A removal is not undoable from this screen, so it
                            // is confirmed before the request leaves.
                            if (
                                window.confirm(
                                    `Remove ${row.email ?? 'this member'} from the store?`,
                                )
                            ) {
                                router.delete(`/seller/team/${row.id}`, { preserveScroll: true });
                            }
                        }}
                    >
                        Remove
                    </Button>
                ) : null,
        },
    ];

    const invitationColumns: Column<Invitation>[] = [
        { key: 'email', header: 'Invited', render: (row) => row.email },
        {
            key: 'role',
            header: 'Role',
            render: (row) => roles.find((role) => role.value === row.role)?.label ?? row.role,
        },
        { key: 'expires', header: 'Expires', render: (row) => row.expiresAt },
        {
            key: 'action',
            header: '',
            render: (row) =>
                can.manage ? (
                    <Button
                        variant="ghost"
                        onClick={() =>
                            router.delete(`/seller/team/invitations/${row.publicId}`, {
                                preserveScroll: true,
                            })
                        }
                    >
                        Withdraw
                    </Button>
                ) : null,
        },
    ];

    return (
        <SellerLayout title="Team">
            <FlashBanner success={flash.success} error={flash.error} />

            {errors.role || errors.member ? (
                <p
                    role="alert"
                    className="mb-6 border-2 border-[var(--vc-accent)] px-4 py-3 text-[14px] text-[var(--vc-accent-800)]"
                >
                    {errors.role ?? errors.member}
                </p>
            ) : null}

            <div className="grid gap-10 lg:grid-cols-[minmax(0,1fr)_320px]">
                <div className="flex flex-col gap-10">
                    <section>
                        <h2 className="mb-4 text-[22px]">Members</h2>

                        <Table
                            columns={memberColumns}
                            rows={members}
                            rowKey={(row) => row.id}
                            caption="People with access to this store"
                        />
                    </section>

                    <section>
                        <h2 className="mb-4 text-[22px]">Pending invitations</h2>

                        <Table
                            columns={invitationColumns}
                            rows={invitations}
                            rowKey={(row) => row.publicId}
                            caption="Invitations that have not been accepted"
                            empty={
                                <EmptyState
                                    title="No invitations outstanding"
                                    body="Everyone you have invited has either joined or had their invitation withdrawn."
                                />
                            }
                        />
                    </section>
                </div>

                {can.manage ? (
                    <aside className="h-max border-2 border-[var(--vc-divider)] p-5">
                        <h2 className="mb-1 text-[20px]">Invite someone</h2>
                        <p className="mb-5 text-[13px] text-[var(--vc-neutral-700)]">
                            They receive a single-use link. It expires, and it only works for the
                            address you enter here.
                        </p>

                        <form
                            className="flex flex-col gap-4"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.post('/seller/team/invitations', {
                                    preserveScroll: true,
                                    onSuccess: () => form.reset('email'),
                                });
                            }}
                        >
                            <Field label="Email address" error={form.errors.email}>
                                {({ id, describedBy, invalid }) => (
                                    <Input
                                        id={id}
                                        type="email"
                                        required
                                        aria-describedby={describedBy}
                                        invalid={invalid}
                                        value={form.data.email}
                                        onChange={(event) =>
                                            form.setData('email', event.target.value)
                                        }
                                    />
                                )}
                            </Field>

                            <Field label="Role" error={form.errors.role}>
                                {({ id, describedBy, invalid }) => (
                                    <Select
                                        id={id}
                                        aria-describedby={describedBy}
                                        invalid={invalid}
                                        value={form.data.role}
                                        onChange={(event) =>
                                            form.setData('role', event.target.value)
                                        }
                                    >
                                        {roles.map((role) => (
                                            <option key={role.value} value={role.value}>
                                                {role.label}
                                            </option>
                                        ))}
                                    </Select>
                                )}
                            </Field>

                            <Button
                                type="submit"
                                variant="primary"
                                loading={form.processing}
                                loadingLabel="Sending…"
                            >
                                Send invitation
                            </Button>
                        </form>
                    </aside>
                ) : (
                    <aside className="h-max border-2 border-[var(--vc-divider)] p-5 text-[13px] text-[var(--vc-neutral-700)]">
                        Your role can see the team but not change it. An owner can invite and remove
                        members.
                    </aside>
                )}
            </div>
        </SellerLayout>
    );
}
