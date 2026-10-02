const {test} = require('node:test');
const assert = require('node:assert/strict');
const {normalize, place, move, pin} = require('../public/assets/js/kaep-dashboard-layout.js');

const ids = ['overview', 'metrics', 'measures', 'journal', 'close'];
const initial = () => ({order: [...ids], pinned: []});

test('saved order is restored and new sections are appended', () => {
    assert.deepEqual(normalize({version: 1, order: ['journal', 'overview', 'retired'], pinned: ['overview']}, ids),
        {order: ['overview', 'journal', 'metrics', 'measures', 'close'], pinned: ['overview']});
});
test('corrupt layouts are rejected instead of silently dropping sections', () => {
    for (const saved of [null, {}, {version: 1, order: ['metrics', 'metrics'], pinned: []},
        {version: 1, order: ids, pinned: ['unknown']}, {version: 1, order: [7], pinned: []}]) {
        assert.throws(() => normalize(saved, ids));
    }
});
test('arrows move one visible section without losing hidden sections', () => {
    assert.deepEqual(move(initial(), 'journal', -1).order, ['overview', 'metrics', 'journal', 'measures', 'close']);
    assert.deepEqual(move(initial(), 'metrics', 1, ['overview', 'metrics', 'journal']).order, ['overview', 'measures', 'journal', 'metrics', 'close']);
    assert.deepEqual(move(initial(), 'overview', -1), initial());
    assert.deepEqual(move(initial(), 'close', 1), initial());
});
test('pin moves immediately to the top and pinned sections stay above others', () => {
    let layout = pin(initial(), 'journal');
    assert.deepEqual(layout.order, ['journal', 'overview', 'metrics', 'measures', 'close']);
    assert.deepEqual(move(layout, 'journal', 1), layout);
    assert.deepEqual(place(layout, 'metrics', 'journal', false), layout);
    layout = pin(layout, 'metrics');
    assert.deepEqual(layout.order, ['metrics', 'journal', 'overview', 'measures', 'close']);
    layout = move(layout, 'journal', -1);
    assert.deepEqual(layout.order, ['journal', 'metrics', 'overview', 'measures', 'close']);
    layout = pin(layout, 'journal');
    assert.deepEqual(layout.order, ['metrics', 'journal', 'overview', 'measures', 'close']);
    assert.deepEqual(layout.pinned, ['metrics']);
});
test('drag and drop preserves all sections and does not mutate other clients', () => {
    const clientA = initial();
    const clientB = initial();
    const moved = place(clientA, 'journal', 'overview', false);
    assert.deepEqual(moved.order, ['journal', 'overview', 'metrics', 'measures', 'close']);
    assert.deepEqual(clientA, initial());
    assert.deepEqual(clientB, initial());
    assert.deepEqual(place(moved, 'journal', 'close', true).order, ['overview', 'metrics', 'measures', 'close', 'journal']);
    assert.deepEqual(place(moved, 'missing', 'close', true), moved);
});
