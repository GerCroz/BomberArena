import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const rendererSource = readFileSync(new URL('../../resources/js/game/rendering/gameRenderer.js', import.meta.url), 'utf8')
    .replace(/^import .*;$/gm, '')
    .replace(/^export /gm, '');

test('adjacent players retain independent animated positions and the local player renders on top', () => {
    let now = 0;
    const draws = [];
    const canvasContext = { clearRect() {}, drawImage() {} };
    const canvas = { getContext: () => canvasContext };
    const context = vm.createContext({
        performance: { now: () => now },
        document: { createElement: () => ({ getContext: () => canvasContext }) },
        drawTile() {},
        drawCharacter: (ctx, x, y, size, player, time, local) => draws.push({ id: player.id, x, y, local }),
    });
    vm.runInContext(rendererSource, context);
    context.canvas = canvas;
    context.state = {
        grid: [[0]],
        players: {
            local: { id: 'local', x: 1, y: 1, alive: true },
            rival: { id: 'rival', x: 1, y: 2, alive: true },
        },
    };
    vm.runInContext("const renderer = new GameRenderer(canvas); renderer.render(state, 'local');", context);
    draws.length = 0;
    now = 25;

    vm.runInContext("renderer.render(state, 'local');", context);

    assert.deepEqual(draws, [
        { id: 'rival', x: 75, y: 125, local: false },
        { id: 'local', x: 75, y: 75, local: true },
    ]);

    draws.length = 0;
    context.state.players.local.x = 2;
    now = 50;

    vm.runInContext("renderer.render(state, 'local');", context);

    assert.deepEqual(draws, [
        { id: 'rival', x: 75, y: 125, local: false },
        { id: 'local', x: 100, y: 75, local: true },
    ]);
});

test('a keyboard tap sends exactly one step and release never sends a continuous walking intent', async () => {
    const client = createClient();
    client.run('initKeyboard();');
    const press = { key: 'ArrowRight', preventDefault() {} };
    client.listeners.get('keydown')(press);
    client.listeners.get('keydown')(press);
    await new Promise(resolve => setImmediate(resolve));

    assert.equal(client.calls.length, 1);
    assert.equal(JSON.parse(client.calls[0][1].body).actions[0].type, 'move');
    assert.equal(client.run('gameState.players[CURRENT_PLAYER_ID].x'), 1);
    client.listeners.get('keyup')({ key: 'ArrowRight' });
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(client.calls.length, 1);
    assert.equal(client.run('walkRefreshInterval'), null);
});

test('quick escape tap during pending bomb response sends a step instead of losing it to stop', async () => {
    const responses = [];
    const client = createClient(() => new Promise(resolve => responses.push(resolve)));
    client.run('initKeyboard(); sendBomb();');
    client.listeners.get('keydown')({ key: 'ArrowRight', preventDefault() {} });
    client.listeners.get('keyup')({ key: 'ArrowRight' });

    assert.equal(client.calls.length, 1);
    assert.equal(client.run('gameState.players[CURRENT_PLAYER_ID].x'), 1);
    assert.equal(client.run('visualPrediction.x'), 2);
    assert.equal(client.run('pendingActionQueue.some(action => action.type === "move" && action.direction === "right")'), true);
    responses[0]({ ok: true, json: async () => ({ success: true }) });
    await new Promise(resolve => setImmediate(resolve));

    const next = JSON.parse(client.calls[1][1].body).actions;
    assert.ok(next.some(action => action.type === 'move' && action.direction === 'right'));
    assert.equal(next.filter(action => action.type === 'move').length, 1);
    assert.equal(next.some(action => action.type === 'walk' || action.type === 'stop'), false);
    responses[1]({ ok: true, json: async () => ({ success: true }) });
    await new Promise(resolve => setImmediate(resolve));
});

test('holding during delayed HTTP never queues repeat steps after release', async () => {
    const responses = [];
    const client = createClient(() => new Promise(resolve => responses.push(resolve)));
    client.run(`beginWalk('right');`);
    for (const callback of client.intervals.values()) callback();
    client.run('endWalk();');

    assert.equal(client.run('pendingActionQueue.some(action => action.type === "move")'), false);
    responses[0]({ ok: true, json: async () => ({ success: true }) });
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(client.calls.length, 1);
    assert.equal(client.run('pendingActionQueue.length'), 0);
});

