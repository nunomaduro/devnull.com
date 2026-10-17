import { Form, Link, usePage } from '@inertiajs/react';
import { ChartBar, Clock3 } from 'lucide-react';
import type { KeyboardEvent } from 'react';
import { useEffect, useRef, useState } from 'react';
import CommitController from '@/actions/App/Http/Controllers/CommitController';
import ScheduledCommitController from '@/actions/App/Http/Controllers/ScheduledCommitController';
import { BodyCounter } from '@/components/body-counter';
import { CommitCommands } from '@/components/commit-commands';
import {
    ImageDropOverlay,
    ImagePickerButton,
    ImagePickerPreviews,
    imageErrorFrom,
} from '@/components/commit-image-picker';
import {
    ComposerPollPreview,
    ComposerPreviewBody,
    PreviewToggle,
    useComposerPreview,
} from '@/components/composer-preview';
import { GraphRail, RailAvatar } from '@/components/graph-rail';
import InputError from '@/components/input-error';
import { LinkCardPicker } from '@/components/link-card-picker';
import { MentionTextarea } from '@/components/mention-textarea';
import type { PollDraft } from '@/components/poll-editor';
import { emptyPollDraft, PollEditor } from '@/components/poll-editor';
import { SecretWarning } from '@/components/secret-warning';
import { StashLabel } from '@/components/stash-label';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useImageAttachments } from '@/hooks/use-image-attachments';
import { useLinkCard } from '@/hooks/use-link-card';
import type { UseLinkCardReturn } from '@/hooks/use-link-card';
import { useSecretWarning } from '@/hooks/use-secret-warning';
import type { UseStashReturn } from '@/hooks/use-stash';
import {
    discardSeriesStash,
    moveCaretToEnd,
    useStash,
} from '@/hooks/use-stash';
import { useUser } from '@/hooks/use-user';
import { maxBodyLength, minBodyLength } from '@/lib/commit';
import { hasModKey, useKeyLabels } from '@/lib/keyboard';
import {
    characterCount,
    cn,
    isRejectedSubmission,
    laneColor,
} from '@/lib/utils';
import type { User } from '@/types';

function focusBody(id: string): void {
    requestAnimationFrame(() => document.getElementById(id)?.focus());
}

function useCommitSchedule() {
    const [isScheduling, setIsScheduling] = useState(false);
    const [scheduledAt, setScheduledAt] = useState('');
    const [timezone, setTimezone] = useState('');

    return {
        isScheduling,
        setIsScheduling,
        scheduledAt,
        setScheduledAt,
        timezone,
        setTimezone,
    };
}

