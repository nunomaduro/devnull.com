import { Link, router, useHttp, usePage } from '@inertiajs/react';
import { Terminal } from 'lucide-react';
import type { CSSProperties } from 'react';
import { useId, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { toast } from 'sonner';
import { store } from '@/actions/App/Http/Controllers/IssueController';
import { edit } from '@/actions/App/Http/Controllers/UserProfileController';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

const commands = [
    { name: '/feature', description: 'Request a feature' },
    { name: '/bug', description: 'Report a bug' },
];

export function CommitCommands({
    body,
    images,
    descriptions,
    disabled,
    hasSecrets,
    onProcessingChange,
}: {
    body: string;
    images: File[];
    descriptions: string[];
    disabled: boolean;
    hasSecrets: boolean;
    onProcessingChange: (processing: boolean) => void;
}) {
    const id = useId();
    const { auth } = usePage().props;
    const [authorizationExpired, setAuthorizationExpired] = useState(false);
    const authorized = auth.githubIssueCommandsEnabled && !authorizationExpired;
    const input = useRef<HTMLInputElement>(null);
    const container = useRef<HTMLDivElement>(null);
    const [position, setPosition] = useState<CSSProperties>({});
    const running = useRef(false);
    const [command, setCommand] = useState('');
    const [suggesting, setSuggesting] = useState(false);
    const [selected, setSelected] = useState(0);
    const request = useHttp<
        {
            command: string;
            body: string;
            images: File[];
            image_descriptions: string[];
        },
        { number: number; url: string; labeled: boolean }
    >({ command: '', body: '', images: [], image_descriptions: [] });
    const matches = commands.filter((item) => item.name.startsWith(command));
    const showSuggestions =
        authorized &&
        suggesting &&
        command.startsWith('/') &&
        matches.length > 0;
    const valid = commands.some((item) => item.name === command);

    useLayoutEffect(() => {
        if (!showSuggestions) return;

        const updatePosition = () => {
            const rect = container.current?.getBoundingClientRect();
            if (!rect) return;
            const height = window.visualViewport?.height ?? window.innerHeight;
            const below = height - rect.bottom - 8;
            const above = below < 160 && rect.top > below;
            setPosition({
                left: rect.left,
                width: rect.width,
                top: above ? undefined : rect.bottom + 4,
                bottom: above ? window.innerHeight - rect.top + 4 : undefined,
                maxHeight: Math.max(
                    0,
                    Math.min(height * 0.4, above ? rect.top - 8 : below),
                ),
            });
        };

        updatePosition();
        window.addEventListener('resize', updatePosition);
        window.addEventListener('scroll', updatePosition, true);
        window.visualViewport?.addEventListener('resize', updatePosition);
        return () => {
            window.removeEventListener('resize', updatePosition);
            window.removeEventListener('scroll', updatePosition, true);
            window.visualViewport?.removeEventListener(
                'resize',
                updatePosition,
            );
        };
    }, [showSuggestions]);

    const complete = (name: string) => {
        setCommand(name);
        setSelected(0);
        setSuggesting(false);
        input.current?.focus();
    };

    const run = async () => {
        if (!authorized || disabled || running.current || !valid) {
            return;
        }

        if (hasSecrets) {
            toast.error(
                'Remove detected secrets before creating a public GitHub issue.',
            );
            return;
        }

        running.current = true;
        onProcessingChange(true);
        setSuggesting(false);
        request.transform(() => ({
            command,
            body,
            images,
            image_descriptions: descriptions,
        }));

        try {
            const issue = await request.submit(store(), {
                onError: (errors) => {
                    toast.error(
                        Object.values(errors)[0] ?? 'Check your draft.',
                    );
                },
                onHttpException: (response) => {
                    if (response.status === 403) {
                        setAuthorizationExpired(true);
                        router.reload({ only: ['auth'] });
                    }
                    toast.error(
                        response.status === 403
                            ? 'Enable GitHub issue commands in Settings → Profile. Your draft is safe.'
                            : response.status === 429
                              ? 'Issue limit reached. Try again in an hour.'
                              : 'GitHub did not confirm the issue. Check upstream before retrying. Your draft is safe.',
                    );
                },
                onNetworkError: () => {
                    toast.error(
                        'Connection lost. Check upstream before retrying. Your draft is safe.',
                    );
                },
            });
            setCommand('');
            toast.success(
                `Created issue #${issue.number}. Your draft is still here.`,
                {
                    duration: 10000,
                    action: {
                        label: 'View issue',
                        onClick: () =>
                            window.open(
                                issue.url,
                                '_blank',
                                'noopener,noreferrer',
                            ),
                    },
                },
            );
        } catch {
            // The callbacks explain the failure; keep the draft and command intact.
        } finally {
            running.current = false;
            onProcessingChange(false);
        }
    };

    return (
        <Tooltip open={authorized ? false : undefined}>
            <TooltipTrigger asChild>
                <div
                    ref={container}
                    tabIndex={authorized ? undefined : 0}
                    aria-label={
                        authorized
                            ? undefined
                            : 'Issue commands disabled. Enable public_repo permission in Settings, Profile.'
                    }
                    className="relative mt-2 rounded-md border border-neutral-600 bg-neutral-700 text-neutral-200 focus-within:border-blue-400 dark:border-neutral-800 dark:bg-neutral-950 dark:focus-within:border-blue-400"
                    onBlur={(event) => {
                        if (!event.currentTarget.contains(event.relatedTarget))
                            setSuggesting(false);
                    }}
                >
                    <div className="flex h-10 min-w-0 items-center gap-2 px-3">
                        <Terminal
                            className="size-4 shrink-0 text-blue-300"
                            aria-hidden="true"
                        />
                        <div className="min-w-0 flex-1">
                            <input
                                ref={input}
                                role="combobox"
                                aria-label="Command"
                                aria-autocomplete="list"
                                aria-expanded={showSuggestions}
                                aria-controls={
                                    showSuggestions
                                        ? `${id}-commands`
                                        : undefined
                                }
                                aria-activedescendant={
                                    showSuggestions
                                        ? `${id}-option-${selected}`
                                        : undefined
                                }
                                aria-describedby={`${id}-help`}
                                autoComplete="off"
                                spellCheck={false}
                                placeholder="/command"
                                value={command}
                                disabled={!authorized || request.processing}
                                onChange={(event) => {
                                    setCommand(event.target.value);
                                    setSelected(0);
                                    setSuggesting(true);
                                }}
                                onFocus={() => setSuggesting(true)}
                                onKeyDown={(event) => {
                                    if (event.nativeEvent.isComposing) return;
                                    if (
                                        (event.key === 'ArrowDown' ||
                                            event.key === 'ArrowUp') &&
                                        command.startsWith('/') &&
                                        matches.length > 0
                                    ) {
                                        event.preventDefault();
                                        setSuggesting(true);
                                        setSelected(
                                            (index) =>
                                                (index +
                                                    (event.key === 'ArrowDown'
                                                        ? 1
                                                        : -1) +
                                                    matches.length) %
                                                matches.length,
                                        );
                                    } else if (
                                        event.key === 'Tab' &&
                                        !event.shiftKey &&
                                        showSuggestions
                                    ) {
                                        event.preventDefault();
                                        complete(matches[selected].name);
                                    } else if (event.key === 'Enter') {
                                        event.preventDefault();
                                        event.stopPropagation();
                                        if (showSuggestions && !valid)
                                            complete(matches[selected].name);
                                        else void run();
                                    } else if (event.key === 'Escape') {
                                        event.preventDefault();
                                        event.stopPropagation();
                                        setSuggesting(false);
                                    }
                                }}
                                className="h-9 w-full min-w-0 bg-transparent font-mono text-base text-neutral-100 caret-blue-300 outline-none placeholder:text-neutral-300 disabled:pointer-events-none disabled:opacity-50 md:text-sm dark:placeholder:text-neutral-500"
                            />
                        </div>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            disabled={
                                !authorized ||
                                disabled ||
                                request.processing ||
                                !valid
                            }
                            onClick={() => void run()}
                            className="min-w-20 shrink-0 font-mono text-xs text-neutral-300 hover:bg-neutral-600 hover:text-white focus-visible:ring-blue-400/50 dark:hover:bg-neutral-800"
                        >
                            {request.processing ? 'Running…' : 'Run ↵'}
                        </Button>
                    </div>
                    <p
                        id={`${id}-help`}
                        className="border-t border-neutral-600 px-3 py-2 text-xs leading-relaxed text-neutral-300 dark:border-neutral-800 dark:text-neutral-400"
                    >
                        Commit body and images are passed as command arguments.{' '}
                        <Link
                            href={`${edit.url()}#github-issue-commands`}
                            className="underline underline-offset-2 hover:text-white"
                        >
                            Settings
                        </Link>
                    </p>
                    {showSuggestions &&
                        createPortal(
                            <div
                                data-command-suggestions
                                style={position}
                                className="pointer-events-auto fixed z-[60] overflow-y-auto overscroll-contain rounded-md border border-neutral-600 bg-neutral-700 p-1 text-neutral-200 shadow-md dark:border-neutral-800 dark:bg-neutral-950"
                            >
                                <div
                                    id={`${id}-commands`}
                                    role="listbox"
                                    aria-label="Commands"
                                >
                                    {matches.map((item, index) => (
                                        <button
                                            key={item.name}
                                            id={`${id}-option-${index}`}
                                            type="button"
                                            role="option"
                                            aria-selected={selected === index}
                                            tabIndex={-1}
                                            onMouseDown={(event) =>
                                                event.preventDefault()
                                            }
                                            onMouseEnter={() =>
                                                setSelected(index)
                                            }
                                            onClick={() => complete(item.name)}
                                            className={cn(
                                                'flex w-full items-center gap-3 rounded-md px-3 py-2 text-left text-sm',
                                                selected === index &&
                                                    'bg-neutral-600 text-white dark:bg-neutral-800',
                                            )}
                                        >
                                            <span className="w-20 shrink-0 font-mono">
                                                {item.name}
                                            </span>
                                            <span className="min-w-0 truncate text-neutral-300 dark:text-neutral-400">
                                                {item.description}
                                            </span>
                                        </button>
                                    ))}
                                </div>
                                <p className="px-3 py-2 font-mono text-xs leading-relaxed text-neutral-300 dark:text-neutral-400">
                                    ↑↓ select · Tab complete · Enter run
                                </p>
                            </div>,
                            document.body,
                        )}
                </div>
            </TooltipTrigger>
            <TooltipContent className="max-w-72">
                Enable GitHub issue commands in Settings → Profile by granting
                public_repo permission.
            </TooltipContent>
        </Tooltip>
    );
}
