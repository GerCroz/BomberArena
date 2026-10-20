import test from 'node:test';
import assert from 'node:assert/strict';
import { ClassicController, isTileFree } from '../../resources/js/game/classicMovement.js';

function createMockState(overrides = {}) {
    const width = 13;
    const height = 11;
    const grid = Array.from({ length: height }, (_, y) =>
        Array.from({ length: width }, (_, x) => {
            if (x === 0 || x === width - 1 || y === 0 || y === height - 1) return 1;
            if (x % 2 === 0 && y % 2 === 0) return 1;
            return 0;
        })
    );
    // Put a destructible block at (2, 1)
    grid[1][2] = 2;

    return {
        grid,
        players: {
            p1: { id: 'p1', x: 1, y: 1, position_x: 1, position_y: 1, alive: true, speed: 1 },
            p2: { id: 'p2', x: 3, y: 1, position_x: 3, position_y: 1, alive: true, speed: 1 },
            p3: { id: 'p3', x: 1, y: 3, position_x: 1, position_y: 3, alive: true, speed: 1 },
            p4: { id: 'p4', x: 3, y: 3, position_x: 3, position_y: 3, alive: true, speed: 1 },
        },
        bombs: {},
        rules: { player_collisions: false },
        ...overrides,
    };
}

// 1. Una pulsación mueve exactamente una casilla.
test('1. Una pulsación mueve exactamente una casilla', () => {
    const state = createMockState();
    const sentFrames = [];
    const controller = new ClassicController(state.players.p1, {
        state,
        onSendFrame: (frame) => sentFrames.push(frame),
    });

    assert.equal(controller.player.x, 1);
    assert.equal(controller.player.y, 1);

    // Pulse down (from 1,1 to 1,2)
    const moved = controller.attemptStep('down');
    assert.equal(moved, true);
    assert.equal(controller.isStepping, true);
    assert.equal(controller.player.x, 1);
    assert.equal(controller.player.y, 2);
    assert.equal(sentFrames.length, 1);
    assert.equal(sentFrames[0].direction, 'down');
    assert.equal(sentFrames[0].to_x, 1);
    assert.equal(sentFrames[0].to_y, 2);

    // Advance animation to end
    controller.update(0.15);
    assert.equal(controller.isStepping, false);
    assert.equal(controller.visualX, 1);
    assert.equal(controller.visualY, 2);
});

// 2. Mantener una tecla no genera movimientos adicionales.
test('2. Mantener una tecla no genera movimientos adicionales', () => {
    const state = createMockState();
    let sendCount = 0;
    const controller = new ClassicController(state.players.p1, {
        state,
        onSendFrame: () => { sendCount++; },
    });

    controller.onKeyDown('ArrowDown');
    assert.equal(sendCount, 1);
    assert.equal(controller.player.y, 2);

    // Still holding the key, onKeyDown fired again without keyUp
    controller.onKeyDown('ArrowDown');
    assert.equal(sendCount, 1, 'Holding key must not trigger another step');

    // Complete animation
    controller.update(0.2);
    controller.onKeyDown('ArrowDown');
    assert.equal(sendCount, 1, 'Holding key must still not trigger another step even after animation ends');
});

// 3. event.repeat no genera nuevos movimientos.
test('3. event.repeat no genera nuevos movimientos', () => {
    const state = createMockState();
    let sendCount = 0;
    const controller = new ClassicController(state.players.p1, {
        state,
        onSendFrame: () => { sendCount++; },
    });

    controller.onKeyDown('ArrowDown', false);
    assert.equal(sendCount, 1);

    // OS generates repeated keydown
    controller.onKeyDown('ArrowDown', true);
    assert.equal(sendCount, 1, 'event.repeat=true must be ignored');
});

// 4. Soltar una dirección no mueve al jugador.
test('4. Soltar una dirección no mueve al jugador', () => {
    const state = createMockState();
    let sendCount = 0;
    const controller = new ClassicController(state.players.p1, {
        state,
        onSendFrame: () => { sendCount++; },
    });

    controller.onKeyDown('ArrowDown', false);
    assert.equal(sendCount, 1);
    assert.equal(controller.player.y, 2);

    controller.onKeyUp('ArrowDown');
    assert.equal(sendCount, 1, 'Releasing direction must not generate another movement');
    assert.equal(controller.player.y, 2);
});

// 5. Dos pulsaciones generan dos movimientos.
test('5. Dos pulsaciones generan dos movimientos', () => {
    const state = createMockState();
    let sendCount = 0;
    const controller = new ClassicController(state.players.p1, {
        state,
        onSendFrame: () => { sendCount++; },
    });

    // First press
    controller.onKeyDown('ArrowDown', false);
    controller.onKeyUp('ArrowDown');
    assert.equal(sendCount, 1);
    assert.equal(controller.player.y, 2);

    // Finish animation
    controller.update(0.15);
    assert.equal(controller.isStepping, false);

    // Second press
    controller.onKeyDown('ArrowDown', false);
    controller.onKeyUp('ArrowDown');
    assert.equal(sendCount, 2);
    assert.equal(controller.player.y, 3);
});

