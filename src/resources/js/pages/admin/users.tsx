import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type UserRow = {
    id: number;
    name: string;
    email: string;
    role: string;
    tier: string;
    pro_until: string | null;
};
type InvitationRow = {
    id: number;
    email: string;
    role: string;
    status: string;
    expires_at: string;
};

export default function Users({
    users,
    invitations,
    roles,
}: {
    users: UserRow[];
    invitations: InvitationRow[];
    roles: string[];
}) {
    return (
        <>
            <Head title="Users" />
            <div className="mx-auto grid max-w-4xl gap-8 p-6">
                <Form
                    action="/admin/invitations"
                    method="post"
                    resetOnSuccess
                    className="flex flex-wrap items-end gap-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="email">Invite by email</Label>
                                <Input
                                    id="email"
                                    name="email"
                                    type="email"
                                    required
                                />
                                <InputError message={errors.email} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="role">Role</Label>
                                <select
                                    id="role"
                                    name="role"
                                    defaultValue="member"
                                    className="h-9 rounded-md border bg-transparent px-2"
                                >
                                    {roles.map((r) => (
                                        <option key={r} value={r}>
                                            {r}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <Button type="submit" disabled={processing}>
                                Send invitation
                            </Button>
                        </>
                    )}
                </Form>

                <section>
                    <h2 className="mb-2 font-semibold">Users</h2>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b text-left">
                                <th className="px-2 py-1">Name</th>
                                <th className="px-2 py-1">Email</th>
                                <th className="px-2 py-1">Role</th>
                                <th className="px-2 py-1">Tier</th>
                            </tr>
                        </thead>
                        <tbody>
                            {users.map((u) => (
                                <tr key={u.id}>
                                    <td className="px-2 py-1">{u.name}</td>
                                    <td className="px-2 py-1">{u.email}</td>
                                    <td className="px-2 py-1">{u.role}</td>
                                    <td className="px-2 py-1">{u.tier}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </section>

                <section>
                    <h2 className="mb-2 font-semibold">Invitations</h2>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b text-left">
                                <th className="px-2 py-1">Email</th>
                                <th className="px-2 py-1">Role</th>
                                <th className="px-2 py-1">Status</th>
                                <th className="px-2 py-1">Expires</th>
                            </tr>
                        </thead>
                        <tbody>
                            {invitations.map((i) => (
                                <tr key={i.id}>
                                    <td className="px-2 py-1">{i.email}</td>
                                    <td className="px-2 py-1">{i.role}</td>
                                    <td className="px-2 py-1">{i.status}</td>
                                    <td className="px-2 py-1">
                                        {i.expires_at}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </section>
            </div>
        </>
    );
}
