/* Deterministic schematic coordinates. All connections come from the database. */
(function () {
    'use strict';
    function buildGraph(data) {
        const count = data.sectors.length;
        const nodes = data.sectors.map((sector, index) => {
            const radius = count === 1 ? 0 : Math.sqrt((index + .5) / Math.max(1, count)) * 1000;
            const angle = index * 2.399963229728653;
            return {...sector, x: Math.cos(angle) * radius, y: Math.sin(angle) * radius * .8};
        });
        const byId = new Map(nodes.map(node => [node.id, node]));
        const outgoing = new Map(nodes.map(node => [node.id, []]));
        const links = data.links.filter(([from, to]) => byId.has(from) && byId.has(to));
        for (const [from, to] of links) outgoing.get(from).push(to);
        return {nodes, byId, outgoing, links};
    }
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = {buildGraph};
        return;
    }
    const source = document.getElementById('galaxy-data');
    const canvas = document.getElementById('galaxy-canvas');
    if (!source || !canvas) return;
    const context = canvas.getContext('2d');
    if (!context) return; // The server-rendered search and movement remain available.
    const data = JSON.parse(source.textContent);
    const graph = buildGraph(data);
    const controls = document.querySelector('.map-controls');
    controls.hidden = false;
    const input = document.getElementById('map-sector');
    const feedback = document.getElementById('map-feedback');
    const allLinks = document.getElementById('map-all-links');
    const moveForm = document.getElementById('map-move');
    let selected = data.selected;
    let width = 0, height = 0, scale = 1, minScale = .1, offsetX = 0, offsetY = 0;
    let frame = null, drag = null;
    const outgoingCurrent = new Set(graph.outgoing.get(data.current) || []);

    function point(node) { return {x: node.x * scale + offsetX, y: node.y * scale + offsetY}; }
    function requestDraw() {
        if (frame !== null) return;
        frame = requestAnimationFrame(() => { frame = null; draw(); });
    }
    function fit() {
        minScale = Math.max(.05, Math.min((width - 50) / 2100, (height - 50) / 1700));
        scale = minScale; offsetX = width / 2; offsetY = height / 2; requestDraw();
    }
    function zoom(factor, x = width / 2, y = height / 2) {
        const next = Math.max(minScale, Math.min(5, scale * factor));
        const ratio = next / scale;
        offsetX = x - (x - offsetX) * ratio; offsetY = y - (y - offsetY) * ratio;
        scale = next; requestDraw();
    }
    function center(id) {
        const node = graph.byId.get(id);
        if (!node) return;
        scale = Math.max(minScale * 2.5, scale);
        offsetX = width / 2 - node.x * scale; offsetY = height / 2 - node.y * scale;
        requestDraw();
    }
    function select(id, focus = false) {
        const node = graph.byId.get(id);
        if (!node) { feedback.textContent = 'That sector does not exist.'; return false; }
        selected = id; input.value = id; feedback.textContent = '';
        document.getElementById('map-sector-label').textContent = `SECTOR ${id}`;
        document.getElementById('map-sector-name').textContent = node.name;
        document.getElementById('map-sector-kind').textContent = node.starbase ? 'Protected starbase' : node.port === 'none' ? 'Empty space' : `${node.port} port`;
        const canMove = outgoingCurrent.has(id) && id !== data.current;
        const routes = (graph.outgoing.get(id) || []).length;
        document.getElementById('map-route-note').textContent = (id === data.current ? 'You are here. ' : canMove ? 'Direct warp link from your sector. ' : 'No direct outgoing link from your sector. ') + `${routes} outgoing routes.`;
        moveForm.hidden = !canMove;
        moveForm.action = `/move/${id}`;
        if (focus) center(id);
        requestDraw();
        return true;
    }
    function edge(from, to, color, arrow) {
        const a = point(graph.byId.get(from)), b = point(graph.byId.get(to));
        context.strokeStyle = color; context.lineWidth = arrow ? 1.3 : .5;
        context.beginPath(); context.moveTo(a.x, a.y); context.lineTo(b.x, b.y); context.stroke();
        if (arrow) {
            const angle = Math.atan2(b.y - a.y, b.x - a.x);
            const x = b.x - Math.cos(angle) * 9, y = b.y - Math.sin(angle) * 9;
            context.fillStyle = color;
            context.beginPath(); context.moveTo(x, y);
            context.lineTo(x - Math.cos(angle - .5) * 7, y - Math.sin(angle - .5) * 7);
            context.lineTo(x - Math.cos(angle + .5) * 7, y - Math.sin(angle + .5) * 7);
            context.closePath(); context.fill();
        }
    }
    function draw() {
        context.clearRect(0, 0, width, height);
        // Stable procedural stars, drawn only on interaction/resizing.
        for (let i = 0; i < 130; i++) {
            const x = ((i * 73.13) % 997) / 997 * width;
            const y = ((i * 137.7) % 991) / 991 * height;
            context.fillStyle = i % 4 ? '#9daec13b' : '#d3e9ff80';
            context.fillRect(x, y, i % 4 ? 1 : 1.5, 1.5);
        }
        if (!graph.nodes.length) {
            context.fillStyle = '#b4c9db'; context.textAlign = 'center'; context.font = '15px system-ui';
            context.fillText('No sectors to chart yet', width / 2, height / 2); return;
        }
        if (allLinks.checked) for (const [from, to] of graph.links) edge(from, to, '#678ba930', false);
        for (const to of graph.outgoing.get(selected) || []) edge(selected, to, '#8bdde4a8', true);
        const neighbors = new Set(graph.outgoing.get(selected) || []);
        for (const node of graph.nodes) {
            const p = point(node);
            if (p.x < -20 || p.x > width + 20 || p.y < -20 || p.y > height + 20) continue;
            const current = node.id === data.current, active = node.id === selected;
            const radius = current || active ? 5 : Math.max(2, Math.min(3.8, scale * 3));
            context.fillStyle = current ? '#a5f3cc' : node.starbase ? '#ffd28a' : node.port !== 'none' ? '#7fdef4' : '#8b9db5';
            context.beginPath();
            if (node.starbase) {
                context.moveTo(p.x, p.y - radius - 1); context.lineTo(p.x + radius + 1, p.y);
                context.lineTo(p.x, p.y + radius + 1); context.lineTo(p.x - radius - 1, p.y); context.closePath();
            } else context.arc(p.x, p.y, radius, 0, Math.PI * 2);
            context.fill();
            if (current || active) {
                context.strokeStyle = current ? '#a5f3cc' : '#fff'; context.lineWidth = 1.5;
                context.beginPath(); context.arc(p.x, p.y, 10, 0, Math.PI * 2); context.stroke();
            }
            if (active || current || neighbors.has(node.id) || scale > .9) {
                context.fillStyle = active || current ? '#fff' : '#d2e0ec';
                context.font = `${current ? 'bold ' : ''}11px system-ui`; context.textAlign = 'left';
                context.fillText(`${node.id}${current ? ' · YOU' : ''}`, p.x + 12, p.y - 9);
            }
        }
    }
    function resize() {
        const rect = canvas.getBoundingClientRect();
        const oldWidth = width, oldHeight = height;
        width = rect.width; height = rect.height;
        const ratio = Math.min(window.devicePixelRatio || 1, 2);
        canvas.width = Math.round(width * ratio); canvas.height = Math.round(height * ratio);
        context.setTransform(ratio, 0, 0, ratio, 0, 0);
        if (!oldWidth) fit();
        else {
            offsetX += (width - oldWidth) / 2; offsetY += (height - oldHeight) / 2;
            minScale = Math.max(.05, Math.min((width - 50) / 2100, (height - 50) / 1700));
            requestDraw();
        }
    }
    canvas.addEventListener('pointerdown', event => {
        if (event.button !== 0) return;
        drag = {id: event.pointerId, x: event.clientX, y: event.clientY, moved: 0};
        canvas.setPointerCapture(event.pointerId);
    });
    canvas.addEventListener('pointermove', event => {
        if (!drag || drag.id !== event.pointerId) return;
        const dx = event.clientX - drag.x, dy = event.clientY - drag.y;
        drag.moved += Math.abs(dx) + Math.abs(dy); drag.x = event.clientX; drag.y = event.clientY;
        offsetX += dx; offsetY += dy; requestDraw();
    });
    canvas.addEventListener('pointerup', event => {
        if (!drag || drag.id !== event.pointerId) return;
        if (drag.moved < 6) {
            const rect = canvas.getBoundingClientRect();
            const x = event.clientX - rect.left, y = event.clientY - rect.top;
            let closest = null, distance = 14;
            for (const node of graph.nodes) {
                const p = point(node), d = Math.hypot(p.x - x, p.y - y);
                if (d < distance) { closest = node; distance = d; }
            }
            if (closest) select(closest.id);
        }
        drag = null;
    });
    canvas.addEventListener('pointercancel', () => { drag = null; });
    canvas.addEventListener('wheel', event => {
        event.preventDefault(); const rect = canvas.getBoundingClientRect();
        zoom(event.deltaY < 0 ? 1.15 : 1 / 1.15, event.clientX - rect.left, event.clientY - rect.top);
    }, {passive: false});
    canvas.addEventListener('keydown', event => {
        const steps = {ArrowLeft: [45, 0], ArrowRight: [-45, 0], ArrowUp: [0, 45], ArrowDown: [0, -45]};
        if (steps[event.key]) { event.preventDefault(); offsetX += steps[event.key][0]; offsetY += steps[event.key][1]; requestDraw(); }
        else if (['+', '=', '-', 'Home'].includes(event.key)) {
            event.preventDefault(); if (event.key === 'Home') { select(data.current); center(data.current); }
            else zoom(event.key === '-' ? 1 / 1.4 : 1.4);
        }
    });
    controls.addEventListener('click', event => {
        const action = event.target.dataset.mapAction;
        if (action === 'in') zoom(1.4); else if (action === 'out') zoom(1 / 1.4);
        else if (action === 'fit') fit(); else if (action === 'home') { select(data.current); center(data.current); }
    });
    allLinks.addEventListener('change', requestDraw);
    document.getElementById('map-search').addEventListener('submit', event => { event.preventDefault(); select(Number(input.value), true); });
    document.querySelectorAll('[data-select-sector]').forEach(link => link.addEventListener('click', event => {
        event.preventDefault(); select(Number(link.dataset.selectSector), true);
    }));
    new ResizeObserver(resize).observe(canvas);
    resize();
    if (selected !== null) select(selected);
}());