test('visual anticipation changes drawing while logical position and bomb placement remain confirmed', () => {
    const renderer = createRenderer();
    const state = { seed: 42, grid: [[0]], players: { a: { id: 'a', alive: true, x: 1, y: 1 } } };
    renderer.frame(0, state);
    renderer.run('renderer.localPreview = { id: "a", x: 2, y: 1 };');

    renderer.frame(25, state);

    assert.equal(renderer.draws[0].x, 100);
    assert.equal(state.players.a.x, 1);
    assert.equal(state.players.a.y, 1);
});

test('short press with a fast response does not repeat and intentional hold stops generating steps on release', async () => {
    const client = createClient();
    client.run(`beginWalk('right');`);
    await new Promise(resolve => setImmediate(resolve));
    client.frame(650);
    for (const callback of client.intervals.values()) callback();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(client.calls.length, 1);

    client.frame(900);
    for (const callback of client.intervals.values()) callback();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(client.calls.length, 2);
    client.run('endWalk();');
    client.frame(1400);
    for (const callback of client.intervals.values()) callback();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(client.calls.length, 2);
    assert.equal(client.run('pendingActionQueue.length'), 0);
});

test('two separate taps during a delayed bomb remain exactly two single-step requests', async () => {
    const responses = [];
    const client = createClient(() => new Promise(resolve => responses.push(resolve)));
    client.run('sendBomb(); beginWalk("right"); endWalk(); beginWalk("down"); endWalk();');
    responses[0]({ ok: true, json: async () => ({ success: true }) });
    await new Promise(resolve => setImmediate(resolve));
    assert.deepEqual(JSON.parse(client.calls[1][1].body).actions, [{ type: 'move', direction: 'right' }]);
    responses[1]({ ok: true, json: async () => ({ success: true }) });
    await new Promise(resolve => setImmediate(resolve));
    assert.deepEqual(JSON.parse(client.calls[2][1].body).actions, [{ type: 'move', direction: 'down' }]);
    responses[2]({ ok: true, json: async () => ({ success: true }) });
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(client.calls.length, 3);
});

function createRenderer() {
    let now = 0;
    let boards = 0;
    const draws = [];
    const ctx = { clearRect() {}, drawImage() {}, strokeRect() {}, fillRect() {}, fillText() {} };
    const context = vm.createContext({
        performance: { now: () => now }, document: { createElement: () => ({ getContext: () => ctx }) },
        drawTile() { boards++; }, drawCharacter: (ctx, x, y, size, player) => draws.push({ id: player.id, x, y }),
    });
    vm.runInContext(rendererSource, context);
    context.canvas = { getContext: () => ctx };
    vm.runInContext('const renderer = new GameRenderer(canvas);', context);
    return { run: code => vm.runInContext(code, context), context, draws,
        frame(time, state) { now = time; context.state = state; draws.length = 0; vm.runInContext("renderer.render(state, 'a');", context); },
        boards: () => boards,
    };
}

const template = readFileSync(new URL('../../resources/views/bomber/arena.blade.php', import.meta.url), 'utf8');
const script = template.slice(template.indexOf('const ROOM_CODE ='), template.lastIndexOf('</script>'))
    .replace(/\{\{[^}]*\}\}/g, '180')
    .replace('@json($room)', 'initialRoom')
    .replace('@json($room->game_state)', 'initialState');

