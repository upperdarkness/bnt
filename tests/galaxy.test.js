const test = require('node:test');
const assert = require('node:assert/strict');
const {buildGraph} = require('../public/assets/js/galaxy.js');

test('preserves directed navigation, isolated sectors, and non-contiguous IDs', () => {
    const graph = buildGraph({sectors: [{id: 2}, {id: 9}, {id: 100}], links: [[2, 9], [9, 777]]});
    assert.deepEqual(graph.outgoing.get(2), [9]);
    assert.deepEqual(graph.outgoing.get(9), []);
    assert.deepEqual(graph.outgoing.get(100), []);
    assert.deepEqual(graph.links, [[2, 9]]);
});

test('empty and single-sector maps have finite coordinates', () => {
    assert.equal(buildGraph({sectors: [], links: []}).nodes.length, 0);
    const node = buildGraph({sectors: [{id: 17}], links: []}).nodes[0];
    assert.equal(node.x, 0);
    assert.equal(node.y, 0);
});

test('5000 sectors produce deterministic bounded positions without modifying input', () => {
    const data = {sectors: Array.from({length: 5000}, (_, i) => ({id: i + 1, name: `Sector ${i + 1}`})), links: []};
    const first = buildGraph(data), second = buildGraph(data);
    assert.deepEqual(first.nodes, second.nodes);
    assert.equal(data.sectors[0].x, undefined);
    for (const node of first.nodes) {
        assert.ok(Number.isFinite(node.x) && Number.isFinite(node.y));
        assert.ok(Math.abs(node.x) <= 1000 && Math.abs(node.y) <= 800);
    }
});
