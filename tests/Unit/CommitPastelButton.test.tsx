/// <reference types="node" />
import assert from 'node:assert/strict';
import * as React from 'react';
import type { ReactElement, ReactNode } from 'react';
import { beforeEach, test, vi } from 'vite-plus/test';

type Element = ReactElement<Record<string, unknown>>;
type Update = number | ((current: number) => number);

const day = '2026-10-16';
const values = new Map<string, number>();
let refs: unknown[] = [];
let cursor = 0;
let pending: { resolve: (v: unknown) => void; reject: (e: unknown) => void };
const submit = vi.fn(
    () =>
        new Promise((resolve, reject) => {
            pending = { resolve, reject };
        }),
);
const flushAll = vi.fn();
const toastError = vi.fn();
const toast = Object.assign(vi.fn(), { error: toastError });

vi.doMock('react', () => ({
    ...React,
    useRef: (initial: unknown) => {
        const index = cursor++;
        if (!(index in refs)) {
            refs[index] = { current: initial };
        }
        return refs[index];
    },
}));
vi.doMock('@inertiajs/react', () => ({
    router: { flushAll },
    usePage: () => ({
        props: {
            auth: { user: { id: 'viewer' }, pasteis: { remaining: 3, day } },
        },
    }),
    useHttp: () => ({ processing: false, submit }),
}));
vi.doMock('sonner', () => ({ toast }));
vi.doMock('@/actions/App/Http/Controllers/CommitPastelController', () => ({
    store: () => 'store',
}));
vi.doMock('@/hooks/use-now', () => ({
    useNow: () => new Date(`${day}T12:00:00Z`),
}));
vi.doMock('@/lib/throttle', () => ({ toastWhenThrottled: () => false }));
vi.doMock('@/hooks/use-shared-state', () => ({
    refreshSharedState: (fresh: Map<string, number>) =>
        fresh.forEach((value, key) => values.set(key, value)),
    useSharedState: (key: string, initial: number) => [
        values.has(key) ? values.get(key) : initial,
        (update: Update) =>
            values.set(
                key,
                typeof update === 'function'
                    ? update(values.has(key) ? values.get(key)! : initial)
                    : update,
            ),
    ],
}));

const { CommitPastelButton } =
    await import('../../resources/js/components/commit-pastel-button');
const { commitStateKey } = await import('../../resources/js/lib/commit-state');

const commit = {
    id: 'commit',
    user_id: 'author',
    status: 'approved',
    pasteis_count: 5,
} as never;
const countKey = commitStateKey('pasteis_count', 'commit');
const allowanceKey = `pasteis-allowance:viewer:${day}`;

function render(): ReactNode {
    cursor = 0;

    return CommitPastelButton({ commit });
}

function findClickable(node: ReactNode): Element | null {
    if (!React.isValidElement(node)) {
        return null;
    }

    const element = node as Element;

    if (typeof element.props.onClick === 'function') {
        return element;
    }

    for (const child of React.Children.toArray(
        element.props.children as ReactNode,
    )) {
        const found = findClickable(child);

        if (found) {
            return found;
        }
    }

    return null;
}

function click(): Promise<void> {
    const clickable = findClickable(render());

    assert.ok(clickable);

    return (clickable.props.onClick as () => Promise<void>)();
}

function count(): number {
    void render();

    return values.get(countKey) ?? 5;
}

function allowance(): number {
    void render();

    return values.get(allowanceKey) ?? 3;
}

beforeEach(() => {
    vi.clearAllMocks();
    vi.stubGlobal('window', {
        matchMedia: () => ({ matches: true }),
    });
    values.clear();
    refs = [];
});

test('awarding a pastel updates the count and allowance while pending', async () => {
    const clicked = click();

    assert.equal(count(), 6);
    assert.equal(allowance(), 2);

    pending.resolve({
        pasteis_count: 6,
        remaining: 2,
        day,
        recipient: { id: 'author', pasteis_received: 9 },
    });
    await clicked;
});

test('the server response settles the count, allowance and recipient', async () => {
    const clicked = click();

    pending.resolve({
        pasteis_count: 6,
        remaining: 2,
        day,
        recipient: { id: 'author', pasteis_received: 9 },
    });
    await clicked;

    assert.equal(count(), 6);
    assert.equal(allowance(), 2);
    assert.equal(values.get(commitStateKey('pasteis_received', 'author')), 9);
    assert.equal(flushAll.mock.calls.length, 1);
    assert.equal(toastError.mock.calls.length, 0);
});

test('a rejected request restores the count and allowance', async () => {
    const clicked = click();

    assert.equal(count(), 6);

    pending.reject(new Error('failed'));
    await clicked;

    assert.equal(count(), 5);
    assert.equal(allowance(), 3);
    assert.equal(toastError.mock.calls.length, 1);
    assert.equal(flushAll.mock.calls.length, 0);
});