function createClient(fetchResponse = async () => ({ ok: true, json: async () => ({ success: true }) })) {
    const nodes = new Map();
    const listeners = new Map();
    const calls = [];
    const alerts = [];
    const intervals = new Map();
    const frames = new Map();
    let now = 500;
    let nextId = 1;
    const state = {
        round_id: 'old_round', started_at: 100, version: 90, duration_seconds: 180,
        rules: { countdown_seconds: 0 }, bombs: {}, powerups: {}, is_game_over: false,
        grid: Array.from({ length: 11 }, () => Array(13).fill(0)),
        players: { '180': { id: '180', x: 1, y: 1, alive: true, max_bombs: 1 }, other: { id: 'other', x: 11, y: 9, alive: true } },
    };
    const context = vm.createContext({
        initialState: state, initialRoom: { status: 'playing', players: [] }, console,
        Date, performance: { now: () => now },
        AbortController, AbortSignal,
        haptics: { heavy() {}, light() {}, explosion() {}, victory() {} },
        alert: message => alerts.push(message),
        fetch: (...args) => { calls.push(args); return fetchResponse(...args); },
        window: { addEventListener: (name, callback) => listeners.set(name, callback) },
        document: {
            addEventListener() {},
            getElementById(id) {
                if (!nodes.has(id)) {
                    const classes = new Set(['hidden']);
                    nodes.set(id, { classList: {
                        add: name => classes.add(name), remove: name => classes.delete(name),
                        contains: name => classes.has(name),
                        toggle: (name, enabled) => enabled ? classes.add(name) : classes.delete(name),
                    }, appendChild() {}, innerHTML: '' });
                }
                return nodes.get(id);
            },
            createElement: () => ({ innerHTML: '', innerText: '', appendChild() {} }),
        },
        setInterval: callback => { const id = nextId++; intervals.set(id, callback); return id; },
        clearInterval: id => intervals.delete(id),
        setTimeout: () => nextId++,
        requestAnimationFrame: callback => { const id = nextId++; frames.set(id, callback); return id; },
        cancelAnimationFrame: id => frames.delete(id),
    });
    vm.runInContext(script, context);
    return { context, calls, alerts, intervals, listeners, nodes,
        frame(time) { now = time; const pending = [...frames.values()]; frames.clear(); pending.forEach(callback => callback()); },
        run: source => vm.runInContext(source, context) };
}

function startRematch(client) {
    client.run(`handleIncomingEvent({ type: 'game_started', data: { state: { ...gameState, round_id: 'new_round', started_at: 200, version: 1, bombs: {}, is_game_over: false }, rules: { countdown_seconds: 0 } } });`);
}

test('rematch resets versions, pending input and explosion effects and accepts new movements', () => {
    const client = createClient();
    client.run(`pendingActionQueue = [{ type: 'bomb' }]; gameRenderer = { activeExplosions: ['old'], invalidateBoard() {}, render() {} };`);

    startRematch(client);
    client.run(`handleIncomingEvent({ type: 'player_moved', data: { round_id: 'new_round', version: 2, player_id: 'other', x: 10, y: 9 } });`);

    assert.equal(client.run('lastProcessedVersion'), 2);
    assert.equal(client.run('pendingActionQueue.length'), 0);
    assert.equal(client.run('gameRenderer.activeExplosions.length'), 0);
    assert.equal(client.run('gameState.players.other.x'), 10);
});

test('old game over and old game started events cannot finish or replace a rematch', () => {
    const client = createClient();
    startRematch(client);

    client.run(`handleIncomingEvent({ type: 'game_over', data: { round_id: 'old_round', winner_id: 'other' } });
        handleIncomingEvent({ type: 'game_started', data: { state: { round_id: 'old_round', started_at: 100 } } });`);

    assert.equal(client.run('gameState.round_id'), 'new_round');
    assert.equal(client.run('currentRoom.status'), 'playing');
});

test('duplicate start does not restart the countdown or discard new input', () => {
    const client = createClient();
    startRematch(client);
    client.run(`pendingActionQueue = [{ type: 'move', direction: 'right' }];`);

    startRematch(client);

    assert.equal(client.run('roundGeneration'), 1);
    assert.equal(client.run('pendingActionQueue.length'), 1);
});

test('an old action response cannot insert bombs or reconcile positions in a rematch', async () => {
    let resolveResponse;
    const client = createClient(() => new Promise(resolve => { resolveResponse = resolve; }));
    client.run(`pendingActionQueue = [{ type: 'bomb', temp_id: 'optimistic_old' }];`);
    const pending = client.run('flushActions()');
    startRematch(client);

    resolveResponse({ ok: true, json: async () => ({ success: true, pos: { x: 7, y: 7 }, bombs: [{ id: 'old_bomb', temp_id: 'optimistic_old' }] }) });
    await pending;

    assert.equal(client.run('Object.keys(gameState.bombs).length'), 0);
    assert.equal(client.run('gameState.players[CURRENT_PLAYER_ID].x'), 1);
});