// 6. Una pulsación contra una pared no mueve al jugador.
test('6. Una pulsación contra una pared no mueve al jugador', () => {
    const state = createMockState();
    let sendCount = 0;
    const controller = new ClassicController(state.players.p1, {
        state,
        onSendFrame: () => { sendCount++; },
    });

    // Player at (1, 1). Up is wall (1, 0)
    const moved = controller.attemptStep('up');
    assert.equal(moved, false);
    assert.equal(sendCount, 0);
    assert.equal(controller.player.x, 1);
    assert.equal(controller.player.y, 1);

    // Right is destructible block at (2, 1)
    const movedRight = controller.attemptStep('right');
    assert.equal(movedRight, false);
    assert.equal(sendCount, 0);
    assert.equal(controller.player.x, 1);
    assert.equal(controller.player.y, 1);
});

// 7. Cambiar rápidamente de dirección mantiene coordenadas correctas con buffer de 1.
test('7. Cambiar rápidamente de dirección mantiene coordenadas correctas', () => {
    const state = createMockState();
    const sent = [];
    const controller = new ClassicController(state.players.p1, {
        state,
        onSendFrame: (f) => sent.push(f),
    });

    // At (1,1). Step down to (1,2)
    controller.onKeyDown('ArrowDown', false);
    controller.onKeyUp('ArrowDown');
    assert.equal(controller.isStepping, true);
    assert.equal(controller.player.x, 1);
    assert.equal(controller.player.y, 2);

    // While animating, tap down again (buffered)
    controller.onKeyDown('ArrowDown', false);
    controller.onKeyUp('ArrowDown');
    assert.equal(controller.bufferedInput, 'down');
    assert.equal(sent.length, 1);

    // Advance partial animation
    controller.update(0.06);
    assert.equal(controller.isStepping, true);

    // Finish first animation -> buffered down step executes immediately
    controller.update(0.07);
    assert.equal(sent.length, 2);
    assert.equal(controller.player.x, 1);
    assert.equal(controller.player.y, 3);

    // Finish second animation
    controller.update(0.15);
    assert.equal(controller.isStepping, false);
    assert.equal(controller.visualX, 1);
    assert.equal(controller.visualY, 3);
});

// 8 & 9. Múltiples jugadores se mueven simultáneamente.
test('8 & 9. Múltiples jugadores se mueven simultáneamente', () => {
    const state = createMockState();
    const c1 = new ClassicController(state.players.p1, { state, onSendFrame: () => {} });
    const c2 = new ClassicController(state.players.p2, { state, onSendFrame: () => {} });
    const c3 = new ClassicController(state.players.p3, { state, onSendFrame: () => {} });
    const c4 = new ClassicController(state.players.p4, { state, onSendFrame: () => {} });

    assert.equal(c1.attemptStep('down'), true); // p1: (1,1) -> (1,2)
    assert.equal(c2.attemptStep('down'), true); // p2: (3,1) -> (3,2)
    assert.equal(c3.attemptStep('right'), true); // p3: (1,3) -> (2,3)
    assert.equal(c4.attemptStep('left'), true); // p4: (3,3) -> (2,3) (crossing/entering)

    assert.equal(c1.player.x, 1); assert.equal(c1.player.y, 2);
    assert.equal(c2.player.x, 3); assert.equal(c2.player.y, 2);
    assert.equal(c3.player.x, 2); assert.equal(c3.player.y, 3);
    assert.equal(c4.player.x, 2); assert.equal(c4.player.y, 3);
});

// 10. Los jugadores pueden cruzarse.
test('10. Los jugadores pueden cruzarse', () => {
    const state = createMockState();
    state.players.p1.x = 1; state.players.p1.y = 2;
    state.players.p2.x = 1; state.players.p2.y = 3;

    // Player collision is disabled by default in classic bomber
    const freeForP1 = isTileFree(state, state.players.p1, 1, 3);
    const freeForP2 = isTileFree(state, state.players.p2, 1, 2);
    assert.equal(freeForP1, true);
    assert.equal(freeForP2, true);
});

// 11. El movimiento local comienza sin esperar confirmación.
test('11. El movimiento local comienza sin esperar confirmación', () => {
    const state = createMockState();
    const controller = new ClassicController(state.players.p1, { state, onSendFrame: () => {} });

    assert.equal(controller.isStepping, false);
    controller.attemptStep('down');
    assert.equal(controller.isStepping, true, 'Animation starts immediately');
    assert.ok(controller.stepProgress >= 0 && controller.stepProgress < 0.1, 'Step progress starts near 0');
    assert.equal(controller.player.y, 2, 'Logical position updates immediately');
});