function CommitComposerBody({
    id,
    isInDialog,
    user,
    stash,
    attachments,
    linkCard,
    schedule,
    poll,
    setPoll,
    secrets,
    isSubmittable,
    processing,
    onIssueProcessingChange,
    errors,
    onKeyDown,
    drop,
}: {
    id: string;
    isInDialog: boolean;
    user: User;
    stash: UseStashReturn;
    attachments: ReturnType<typeof useImageAttachments>;
    linkCard: UseLinkCardReturn;
    schedule: ReturnType<typeof useCommitSchedule>;
    poll: PollDraft | null;
    setPoll: (poll: PollDraft | null) => void;
    secrets: ReturnType<typeof useSecretWarning>;
    isSubmittable: boolean;
    processing: boolean;
    onIssueProcessingChange: (processing: boolean) => void;
    errors: Record<string, string>;
    onKeyDown: (event: KeyboardEvent<HTMLTextAreaElement>) => void;
    drop: () => void;
}) {
    const keys = useKeyLabels();
    const { body, isRestored, updateBody } = stash;
    const { auth } = usePage().props;
    const {
        isScheduling,
        scheduledAt,
        setScheduledAt,
        timezone,
        setTimezone,
        setIsScheduling,
    } = schedule;
    const hasImages = attachments.images.length > 0;
    const isFilled = hasImages || characterCount(body.trim()) >= minBodyLength;
    const error = errors.body ?? errors.link_preview_url;
    const textareaRef = useRef<HTMLTextAreaElement>(null);
    const preview = useComposerPreview({ body, textareaRef, isSubmittable });

    return (
        <div
            className="relative flex"
            {...(poll === null ? attachments.dropzone : {})}
        >
            {attachments.isDraggingFiles && (
                <ImageDropOverlay className="inset-2" />
            )}
            <GraphRail
                lane={laneColor(user.lane)}
                variant="staged"
                isFilled={isFilled}
                hasTail={!isInDialog}
                node={<RailAvatar user={user} isStaged={!isFilled} />}
            />

            <div
                className={cn('flex min-w-0 flex-1 pt-3 pr-4 pb-3', {
                    'border-b': !isInDialog,
                })}
            >
                <div className="min-w-0 flex-1">
                    <label htmlFor={id} className="sr-only">
                        Body
                    </label>
                    <div className="grid">
                        <MentionTextarea
                            ref={textareaRef}
                            id={id}
                            name="body"
                            rows={1}
                            value={body}
                            onValueChange={updateBody}
                            onPaste={
                                poll === null ? attachments.onPaste : undefined
                            }
                            onKeyDown={(event) =>
                                preview.handleShortcut(event) ||
                                onKeyDown(event)
                            }
                            onFocus={isRestored ? moveCaretToEnd : undefined}
                            maxLength={maxBodyLength}
                            placeholder={
                                poll ? 'Ask a question' : 'What did you ship?'
                            }
                            autoComplete="off"
                            aria-invalid={error ? true : undefined}
                            aria-describedby={`${id}-count`}
                            className={cn(
                                'col-start-1 row-start-1 block field-sizing-content max-h-[60dvh] min-h-10 w-full resize-none overflow-x-hidden overflow-y-auto bg-transparent py-1.5 text-[17px] leading-relaxed outline-none placeholder:text-muted-foreground focus-visible:outline-none',
                                { invisible: preview.isPreviewing },
                            )}
                        />
                        {preview.isPreviewing && (
                            <ComposerPreviewBody
                                preview={preview}
                                url={linkCard.link}
                                images={attachments.images}
                                descriptions={attachments.descriptions}
                                className="col-start-1 row-start-1 py-1.5"
                            />
                        )}
                    </div>

                    <SecretWarning
                        body={body}
                        findings={secrets.findings}
                        textarea={textareaRef}
                        onChange={updateBody}
                        confirming={secrets.confirming}
                        onConfirmingChange={secrets.setConfirming}
                        onApprove={secrets.approve}
                        onEdit={preview.close}
                    />
                    <InputError message={error} />

                    {!preview.isPreviewing && (
                        <ImagePickerPreviews
                            images={attachments.images}
                            descriptions={attachments.descriptions}
                            onDescribe={attachments.describe}
                            onRemove={attachments.remove}
                        />
                    )}
                    <InputError
                        message={attachments.error ?? imageErrorFrom(errors)}
                        className="mt-1"
                    />

                    {poll && (
                        <div className="mt-2 grid">
                            <PollEditor
                                id={`${id}-poll`}
                                poll={poll}
                                errors={errors}
                                onChange={setPoll}
                                onRemove={() => {
                                    setPoll(null);
                                    focusBody(id);
                                }}
                                className={cn('col-start-1 row-start-1', {
                                    invisible: preview.isPreviewing,
                                })}
                            />
                            {preview.isPreviewing && (
                                <ComposerPollPreview
                                    poll={poll}
                                    className="col-start-1 row-start-1"
                                />
                            )}
                        </div>
                    )}

                    {isScheduling && (
                        <div
                            id={`${id}-schedule`}
                            className="mt-3 space-y-2 rounded-lg border bg-muted/30 p-3"
                        >
                            <label
                                htmlFor={`${id}-scheduled-at`}
                                className="text-sm font-medium"
                            >
                                Submit at
                            </label>
                            <Input
                                id={`${id}-scheduled-at`}
                                type="datetime-local"
                                required
                                value={scheduledAt}
                                onChange={(event) => {
                                    const value = event.target.value;
                                    setScheduledAt(value);
                                    const date = new Date(value);
                                    const pad = (n: number) =>
                                        String(n).padStart(2, '0');
                                    const local = `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
                                    event.target.setCustomValidity(
                                        value && local !== value
                                            ? 'This time does not exist in your timezone. Choose another time.'
                                            : '',
                                    );
                                }}
                                aria-describedby={`${id}-schedule-help ${id}-schedule-error`}
                                aria-invalid={
                                    errors.scheduled_at ? true : undefined
                                }
                            />
                            <p
                                id={`${id}-schedule-help`}
                                className="text-xs leading-relaxed text-muted-foreground"
                            >
                                {timezone}
                                {scheduledAt &&
                                !Number.isNaN(new Date(scheduledAt).getTime())
                                    ? ` (${new Intl.DateTimeFormat(undefined, { timeZoneName: 'shortOffset' }).formatToParts(new Date(scheduledAt)).find((part) => part.type === 'timeZoneName')?.value})`
                                    : ''}
                                . Within 30 days. Private until submitted;{' '}
                                {auth.isAdmin
                                    ? 'your commit will be approved automatically.'
                                    : 'then submitted for review, not published automatically.'}
                            </p>
                            <InputError
                                id={`${id}-schedule-error`}
                                message={errors.scheduled_at}
                            />
                            <Link
                                href={ScheduledCommitController.index()}
                                className="text-xs font-medium text-primary hover:underline"
                            >
                                Scheduled commits →
                            </Link>
                        </div>
                    )}

                    <div className="mt-2 flex flex-wrap items-center justify-end gap-x-4 gap-y-2">
                        <div className="mr-auto flex min-w-0 flex-wrap items-center gap-x-3 gap-y-2">
                            <ImagePickerButton
                                count={attachments.images.length}
                                disabled={processing || poll !== null}
                                onPick={attachments.attach}
                                className="-ml-2"
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                disabled={
                                    processing || poll !== null || hasImages
                                }
                                onClick={() => {
                                    setPoll(emptyPollDraft);
                                    requestAnimationFrame(() =>
                                        document
                                            .getElementById(
                                                `${id}-poll-option-0`,
                                            )
                                            ?.focus(),
                                    );
                                }}
                                aria-label="Add poll"
                                title={
                                    hasImages
                                        ? 'A commit can have images or a poll, not both'
                                        : 'Add poll'
                                }
                                className="text-muted-foreground"
                                data-test="add-poll-button"
                            >
                                <ChartBar className="size-5" />
                            </Button>
                            <LinkCardPicker
                                linkCard={linkCard}
                                disabled={processing}
                                className="-ml-3"
                            />
                            <PreviewToggle
                                preview={preview}
                                className="-ml-3"
                            />
                            {isRestored && (
                                <StashLabel
                                    onDrop={() => {
                                        drop();
                                        focusBody(id);
                                    }}
                                    className="text-[13px]"
                                />
                            )}
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            aria-expanded={isScheduling}
                            aria-controls={`${id}-schedule`}
                            disabled={processing}
                            onClick={() => {
                                setTimezone(
                                    Intl.DateTimeFormat().resolvedOptions()
                                        .timeZone,
                                );
                                setIsScheduling(!isScheduling);
                            }}
                            className="gap-1.5 px-2 text-muted-foreground"
                        >
                            <Clock3 className="size-3.5" aria-hidden="true" />
                            Schedule
                        </Button>
                        <BodyCounter
                            id={`${id}-count`}
                            body={body}
                            className="text-[13px]"
                        />
                        <Button
                            type="submit"
                            disabled={processing || !isSubmittable}
                            title={
                                isScheduling
                                    ? `Schedule commit (${keys.combo(keys.mod, keys.enter)})`
                                    : `Commit (${keys.combo(keys.mod, keys.enter)})`
                            }
                            className="min-w-[7.5rem]"
                            data-test="create-commit-button"
                        >
                            {isScheduling
                                ? processing
                                    ? 'Scheduling…'
                                    : 'Schedule commit'
                                : processing
                                  ? 'Committing…'
                                  : 'Commit'}
                        </Button>
                    </div>
                    <CommitCommands
                        body={body}
                        images={attachments.images}
                        descriptions={attachments.descriptions}
                        disabled={
                            processing ||
                            !isSubmittable ||
                            poll !== null ||
                            isScheduling
                        }
                        hasSecrets={secrets.findings.length > 0}
                        onProcessingChange={onIssueProcessingChange}
                    />
                </div>
            </div>
        </div>
    );
}

export function CommitComposer({
    id = 'body',
    isInDialog = false,
    onCommitted,
}: {
    id?: string;
    isInDialog?: boolean;
    onCommitted?: () => void;
}) {
    const schedule = useCommitSchedule();
    const { isScheduling, scheduledAt, setScheduledAt, setIsScheduling } =
        schedule;
    const { user } = useUser();
    const stash = useStash(user.id, 'commit');
    const { body } = stash;
    const linkCard = useLinkCard(body);
    const [draftVersion, setDraftVersion] = useState(0);
    const drop = () => {
        stash.drop();
        linkCard.reset();
        setDraftVersion((version) => version + 1);
    };
    const attachments = useImageAttachments(
        stash.images,
        stash.descriptions,
        stash.updateImages,
    );
    const [poll, setPoll] = useState<PollDraft | null>(null);
    const [issueProcessing, setIssueProcessing] = useState(false);
    const secrets = useSecretWarning(body);

    const hasImages = attachments.images.length > 0;
    const isCommittable =
        (hasImages || characterCount(body.trim()) >= minBodyLength) &&
        (poll === null ||
            poll.options.every((option) => option.trim().length > 0));

    useEffect(() => discardSeriesStash(user.id), [user.id]);

    const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if (event.key === 'Enter' && hasModKey(event) && isCommittable) {
            event.preventDefault();
            event.currentTarget.form?.requestSubmit();
        }
    };

    return (
        <Form
            {...(isScheduling
                ? ScheduledCommitController.store.form()
                : CommitController.store.form())}
            onBefore={() => !issueProcessing && secrets.beforeSubmit()}
            transform={(data) => ({
                ...data,
                ...(hasImages && {
                    images: attachments.images,
                    image_descriptions: attachments.descriptions,
                }),
                ...(isScheduling && {
                    scheduled_at: new Date(scheduledAt)
                        .toISOString()
                        .replace(/\.\d{3}Z$/, '+00:00'),
                }),
            })}
            options={{ preserveScroll: true }}
            onSuccess={(page) => {
                if (isRejectedSubmission(page.flash)) {
                    return;
                }

                drop();
                setScheduledAt('');
                setIsScheduling(false);
                attachments.clear();
                setPoll(null);

                if (onCommitted) {
                    onCommitted();
                } else {
                    focusBody(id);
                }
            }}
            data-test="commit-composer"
        >
            {({ processing, errors }) => (
                <fieldset disabled={issueProcessing} className="contents">
                    <CommitComposerBody
                        key={draftVersion}
                        id={id}
                        isInDialog={isInDialog}
                        user={user}
                        stash={stash}
                        attachments={attachments}
                        linkCard={linkCard}
                        schedule={schedule}
                        poll={poll}
                        setPoll={setPoll}
                        secrets={secrets}
                        isSubmittable={isCommittable}
                        processing={processing}
                        onIssueProcessingChange={setIssueProcessing}
                        errors={errors}
                        onKeyDown={onKeyDown}
                        drop={drop}
                    />
                </fieldset>
            )}
        </Form>
    );
}