test('rejected bomb placement removes the optimistic bomb and allows retry', async () => {
    const client = createClient(async () => ({ ok: true, json: async () => ({ success: true, rejected: 1, bombs: [], events: [] }) }));
    client.run(`gameState.bombs.optimistic = { _optimistic: true }; pendingActionQueue = [{ type: 'bomb', temp_id: 'optimistic' }];`);

    await client.run('flushActions()');

    assert.equal(client.run('Object.keys(gameState.bombs).length'), 0);
    assert.equal(client.run('isFlushingActions'), false);
});

test('only one server tick can be in flight and it includes the round identifier', async () => {
    let resolveResponse;
    const client = createClient(() => new Promise(resolve => { resolveResponse = resolve; }));

    const firstTick = client.run('sendServerTick()');
    await client.run('sendServerTick()');

    assert.equal(client.calls.length, 1);
    assert.equal(JSON.parse(client.calls[0][1].body).round_id, 'old_round');
    resolveResponse({ ok: true, json: async () => ({ success: true }) });
    await firstTick;
    assert.equal(client.run('serverTickInFlight'), false);
});

test('late explosions still apply after a higher version and cannot resurrect detonated bombs', () => {
    const client = createClient();
    client.run(`gameRenderer = { addExplosion() {} };`);

    client.run(`handleIncomingEvent({ type: 'bomb_exploded', data: { round_id: 'old_round', version: 2, event_id: 'explosion', root_bomb_id: 'bomb', detonated_bomb_ids: ['bomb'] } });
        handleIncomingEvent({ type: 'bomb_placed', data: { round_id: 'old_round', version: 1, bomb: { id: 'bomb' } } });`);

    assert.equal(client.run('gameState.bombs.bomb'), undefined);
    assert.equal(client.run("detonatedBombIds.has('bomb')"), true);
});

test('HTTP and WebSocket copies of an explosion are applied once without deleting unchained bombs', () => {
    const client = createClient();
    client.run(`let explosionCount = 0; gameRenderer = { addExplosion() { explosionCount++; } }; gameState.bombs.unchained = { x: 1, y: 1 };`);

    client.run(`const explosion = { type: 'bomb_exploded', data: { round_id: 'old_round', version: 91, event_id: 'same_event', root_bomb_id: 'root', detonated_bomb_ids: ['root'], explosion_tiles: [{ x: 1, y: 1 }] } };
        handleIncomingEvent(explosion); handleIncomingEvent(explosion);`);

    assert.equal(client.run('explosionCount'), 1);
    assert.ok(client.run('gameState.bombs.unchained'));
});

test('game over stops timers and further bomb inputs', () => {
    const client = createClient();
    client.run('startGameLoop()');

    client.run(`handleIncomingEvent({ type: 'game_over', data: { round_id: 'old_round', winner_id: null, finish_reason: 'ALL_PLAYERS_ELIMINATED' } }); sendBomb();`);

    assert.equal(client.run('currentRoom.status'), 'finished');
    assert.equal(client.run('animFrameId'), null);
    assert.equal(client.intervals.size, 0);
    assert.equal(client.calls.length, 0);
});

test('rematch errors are visible and permit another attempt', async () => {
    const client = createClient(async () => ({ ok: false, json: async () => ({ success: false, error: 'Servidor ocupado, reintenta' }) }));
    client.run("gameState.is_game_over = true; gameState.winner_id = 'other';");

    await client.run('requestRematch()');

    assert.equal(client.alerts[0], 'Servidor ocupado, reintenta');
    assert.equal(client.run('rematchInFlight'), false);
});

test('Space plants a bomb even during movement throttling', async () => {
    const client = createClient();
    client.run('initKeyboard(); lastMoveTime = performance.now();');

    client.listeners.get('keydown')({ code: 'Space', key: ' ', preventDefault() {} });
    await new Promise(resolve => setImmediate(resolve));

    assert.equal(client.calls.length, 1);
    assert.equal(JSON.parse(client.calls[0][1].body).actions[0].type, 'bomb');
});

