/// <reference types="node" />
import assert from 'node:assert/strict';
import * as React from 'react';
import type { ReactElement } from 'react';
import { beforeEach, test, vi } from 'vite-plus/test';

type Element = ReactElement<Record<string, unknown>>;

let saved = false;
let removed: ((id: string) => void) | null = null;
let pending: { resolve: (v: unknown) => void; reject: (e: unknown) => void };
const submit = vi.fn(
    () =>
        new Promise((resolve, reject) => {
            pending = { resolve, reject };
        }),
);
const flushAll = vi.fn();
const toastError = vi.fn();

vi.doMock('react', () => ({
    ...React,
    useContext: () => removed,
}));
vi.doMock('@inertiajs/react', () => ({
    router: { flushAll },
    usePage: () => ({ props: { auth: { user: { id: 'viewer' } } } }),
    useHttp: () => ({ processing: false, submit }),
}));
vi.doMock('sonner', () => ({ toast: { error: toastError } }));
vi.doMock('@/actions/App/Http/Controllers/BookmarkController', () => ({
    default: { store: () => 'store', destroy: () => 'destroy' },
}));
vi.doMock('@/hooks/use-shared-state', () => ({
    useSharedState: (_key: string, initial: boolean) => [
        saved ?? initial,
        (value: boolean) => {
            saved = value;
        },
    ],
}));

const { BookmarkButton } =
    await import('../../resources/js/components/bookmark-button');

const commit = (is_saved: boolean) =>
    ({ id: 'commit', user_id: 'author', status: 'approved', is_saved }) as never;

function button(): Element {
    return BookmarkButton({ commit: commit(saved) }) as unknown as Element;
}

function isFilled(): boolean {
    const icon = button().props.children as Element;

    return String(icon.props.className).includes('fill-current');
}

function isPressed(): boolean {
    return button().props['aria-pressed'] === true;
}

function click(): Promise<void> {
    return (button().props.onClick as () => Promise<void>)();
}

beforeEach(() => {
    vi.clearAllMocks();
    saved = false;
    removed = null;
});

test('clicking an unsaved commit fills the bookmark while the request is pending', async () => {
    const clicked = click();

    assert.equal(isPressed(), true);
    assert.equal(isFilled(), true);

    pending.resolve({ is_saved: true });
    await clicked;
});

test('the bookmark stays pressed after the request resolves as saved', async () => {
    const clicked = click();

    pending.resolve({ is_saved: true });
    await clicked;

    assert.equal(isPressed(), true);
    assert.equal(isFilled(), true);
    assert.equal(flushAll.mock.calls.length, 1);
    assert.equal(toastError.mock.calls.length, 0);
});

test('the bookmark reverts and toasts an error when the request rejects', async () => {
    const clicked = click();

    assert.equal(isPressed(), true);

    pending.reject(new Error('failed'));
    await clicked;

    assert.equal(isPressed(), false);
    assert.equal(isFilled(), false);
    assert.equal(toastError.mock.calls.length, 1);
});

test('unsaving reports the removed commit id to the removal context', async () => {
    saved = true;
    removed = vi.fn();

    const clicked = click();

    assert.equal(isPressed(), false);

    pending.resolve({ is_saved: false });
    await clicked;

    assert.equal(isPressed(), false);
    assert.deepEqual((removed as ReturnType<typeof vi.fn>).mock.calls, [
        ['commit'],
    ]);
});