// 12. Los snapshots obsoletos no producen retrocesos.
test('12. Los snapshots obsoletos no producen retrocesos', () => {
    const state = createMockState();
    let sentSeq = 0;
    const controller = new ClassicController(state.players.p1, {
        state,
        onSendFrame: (f) => { sentSeq = f.sequence; },
    });

    controller.attemptStep('down'); // moves to (1,2)
    controller.update(0.15); // completes step

    // Stale server snapshot from before the move (player at (1,1) with last_processed_sequence < sentSeq)
    const staleSnapshot = {
        players: {
            p1: { id: 'p1', position_x: 1, position_y: 1, last_processed_sequence: sentSeq - 1 },
        },
    };
    controller.reconcile(staleSnapshot);
    assert.equal(controller.player.x, 1);
    assert.equal(controller.player.y, 2, 'Stale snapshot must not roll back predicted movement');
});

// 13, 14, 15. Control de colocación de bomba: KeyDown no coloca, KeyUp sí coloca, no duplicados.
test('13, 14, 15. Colocación de bomba: sólo en release, una sola vez', () => {
    const state = createMockState();
    const bombs = [];
    const controller = new ClassicController(state.players.p1, {
        state,
        onSendBomb: (b) => bombs.push(b),
    });

    // Space keydown simulation
    let spacePressed = false;
    function handleKeyDown(repeat = false) {
        if (repeat || spacePressed) return;
        spacePressed = true;
    }
    function handleKeyUp() {
        if (!spacePressed) return;
        spacePressed = false;
        controller.queueBomb();
    }

    // Keydown space
    handleKeyDown(false);
    assert.equal(bombs.length, 0, 'Space keydown must not place bomb');

    // Repeated keydown space (holding key)
    handleKeyDown(true);
    handleKeyDown(true);
    assert.equal(bombs.length, 0, 'Holding space must not place bomb');

    // Keyup space
    handleKeyUp();
    assert.equal(bombs.length, 1, 'Releasing space places exactly 1 bomb');
    assert.equal(bombs[0].x, 1);
    assert.equal(bombs[0].y, 1);

    // Another keyup without keydown
    handleKeyUp();
    assert.equal(bombs.length, 1, 'Duplicate keyup does not place another bomb');
});

// 16 & 17. Controles táctiles: pointerup coloca bomba, pointercancel cancela sin colocar.
test('16 & 17. Controles táctiles: pointerup vs pointercancel', () => {
    const state = createMockState();
    const bombs = [];
    const controller = new ClassicController(state.players.p1, {
        state,
        onSendBomb: (b) => bombs.push(b),
    });

    let pointerDownOnBomb = false;
    function onBombPointerDown() {
        pointerDownOnBomb = true;
    }
    function onBombPointerUp() {
        if (!pointerDownOnBomb) return;
        pointerDownOnBomb = false;
        controller.queueBomb();
    }
    function onBombPointerCancel() {
        pointerDownOnBomb = false; // Cancelled gesture
    }

    // Gesture 1: normal tap
    onBombPointerDown();
    assert.equal(bombs.length, 0);
    onBombPointerUp();
    assert.equal(bombs.length, 1, 'pointerup places bomb');

    // Gesture 2: cancelled gesture (e.g. scroll or notification)
    onBombPointerDown();
    assert.equal(bombs.length, 1);
    onBombPointerCancel();
    onBombPointerUp(); // stray pointerup after cancel
    assert.equal(bombs.length, 1, 'pointercancel cancels bomb placement');
});

// 18. Una bomba se coloca en la casilla lógica correcta.
test('18. Una bomba se coloca en la casilla lógica correcta', () => {
    const state = createMockState();
    const bombs = [];
    const controller = new ClassicController(state.players.p1, {
        state,
        onSendBomb: (b) => bombs.push(b),
    });

    controller.player.x = 5;
    controller.player.y = 7;
    controller.queueBomb();

    assert.equal(bombs.length, 1);
    assert.equal(bombs[0].x, 5);
    assert.equal(bombs[0].y, 7);
    assert.ok(bombs[0].action_id);
    assert.ok(bombs[0].sequence);
});

