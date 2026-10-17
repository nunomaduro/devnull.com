import { CommitComposer } from '@/components/commit-composer';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { useSharedState } from '@/hooks/use-shared-state';

const bodyId = 'commit-dialog-body';

const closedCountKey = 'commit-dialog:closed_count';

/**
 * Focuses the composer at the top of the log when it is on screen, rather than opening the dialog over it.
 */
export function focusVisibleComposer(): boolean {
    const body = document.getElementById('body');

    if (!(body instanceof HTMLTextAreaElement)) {
        return false;
    }

    const { left, top, width, height } = body.getBoundingClientRect();
    const hit = document.elementFromPoint(left + width / 2, top + height / 2);

    if (hit === null || !body.form?.contains(hit)) {
        return false;
    }

    body.focus();

    return true;
}

/**
 * The dialog writes to the same stash as the composer on the log, so that composer remounts with this as its key to
 * show the draft as the dialog left it.
 */
export function useCommitDialogClosedCount(): number {
    const [closedCount] = useSharedState<number>(closedCountKey, 0);

    return closedCount;
}

export function CommitComposerDialog({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [, setClosedCount] = useSharedState<number>(closedCountKey, 0);

    const close = () => {
        onOpenChange(false);
        setClosedCount((count) => count + 1);
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(isOpen) => (isOpen ? onOpenChange(true) : close())}
        >
            {open && (
                <DialogContent
                    onOpenAutoFocus={(event) => {
                        event.preventDefault();
                        document.getElementById(bodyId)?.focus();
                    }}
                    onEscapeKeyDown={(event) => {
                        if (
                            document.querySelector(
                                '[data-mention-suggestions], [data-command-suggestions]',
                            )
                        ) {
                            event.preventDefault();
                        }
                    }}
                    onInteractOutside={(event) => {
                        if (
                            event.target instanceof Element &&
                            event.target.closest(
                                '[data-mention-suggestions], [data-command-suggestions]',
                            )
                        ) {
                            event.preventDefault();
                        }
                    }}
                    className="max-h-[calc(100dvh-2rem)] gap-0 p-0 sm:max-w-xl"
                    data-test="commit-composer-dialog"
                >
                    <DialogTitle className="px-5 pt-5 pb-1 text-base">
                        Write a commit
                    </DialogTitle>
                    <DialogDescription className="sr-only">
                        Your commit is added to your log once it is submitted.
                    </DialogDescription>
                    <CommitComposer
                        id={bodyId}
                        isInDialog
                        onCommitted={close}
                    />
                </DialogContent>
            )}
        </Dialog>
    );
}