test('a server snapshot recovers missed eliminations and game over', () => {
    const client = createClient();

    client.run(`applyStateSnapshot({ ...gameState, version: 91, is_game_over: true, winner_id: null, finish_reason: 'ALL_PLAYERS_ELIMINATED', players: { '180': { alive: false }, other: { alive: false } } });`);

    assert.equal(client.run('gameState.is_game_over'), true);
    assert.equal(client.run('currentRoom.status'), 'finished');
    assert.equal(client.run('gameState.players.other.alive'), false);
});

test('a snapshot can discover a new rematch without its WebSocket event', () => {
    const client = createClient();

    client.run(`applyStateSnapshot({ ...gameState, round_id: 'new_round', started_at: 200, version: 1 });`);

    assert.equal(client.run('gameState.round_id'), 'new_round');
    assert.equal(client.run('lastProcessedVersion'), 1);
});

test('snapshots reconcile confirmed movement while an action is pending', () => {
    const client = createClient();
    client.run(`isFlushingActions = true; gameState.players[CURRENT_PLAYER_ID].x = 3;`);

    client.run(`applyStateSnapshot({ ...gameState, version: 91, players: { '180': { x: 1, y: 1, alive: true } } });`);

    assert.equal(client.run('gameState.players[CURRENT_PLAYER_ID].x'), 1);
});

test('a stalled old action is aborted so new rematch commands can be sent', async () => {
    const responses = [];
    const client = createClient(() => new Promise(resolve => responses.push(resolve)));
    client.run(`pendingActionQueue = [{ type: 'move', direction: 'right' }];`);
    const oldRequest = client.run('flushActions()');
    const oldSignal = client.calls[0][1].signal;

    startRematch(client);
    client.run(`pendingActionQueue = [{ type: 'bomb' }];`);
    const newRequest = client.run('flushActions()');

    assert.equal(oldSignal.aborted, true);
    assert.equal(client.calls.length, 2);
    responses[0]({ ok: true, json: async () => ({ success: true }) });
    await oldRequest;
    assert.equal(client.run('isFlushingActions'), true);
    responses[1]({ ok: true, json: async () => ({ success: true }) });
    await newRequest;
    assert.equal(client.run('isFlushingActions'), false);
});

test('countdown blocks both bomb placement and movement', () => {
    const client = createClient();
    client.run('isCountingDown = true;');

    client.run("sendBomb(); predictAndMove('right');");

    assert.equal(client.run('gameState.players[CURRENT_PLAYER_ID].x'), 1);
    assert.equal(client.calls.length, 0);
});

test('a finished client discovers a rematch through room state if the start event is lost', async () => {
    let response;
    const client = createClient(async () => ({ ok: true, json: async () => response }));
    client.run("currentRoom.status = 'finished'; gameState.is_game_over = true;");
    response = {
        room: { status: 'playing' }, players: [],
        game_state: client.run("({ ...gameState, round_id: 'new_round', started_at: 200, version: 1, is_game_over: false })"),
    };

    await client.run('fetchRoomState()');

    assert.equal(client.run('gameState.round_id'), 'new_round');
    assert.equal(client.run('gameState.is_game_over'), false);
    assert.equal(client.run('currentRoom.status'), 'playing');
});

for (const winner of ['180', 'other', null]) {
    test(`result distinguishes victory, defeat and draw for winner ${winner}`, async () => {
        const client = createClient(async () => ({ ok: false, json: async () => ({ success: false, error: 'Servidor ocupado, reintenta' }) }));
        client.run('let victories = 0; sfx = { playVictory() { victories++; } };');

        client.run(`handleIncomingEvent({ type: 'game_over', data: { round_id: 'old_round', winner_id: ${JSON.stringify(winner)}, finish_reason: 'TIME_EXPIRED' } });`);
        await client.run('requestRematch()');

        assert.equal(client.nodes.get('gameover-title').innerText, winner === '180' ? '¡VICTORIA!' : (winner ? 'DERROTA' : '¡TIEMPO AGOTADO!'));
        assert.equal(client.run('victories'), winner === '180' ? 1 : 0);
        assert.equal(client.nodes.get('rematch-button').classList.contains('hidden'), winner !== 'other');
        assert.equal(client.calls.length, winner === 'other' ? 1 : 0);
    });
}

