import { Head, Link } from '@inertiajs/react';
import type { CSSProperties } from 'react';
import UserBlockController from '@/actions/App/Http/Controllers/UserBlockController';
import UserMuteController from '@/actions/App/Http/Controllers/UserMuteController';
import Heading from '@/components/heading';
import { clearMentionSuggestionsCache } from '@/components/mention-textarea';
import { Button } from '@/components/ui/button';
import { UserAvatar } from '@/components/user-avatar';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { laneColor } from '@/lib/utils';
import { show } from '@/routes/user';
import type { User } from '@/types';

function PeopleList({
    people,
    empty,
    undo,
}: {
    people: User[];
    empty: string;
    undo: (person: User) => {
        label: string;
        href: ReturnType<typeof UserMuteController.destroy>;
    };
}) {
    if (people.length === 0) {
        return (
            <p className="rounded-lg border border-dashed px-4 py-6 text-center text-sm text-muted-foreground">
                {empty}
            </p>
        );
    }

    return (
        <ul className="divide-y rounded-lg border">
            {people.map((person) => {
                const action = undo(person);

                return (
                    <li
                        key={person.id}
                        className="flex items-center gap-3 px-4 py-3"
                    >
                        <Link
                            href={show(person)}
                            className="flex min-w-0 flex-1 items-center gap-3"
                        >
                            <UserAvatar
                                user={person}
                                className="size-9 ring-2 ring-(--lane) ring-offset-2 ring-offset-card"
                                style={
                                    {
                                        '--lane': laneColor(person.lane),
                                    } as CSSProperties
                                }
                            />
                            <div className="min-w-0">
                                <p className="truncate font-medium">
                                    {person.name}
                                </p>
                                <p className="truncate font-mono text-[13px] text-muted-foreground">
                                    @{person.username}
                                </p>
                            </div>
                        </Link>
                        <Button
                            variant="outline"
                            size="sm"
                            className="shrink-0"
                            asChild
                        >
                            <Link
                                href={action.href}
                                as="button"
                                preserveScroll
                                onSuccess={clearMentionSuggestionsCache}
                                aria-label={`${action.label} @${person.username}`}
                            >
                                {action.label}
                            </Link>
                        </Button>
                    </li>
                );
            })}
        </ul>
    );
}

export default function Edit({
    muted,
    blocked,
}: {
    muted: User[];
    blocked: User[];
}) {
    return (
        <>
            <Head title="Safety settings" />

            <section className="px-4 py-6 sm:px-6">
                <Heading
                    title="Muted"
                    description="Their commits and replies stay out of your logs, threads, search, and suggestions, and they can't notify you. They aren't told, and you can still visit their profile."
                />
                <PeopleList
                    people={muted}
                    empty="You haven't muted anyone."
                    undo={(person) => ({
                        label: 'Unmute',
                        href: UserMuteController.destroy(person),
                    })}
                />
            </section>

            <section className="px-4 py-6 sm:px-6">
                <Heading
                    title="Blocked"
                    description="Neither of you can follow, reply to, or star the other, or see each other's commits. Unblocking doesn't bring back follows."
                />
                <PeopleList
                    people={blocked}
                    empty="You haven't blocked anyone."
                    undo={(person) => ({
                        label: 'Unblock',
                        href: UserBlockController.destroy(person),
                    })}
                />
            </section>
        </>
    );
}

Edit.layout = [AppLayout, SettingsLayout];
