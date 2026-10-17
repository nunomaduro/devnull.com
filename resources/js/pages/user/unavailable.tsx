import { Head, Link } from '@inertiajs/react';
import { Ban } from 'lucide-react';
import UserBlockController from '@/actions/App/Http/Controllers/UserBlockController';
import { EmptyFeed } from '@/components/commit-feed';
import { clearMentionSuggestionsCache } from '@/components/mention-textarea';
import { Button } from '@/components/ui/button';
import { UserAvatar } from '@/components/user-avatar';
import AppLayout from '@/layouts/app-layout';
import { edit as editSafety } from '@/routes/safety';
import type { User } from '@/types';

export default function Unavailable({
    username,
    blockedUser,
}: {
    username: string;
    blockedUser: User | null;
}) {
    return (
        <>
            <Head title={`@${username}`} />

            <div className="sticky top-0 z-10 flex h-14 items-center border-b bg-card px-4">
                <p className="truncate font-mono text-sm">@{username}</p>
            </div>

            <div className="px-6 py-16 text-center sm:px-12">
                {blockedUser ? (
                    <>
                        <UserAvatar
                            user={blockedUser}
                            className="mx-auto mb-5 size-16 text-xl grayscale"
                            pixels={160}
                        />
                        <EmptyFeed command={`fatal: @${username} is blocked`}>
                            <p>
                                You blocked {blockedUser.name}. Neither of you
                                can follow, reply to, or star the other, or see
                                each other's commits.
                            </p>
                            <div className="mt-5 flex flex-wrap justify-center gap-2">
                                <Button variant="outline" asChild>
                                    <Link
                                        href={UserBlockController.destroy(
                                            blockedUser,
                                        )}
                                        as="button"
                                        onSuccess={clearMentionSuggestionsCache}
                                        data-test="unblock-button"
                                    >
                                        <Ban />
                                        Unblock @{username}
                                    </Link>
                                </Button>
                                <Button variant="ghost" asChild>
                                    <Link href={editSafety()}>
                                        Muted and blocked people
                                    </Link>
                                </Button>
                            </div>
                        </EmptyFeed>
                    </>
                ) : (
                    <EmptyFeed command={`fatal: @${username} is unavailable`}>
                        This profile isn't available.
                    </EmptyFeed>
                )}
            </div>
        </>
    );
}

Unavailable.layout = AppLayout;
