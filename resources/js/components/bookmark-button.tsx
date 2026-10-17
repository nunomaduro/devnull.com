import { router, useHttp, usePage } from '@inertiajs/react';
import { Bookmark } from 'lucide-react';
import { createContext, useContext } from 'react';
import { toast } from 'sonner';
import BookmarkController from '@/actions/App/Http/Controllers/BookmarkController';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useSharedState } from '@/hooks/use-shared-state';
import { signIn } from '@/lib/auth';
import { commitStateKey } from '@/lib/commit-state';
import { cn } from '@/lib/utils';
import type { Commit } from '@/types';

export const BookmarkRemovalContext = createContext<
    ((id: string) => void) | null
>(null);

export function useBookmark(commit: Commit) {
    const { auth } = usePage().props;
    const [isSaved, setIsSaved] = useSharedState(
        commitStateKey('is_saved', commit.id),
        commit.is_saved ?? false,
    );
    const request = useHttp<Record<string, never>, { is_saved: boolean }>({});
    const onRemoved = useContext(BookmarkRemovalContext);

    const toggle = async () => {
        if (auth.user === null) {
            signIn();

            return;
        }

        if (request.processing) {
            return;
        }

        const next = !isSaved;

        setIsSaved(next);

        try {
            const response = await request.submit(
                next
                    ? BookmarkController.store(commit)
                    : BookmarkController.destroy(commit),
            );

            setIsSaved(response.is_saved);
            router.flushAll();

            if (!response.is_saved) {
                onRemoved?.(commit.id);
            }
        } catch {
            setIsSaved(!next);
            toast.error('Could not update your saved commits. Try again.');
        }
    };

    return {
        isAvailable:
            commit.status === 'approved' || commit.user_id === auth.user?.id,
        isSaved,
        isProcessing: request.processing,
        toggle,
    };
}

export function BookmarkButton({ commit }: { commit: Commit }) {
    const { isAvailable, isSaved, isProcessing, toggle } = useBookmark(commit);

    if (!isAvailable) {
        return null;
    }

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <button
                    type="button"
                    onClick={toggle}
                    aria-disabled={isProcessing}
                    aria-label={isSaved ? 'Unsave commit' : 'Save commit'}
                    aria-pressed={isSaved}
                    className={cn(
                        'rounded-md p-1.5 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none aria-disabled:pointer-events-none aria-disabled:opacity-50',
                        { 'text-primary hover:text-primary': isSaved },
                    )}
                    data-test="bookmark-button"
                >
                    <Bookmark
                        aria-hidden
                        className={cn('size-4', { 'fill-current': isSaved })}
                    />
                </button>
            </TooltipTrigger>
            <TooltipContent>
                {isSaved ? 'Saved' : 'Save commit privately'}
            </TooltipContent>
        </Tooltip>
    );
}