test('the final explosion finishes before the result even if its event arrives after game over', () => {
    const client = createClient();
    client.run(`let renderedFrames = 0;
        gameRenderer = { activeExplosions: [],
            render() { renderedFrames++; },
            addExplosion() { const start = performance.now(); this.activeExplosions.push({ isFinished: () => performance.now() - start >= 450 }); }
        };`);

    client.run(`handleIncomingEvent({ type: 'game_over', data: { round_id: 'old_round', winner_id: '180', finish_reason: 'LAST_PLAYER_ALIVE' } });`);
    client.frame(700);
    client.run(`handleIncomingEvent({ type: 'bomb_exploded', data: { round_id: 'old_round', event_id: 'final_explosion', root_bomb_id: 'final_bomb', eliminated_players: [{ player_id: 'other' }], explosion_tiles: [{ x: 11, y: 9 }] } });`);
    client.frame(1100);

    assert.equal(client.nodes.has('gameover-title'), false);
    assert.equal(client.run('gameState.players.other.alive'), false);
    assert.ok(client.run('renderedFrames') >= 3);
    assert.equal(client.run('pendingActionQueue.length'), 0);

    client.frame(1200);

    assert.equal(client.nodes.get('gameover-title').innerText, '¡VICTORIA!');
    assert.equal(client.run('animFrameId'), null);
});

test('a new round cancels the pending result of the previous explosion', () => {
    const client = createClient();
    client.run('gameRenderer = { activeExplosions: [], render() {}, invalidateBoard() {} };');
    client.run(`handleIncomingEvent({ type: 'game_over', data: { round_id: 'old_round', winner_id: 'other', finish_reason: 'LAST_PLAYER_ALIVE' } });`);

    startRematch(client);
    client.frame(1500);

    assert.equal(client.nodes.get('gameover-modal').classList.contains('hidden'), true);
    assert.equal(client.run('currentRoom.status'), 'playing');
});

