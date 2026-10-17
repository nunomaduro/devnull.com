import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowUpRight, Download, RefreshCw } from 'lucide-react';
import ExportController from '@/actions/App/Http/Controllers/ExportController';
import GitHubIssueAuthorizationController from '@/actions/App/Http/Controllers/GitHubIssueAuthorizationController';
import UserProfileController from '@/actions/App/Http/Controllers/UserProfileController';
import ActivityGraphEmbed from '@/components/activity-graph-embed';
import DeleteUser from '@/components/delete-user';
import { GitHubProfile } from '@/components/github-profile';
import Heading from '@/components/heading';
import LanePicker from '@/components/lane-picker';
import { PasteisModeSetting } from '@/components/pasteis-mode-setting';
import TimezonePicker from '@/components/timezone-picker';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useNow } from '@/hooks/use-now';
import { useUser } from '@/hooks/use-user';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { formatFullDateTime } from '@/lib/utils';
import { show } from '@/routes/user';
import type { Lane } from '@/types';

function formatSyncedAgo(date: string, now: Date): string {
    const seconds = Math.round(
        (now.getTime() - new Date(date).getTime()) / 1000,
    );

    if (seconds < 60) {
        return 'just now';
    }

    const [value, unit]: [number, Intl.RelativeTimeFormatUnit] =
        seconds < 3600
            ? [Math.floor(seconds / 60), 'minute']
            : seconds < 86400
              ? [Math.floor(seconds / 3600), 'hour']
              : [Math.floor(seconds / 86400), 'day'];

    return new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' }).format(
        -value,
        unit,
    );
}

export default function Edit({
    preferredLane,
    automaticLane,
    githubSyncedAt,
    activityGraphUrl,
    profileUrl,
}: {
    preferredLane: Lane | null;
    automaticLane: number;
    githubSyncedAt: string | null;
    activityGraphUrl: string;
    profileUrl: string;
}) {
    const { user } = useUser();
    const { auth } = usePage().props;
    const now = useNow();

    return (
        <>
            <Head title="Profile settings" />

            <section className="px-4 py-6 sm:px-6">
                <Heading
                    title="Profile information"
                    description="Mirrored from GitHub when you sign in. To change any of it, change it on GitHub, then sync it here."
                />

                <GitHubProfile />

                <div className="mt-5 flex flex-wrap gap-2">
                    <Button asChild>
                        <a
                            href="https://github.com/settings/profile"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            Edit on GitHub
                            <ArrowUpRight />
                        </a>
                    </Button>
                    <Form
                        {...UserProfileController.update.form()}
                        options={{ preserveScroll: true }}
                        onSuccess={() => router.flushAll()}
                    >
                        {({ processing }) => (
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={processing}
                                aria-busy={processing}
                                data-test="sync-profile-button"
                            >
                                {processing ? <Spinner /> : <RefreshCw />}
                                Sync from GitHub
                            </Button>
                        )}
                    </Form>
                    <Button variant="outline" asChild>
                        <Link href={show(user)}>View profile</Link>
                    </Button>
                </div>

                <p
                    className="mt-3 text-sm text-muted-foreground"
                    aria-live="polite"
                >
                    {githubSyncedAt ? (
                        <>
                            Last synced{' '}
                            <time
                                dateTime={githubSyncedAt}
                                title={formatFullDateTime(githubSyncedAt)}
                            >
                                {formatSyncedAgo(githubSyncedAt, now)}
                            </time>
                        </>
                    ) : (
                        'Last synced when you signed in'
                    )}
                </p>
            </section>

            <section
                id="github-issue-commands"
                className="scroll-mt-20 px-4 py-6 sm:px-6"
            >
                <Heading
                    title="GitHub issue commands"
                    description="Use /feature and /bug to create public issues in nunomaduro/dev-null.sh as your GitHub account, with your commit body and images."
                />
                <p className="mb-3 text-sm text-muted-foreground">
                    Optional permission:{' '}
                    <code className="font-mono">public_repo</code>. GitHub
                    grants read/write access to public repositories you can
                    access—not just upstream, and not just issues. This app uses
                    it only for upstream issue commands. Normal sign-in does not
                    require it.
                </p>
                <p
                    className="mb-4 line-clamp-2 h-10 text-sm sm:h-5 sm:truncate"
                    aria-live="polite"
                    title={
                        auth.githubIssueCommandsEnabled
                            ? `Enabled · issues are created as @${user.username}`
                            : 'Disabled · additional GitHub permission required'
                    }
                >
                    {auth.githubIssueCommandsEnabled
                        ? `Enabled · issues are created as @${user.username}`
                        : 'Disabled · additional GitHub permission required'}
                </p>
                <div className="flex flex-wrap items-center gap-3">
                    {auth.githubIssueCommandsEnabled ? (
                        <Form
                            {...GitHubIssueAuthorizationController.destroy.form()}
                            options={{ preserveScroll: true }}
                            onSuccess={() => router.flushAll()}
                        >
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    variant="outline"
                                    disabled={processing}
                                    className="w-56"
                                >
                                    Disable issue commands
                                </Button>
                            )}
                        </Form>
                    ) : (
                        <Button asChild className="w-56">
                            <a
                                href={GitHubIssueAuthorizationController.create.url()}
                            >
                                Enable issue commands
                                <ArrowUpRight />
                            </a>
                        </Button>
                    )}
                    <a
                        href="https://github.com/settings/applications"
                        target="_blank"
                        rel="noopener noreferrer"
                        className="text-sm text-muted-foreground underline underline-offset-4 hover:text-foreground"
                    >
                        Review or revoke on GitHub
                    </a>
                </div>
                <p className="mt-3 text-xs text-muted-foreground">
                    Disabling removes the token stored here. It does not revoke
                    the grant on GitHub.
                </p>
            </section>

            <LanePicker
                preferredLane={preferredLane}
                automaticLane={automaticLane}
            />

            <TimezonePicker />

            <PasteisModeSetting />

            <ActivityGraphEmbed
                activityGraphUrl={activityGraphUrl}
                profileUrl={profileUrl}
            />

            <section className="px-4 py-6 sm:px-6">
                <Heading
                    title="Export your data"
                    description="Download everything we store about you: your profile, commits and replies (including pending, rejected, and deleted ones), stars, saved commits, follows, mutes, blocks, notifications, SSH key fingerprints, and active sessions."
                />
                <p className="mb-5 text-sm text-muted-foreground">
                    Other people's replies are excluded. Your export is a JSON
                    file. Your profile is still managed on GitHub.
                </p>
                <Button variant="outline" asChild>
                    <a href={ExportController.url()}>
                        <Download />
                        Download JSON
                    </a>
                </Button>
            </section>

            <DeleteUser />
        </>
    );
}

Edit.layout = [AppLayout, SettingsLayout];