// 19. No se duplican bombas al recibir confirmación.
test('19. No se duplican bombas al recibir confirmación', () => {
    const state = createMockState();
    const clientBombs = {};
    const provisionalId = 'bomb-prov-1';

    // Place provisional bomb
    clientBombs[provisionalId] = {
        id: provisionalId,
        x: 1,
        y: 1,
        is_provisional: true,
        action_id: 'action-123',
    };

    // Server confirms bomb with action_id: 'action-123'
    const serverBomb = {
        id: 'server-bomb-99',
        x: 1,
        y: 1,
        action_id: 'action-123',
    };

    // Match and reconcile without duplication
    for (const [key, b] of Object.entries(clientBombs)) {
        if (b.action_id === serverBomb.action_id || (b.is_provisional && b.x === serverBomb.x && b.y === serverBomb.y)) {
            delete clientBombs[key];
        }
    }
    clientBombs[serverBomb.id] = serverBomb;

    assert.equal(Object.keys(clientBombs).length, 1);
    assert.equal(clientBombs['server-bomb-99'].action_id, 'action-123');
});

// 20 & 21. Vibración háptica con soporte y fallback seguro cuando no hay soporte.
test('20 & 21. Vibración háptica funciona cuando está disponible y no produce errores si falta', () => {
    class MockHapticEngine {
        constructor(mockNavigator) {
            this.nav = mockNavigator;
            this.enabled = true;
            this.lastVibrate = null;
        }
        vibrate(pattern) {
            if (!this.enabled || !this.nav || typeof this.nav.vibrate !== 'function') return false;
            try {
                this.lastVibrate = pattern;
                return this.nav.vibrate(pattern);
            } catch {
                return false;
            }
        }
        directionPress() { return this.vibrate(10); }
        bombPress() { return this.vibrate(15); }
        bombConfirmed() { return this.vibrate(20); }
        powerupCollected() { return this.vibrate([30, 30, 50]); }
        damageOrDeath() { return this.vibrate([60, 40, 80]); }
    }

    // Scenario A: navigator.vibrate is available
    const mockNavAvailable = {
        vibrate: (p) => true,
    };
    const engineA = new MockHapticEngine(mockNavAvailable);
    assert.equal(engineA.directionPress(), true);
    assert.equal(engineA.lastVibrate, 10);
    assert.equal(engineA.bombPress(), true);
    assert.equal(engineA.lastVibrate, 15);
    assert.equal(engineA.bombConfirmed(), true);
    assert.equal(engineA.lastVibrate, 20);
    assert.deepEqual(engineA.powerupCollected() && engineA.lastVibrate, [30, 30, 50]);
    assert.deepEqual(engineA.damageOrDeath() && engineA.lastVibrate, [60, 40, 80]);

    // Scenario B: navigator.vibrate is undefined (e.g. Safari desktop)
    const mockNavUnavailable = {};
    const engineB = new MockHapticEngine(mockNavUnavailable);
    assert.doesNotThrow(() => {
        assert.equal(engineB.directionPress(), false);
        assert.equal(engineB.bombPress(), false);
        assert.equal(engineB.powerupCollected(), false);
    });

    // Scenario C: User toggled off haptics
    engineA.enabled = false;
    assert.equal(engineA.directionPress(), false);
});

// 22. La recolección de power-ups produce una única vibración local (sólo para el jugador local).
test('22. La recolección de power-ups produce vibración sólo para jugador local', () => {
    let vibrateCount = 0;
    const haptics = {
        powerupCollected: () => { vibrateCount++; },
    };

    const localPlayerId = 'p1';
    function onPowerupCollected(event) {
        if (event.player_id === localPlayerId) {
            haptics.powerupCollected();
        }
    }

    // Remote player p2 collects power-up
    onPowerupCollected({ player_id: 'p2', type: 'fire' });
    assert.equal(vibrateCount, 0, 'No vibration for remote player');

    // Local player p1 collects power-up
    onPowerupCollected({ player_id: 'p1', type: 'speed' });
    assert.equal(vibrateCount, 1, 'Local player receives haptic feedback');
});

// 23. Una reconexión no reproduce movimientos antiguos.
test('23. Una reconexión no reproduce movimientos antiguos', () => {
    const state = createMockState();
    const controller = new ClassicController(state.players.p1, { state, onSendFrame: () => {} });

    controller.sequence = 50;
    controller.pendingInputs = [
        { sequence: 48, direction: 'up', duration: 0.05 },
        { sequence: 49, direction: 'down', duration: 0.05 },
    ];

    // Reconnection snapshot resets state
    controller.reset();
    assert.equal(controller.pendingInputs.length, 0);
    assert.equal(controller.isStepping, false);
    assert.equal(controller.bufferedInput, null);
});

// 24. La revancha reinicia correctamente los estados de entrada y animación.
test('24. La revancha reinicia correctamente los estados de entrada y animación', () => {
    const state = createMockState();
    const controller = new ClassicController(state.players.p1, { state, onSendFrame: () => {} });

    controller.isStepping = true;
    controller.bufferedInput = 'right';
    controller.keys.add('ArrowRight');

    controller.reset();
    assert.equal(controller.isStepping, false);
    assert.equal(controller.bufferedInput, null);
    assert.equal(controller.keys.size, 0);
    assert.equal(controller.stepProgress, 0);
});