for (const [id, scenario] of [
    ['V01', 'landscape'], ['V02', 'portrait'], ['V03', 'directions'], ['V04', 'adjacent interpolation'],
    ['V05', 'diagonal correction'], ['V06', 'large correction'], ['V07', 'spatial conflict'], ['V08', 'dead marker'],
    ['V09', 'removed entity'], ['V10', 'immutable logic'], ['V11', 'board cache'],
    ['V12', 'old snapshot'], ['V13', 'old movement'], ['V14', 'confirmed local position'],
]) {
    test(`${id} ${scenario}`, async () => {
        if (id === 'V12' || id === 'V13' || id === 'V14') {
            const client = createClient();
            if (id === 'V12') {
                client.run(`applyStateSnapshot({ ...gameState, version: 89, players: {} });`);
                assert.equal(client.run('gameState.version'), 90);
                assert.ok(client.run('gameState.players.other'));
            } else if (id === 'V13') {
                client.run(`handleIncomingEvent({ type: 'player_moved', data: { round_id: 'old_round', version: 89, player_id: 'other', x: 1, y: 1 } });`);
                assert.equal(client.run('gameState.players.other.x'), 11);
            } else {
                client.run("predictAndMove('right');");
                assert.equal(client.run('gameState.players[CURRENT_PLAYER_ID].x'), 1);
                assert.equal(client.calls.length, 1);
                assert.equal(JSON.parse(client.calls[0][1].body).sequence, 1);
                await new Promise(resolve => setImmediate(resolve));
            }
            return;
        }
        const renderer = createRenderer();
        const state = { seed: 42, grid: [[0, 2, 0]], players: { a: { id: 'a', alive: true, x: 1, y: 1 } } };
        renderer.frame(0, state);
        if (id === 'V01') {
            assert.equal(renderer.draws[0].x, 75); assert.equal(renderer.draws[0].y, 75);
        } else if (id === 'V02') {
            renderer.run('renderer.setPortrait(true);'); renderer.frame(25, state);
            assert.equal(renderer.draws[0].x, 475); assert.equal(renderer.draws[0].y, 75);
        } else if (id === 'V03') {
            renderer.run('renderer.setPortrait(true);');
            for (const direction of ['up', 'right', 'down', 'left']) {
                assert.equal(renderer.run(`renderer.screenToLogicalDir(renderer.logicalToScreenDir('${direction}'))`), direction);
            }
        } else if (id === 'V04') {
            state.players.a.x = 2; renderer.frame(25, state);
            assert.equal(renderer.draws[0].x, 100); assert.equal(renderer.draws[0].y, 75);
        } else if (id === 'V05' || id === 'V06') {
            state.players.a.x = id === 'V05' ? 2 : 5;
            state.players.a.y = id === 'V05' ? 2 : 1;
            renderer.frame(25, state);
            assert.equal(renderer.draws[0].x, state.players.a.x * 50 + 25);
            assert.equal(renderer.draws[0].y, state.players.a.y * 50 + 25);
        } else if (id === 'V07') {
            state.players.b = { id: 'b', alive: true, x: 2, y: 1 }; renderer.frame(1, state);
            state.players.b.x = 3; state.players.a.x = 2; renderer.frame(25, state);
            const a = renderer.draws.find(p => p.id === 'a'), b = renderer.draws.find(p => p.id === 'b');
            assert.ok(Math.abs(a.x - b.x) >= 35);
        } else if (id === 'V08') {
            state.players.b = { id: 'b', alive: false, x: 2, y: 1 };
            state.players.a.x = 2; renderer.frame(25, state);
            assert.equal(renderer.draws.length, 2);
            assert.equal(renderer.draws.find(p => p.id === 'a').x, 100);
        } else if (id === 'V09') {
            delete state.players.a; renderer.frame(25, state);
            assert.equal(renderer.run('renderer.displayPos.size'), 0);
        } else if (id === 'V10') {
            state.players.a.x = 2;
            const before = JSON.stringify(state); renderer.frame(25, state);
            assert.equal(JSON.stringify(state), before);
        } else if (id === 'V11') {
            const before = renderer.boards();
            state.grid = [[0, 0, 2]]; renderer.frame(25, state);
            assert.ok(renderer.boards() > before);
        }
    });
}

for (const latency of [30, 150, 300]) {
    test(`asymmetric virtual network ${latency}ms with jitter, duplicates and reconnection converges`, () => {
        const clients = [createClient(), createClient(), createClient(), createClient()];
        const timeline = [];
        for (let clientIndex = 0; clientIndex < clients.length; clientIndex++) {
            for (let version = 91; version <= 100; version++) {
                const x = 1 + (version % 2);
                const state = clients[clientIndex].run(`({ ...gameState, version: ${version}, tick: ${version}, players: { '180': { id: '180', x: ${x}, y: 1, alive: true }, other: { id: 'other', x: 11, y: 9, alive: true } } })`);
                const time = (version - 91) * 50 + latency * (clientIndex + 1) / 4 + ((version * 17 + clientIndex * 31) % 90);
                if (clientIndex !== 3 || version % 3 === 0) timeline.push({ time, clientIndex, state });
                if (version % 4 === 0) timeline.push({ time: time + 120, clientIndex, state });
            }
        }
        timeline.sort((a, b) => a.time - b.time);
        for (const message of timeline) {
            const client = clients[message.clientIndex];
            client.context.incomingState = message.state;
            client.run('applyStateSnapshot(incomingState);');
        }
        for (const client of clients) {
            client.run(`applyStateSnapshot({ ...gameState, version: 101, tick: 101, players: { '180': { id: '180', x: 2, y: 1, alive: true }, other: { id: 'other', x: 11, y: 9, alive: true } } });`);
            assert.equal(client.run('gameState.version'), 101);
            assert.equal(client.run('gameState.players[CURRENT_PLAYER_ID].x'), 2);
            assert.ok(client.run('diagnostics.staleStates') > 0);
        }
    });
}
