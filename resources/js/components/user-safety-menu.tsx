import { router } from '@inertiajs/react';
import { Ban, Ellipsis, Volume2, VolumeX } from 'lucide-react';
import { useState } from 'react';
import UserBlockController from '@/actions/App/Http/Controllers/UserBlockController';
import UserMuteController from '@/actions/App/Http/Controllers/UserMuteController';
import { clearMentionSuggestionsCache } from '@/components/mention-textarea';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import type { User } from '@/types';

export function UserSafetyMenu({
    user,
    isMuting,
    isBlocking,
    className,
    onOpenChange,
}: {
    user: Pick<User, 'username'>;
    isMuting: boolean;
    isBlocking: boolean;
    className?: string;
    onOpenChange?: (isOpen: boolean) => void;
}) {
    const [isMenuOpen, setIsMenuOpen] = useState(false);
    const [isConfirmingBlock, setIsConfirmingBlock] = useState(false);
    const [isSaving, setIsSaving] = useState(false);

    const changeOpen = (menu: boolean, dialog: boolean) => {
        setIsMenuOpen(menu);
        setIsConfirmingBlock(dialog);
        onOpenChange?.(menu || dialog);
    };

    const visit = (action: Parameters<typeof router.visit>[0]) => {
        router.visit(action, {
            preserveScroll: true,
            onStart: () => setIsSaving(true),
            onFinish: () => {
                setIsSaving(false);
                changeOpen(false, false);
            },
            onSuccess: () => {
                clearMentionSuggestionsCache();
                router.flushAll();
            },
        });
    };

    return (
        <>
            <DropdownMenu
                modal={false}
                open={isMenuOpen}
                onOpenChange={(open) => changeOpen(open, isConfirmingBlock)}
            >
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="outline"
                        size="icon"
                        className={cn('shrink-0', className)}
                        aria-label={`More options for @${user.username}`}
                        data-test="user-safety-menu"
                    >
                        <Ellipsis />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-52">
                    <DropdownMenuItem
                        disabled={isSaving}
                        onSelect={() =>
                            visit(
                                isMuting
                                    ? UserMuteController.destroy(user)
                                    : UserMuteController.store(user),
                            )
                        }
                    >
                        {isMuting ? <Volume2 /> : <VolumeX />}
                        {isMuting
                            ? `Unmute @${user.username}`
                            : `Mute @${user.username}`}
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        variant={isBlocking ? 'default' : 'destructive'}
                        disabled={isSaving}
                        onSelect={() =>
                            isBlocking
                                ? visit(UserBlockController.destroy(user))
                                : changeOpen(false, true)
                        }
                    >
                        <Ban />
                        {isBlocking
                            ? `Unblock @${user.username}`
                            : `Block @${user.username}`}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <Dialog
                open={isConfirmingBlock}
                onOpenChange={(open) => changeOpen(false, open)}
            >
                <DialogContent>
                    <DialogTitle>Block @{user.username}?</DialogTitle>
                    <DialogDescription>
                        You'll stop following each other. Neither of you will be
                        able to follow, reply to, or star the other, or see each
                        other's commits. They won't be told, but they'll see
                        your profile as unavailable. You can unblock them any
                        time from your settings.
                    </DialogDescription>

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button variant="outline">Cancel</Button>
                        </DialogClose>

                        <Button
                            variant="destructive"
                            disabled={isSaving}
                            onClick={() =>
                                visit(UserBlockController.store(user))
                            }
                            data-test="confirm-block-button"
                        >
                            Block
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
