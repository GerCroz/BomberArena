export const PLAYER_RADIUS = 0.28;

export function isFree(state, player, x, y) {
    const r = PLAYER_RADIUS;
    for (let row = Math.floor(y-r+0.5); row <= Math.floor(y+r+0.5); row++) {
        for (let col = Math.floor(x-r+0.5); col <= Math.floor(x+r+0.5); col++) {
            if ((state.grid[row]?.[col] ?? 1) !== 0) return false;
        }
    }
    for (const bomb of Object.values(state.bombs || {})) {
        if (Math.abs(x-bomb.x) < 0.5+r && Math.abs(y-bomb.y) < 0.5+r && !(bomb.pass_through_players || []).includes(player.id)) return false;
    }
    if (state.rules?.player_collisions) {
        for (const other of Object.values(state.players)) {
            if (other.id !== player.id && other.alive && Math.abs(x-(other.position_x ?? other.x)) < r*2 && Math.abs(y-(other.position_y ?? other.y)) < r*2) return false;
        }
    }
    return true;
}

export function isTileFree(state, player, tileX, tileY) {
    if (!state || !state.grid) return false;
    const width = state.grid[0]?.length ?? 13;
    const height = state.grid.length ?? 11;
    if (tileX < 0 || tileX >= width || tileY < 0 || tileY >= height) return false;

    // Solid tiles: 1 = indestructible wall, 2 = destructible block
    const tile = state.grid[tileY]?.[tileX] ?? 1;
    if (tile === 1 || tile === 2) return false;

    const playerId = player?.id;
    const curX = Math.round(player?.position_x ?? player?.x ?? 0);
    const curY = Math.round(player?.position_y ?? player?.y ?? 0);

    // Active bombs
    for (const bomb of Object.values(state.bombs || {})) {
        if (bomb.x === tileX && bomb.y === tileY) {
            const passThrough = bomb.pass_through_players || [];
            // If the player is currently on this bomb tile and has pass-through, allow
            if (curX === tileX && curY === tileY && passThrough.includes(playerId)) {
                continue;
            }
            return false;
        }
    }

    // Player collisions (if enabled by game rules)
    if (state.rules?.player_collisions) {
        for (const other of Object.values(state.players || {})) {
            if (other.id !== playerId && other.alive && Math.round(other.position_x ?? other.x) === tileX && Math.round(other.position_y ?? other.y) === tileY) {
                return false;
            }
        }
    }

    return true;
}

export function movementStep(state, player, direction, delta) {
    const vector = {up:[0,-1], down:[0,1], left:[-1,0], right:[1,0]}[direction];
    if (!player.alive || !vector) return {...player};
    let x = player.position_x ?? player.x, y = player.position_y ?? player.y;
    let [dx,dy] = vector;
    let distance = Math.min(0.05, Math.max(0,delta)) * (state.rules?.movement_speed ?? 4) * (1+0.15*(Math.max(1,player.speed || 1)-1));
    const offset = dx !== 0 ? Math.round(y)-y : Math.round(x)-x;
    if (Math.abs(offset) > 0.000001) {
        if (Math.abs(offset) > (state.rules?.corner_assist ?? 0.25)+0.000001) return {...player};
        const correction = Math.max(-distance,Math.min(distance,offset));
        [dx,dy] = vector[0] === 0 ? [Math.sign(correction),0] : [0,Math.sign(correction)];
        distance = Math.abs(correction);
    }
    const steps = Math.max(1,Math.ceil(distance/0.025));
    for (let i=0;i<steps;i++) {
        const nx=x+dx*distance/steps, ny=y+dy*distance/steps;
        if (!isFree(state,player,nx,ny)) {
            let lo=0,hi=1;
            for(let search=0;search<16;search++) {
                const mid=(lo+hi)/2;
                if(isFree(state,player,x+(nx-x)*mid,y+(ny-y)*mid))lo=mid;else hi=mid;
            }
            x=dx>0?Math.floor((x+(nx-x)*lo)*1e6)/1e6:dx<0?Math.ceil((x+(nx-x)*lo)*1e6)/1e6:x;
            y=dy>0?Math.floor((y+(ny-y)*lo)*1e6)/1e6:dy<0?Math.ceil((y+(ny-y)*lo)*1e6)/1e6:y;
            break;
        }
        x=nx;y=ny;
    }
    return {...player,position_x:Number(x.toFixed(6)),position_y:Number(y.toFixed(6)),x:Math.round(x),y:Math.round(y),direction};
}

export class ClassicController {
    constructor(playerOrOptions = {}, options = {}) {
        let opts = options;
        let player = null;
        if (playerOrOptions && (playerOrOptions.id !== undefined || playerOrOptions.x !== undefined)) {
            player = playerOrOptions;
        } else {
            opts = playerOrOptions || {};
        }

        this.stepDuration = opts.stepDuration ?? 120; // 120ms animation per cell
        this.keys = new Set();
        this.bufferedInput = null; // Buffer at most 1 pending input during animation
        this.isStepping = false;
        this.player = player;
        const initialX = Math.round(player?.position_x ?? player?.x ?? 1);
        const initialY = Math.round(player?.position_y ?? player?.y ?? 1);
        this.stepFrom = { x: initialX, y: initialY };
        this.stepTo = { x: initialX, y: initialY };
        this.stepStart = 0;
        this.sequence = 0;
        this.lastAcknowledged = 0;
        this.lastFrame = null;
        this.corrections = 0;
        this.connected = true;
        this.exitedBombs = new Set();
        this.lastPressedAt = null;
        this.visualResponseMs = null;
        this.pending = [];
        this.ordered = false;
        this.serverTime = Date.now() / 1000;
        this.provisionalBombs = {};
        this.positionError = 0;
        this.bombConfirmationMs = null;
        this.needsResync = false;
        this.roundId = null;
        this.stateVersion = 0;
        this.stateTick = 0;
        this.predictionState = opts.state || opts.predictionState || null;
        this.direction = null;
        this.onSendFrame = opts.onSendFrame || null;
        this.onSendBomb = opts.onSendBomb || null;
    }

    getNowServerTime() {
        if (typeof window !== 'undefined' && typeof window.getCurrentServerTime === 'function') {
            return window.getCurrentServerTime();
        }
        return this.serverTime || (Date.now() / 1000);
    }

    get visualX() {
        if (!this.player) return 0;
        if (!this.isStepping) return this.player.x;
        const elapsed = performance.now() - this.stepStart;
        const progress = Math.min(1, Math.max(0, elapsed / this.stepDuration));
        return this.stepFrom.x + (this.stepTo.x - this.stepFrom.x) * progress;
    }

    get visualY() {
        if (!this.player) return 0;
        if (!this.isStepping) return this.player.y;
        const elapsed = performance.now() - this.stepStart;
        const progress = Math.min(1, Math.max(0, elapsed / this.stepDuration));
        return this.stepFrom.y + (this.stepTo.y - this.stepFrom.y) * progress;
    }

    get stepProgress() {
        if (!this.isStepping) return 0;
        const elapsed = performance.now() - this.stepStart;
        return Math.min(1, Math.max(0, elapsed / this.stepDuration));
    }

    get pendingInputs() {
        return this.pending;
    }

    set pendingInputs(val) {
        this.pending = val;
    }

    onKeyDown(key, repeat = false, now = performance.now()) {
        if (repeat) return false;
        const keyMap = {
            ArrowUp: 'up', KeyW: 'up', w: 'up', W: 'up',
            ArrowDown: 'down', KeyS: 'down', s: 'down', S: 'down',
            ArrowLeft: 'left', KeyA: 'left', a: 'left', A: 'left',
            ArrowRight: 'right', KeyD: 'right', d: 'right', D: 'right',
        };
        const dir = keyMap[key];
        if (!dir) return false;
        return this.press(key, dir, now);
    }

    onKeyUp(key) {
        return this.release(key);
    }

    press(key, direction, now = performance.now()) {
        if (this.keys.has(key)) {
            // Rule 2 & 3: Mantener presionado NO genera movimientos adicionales
            return false;
        }
        this.keys.add(key);
        this.direction = direction;
        this.lastPressedAt = now;

        if (this.isStepping) {
            // Fast tap handling: allow at most ONE pending input in the buffer
            this.bufferedInput = direction;
            return true;
        }

        return this.initiateStep(direction, now);
    }

    release(key) {
        if (!this.keys.has(key)) return false;
        this.keys.delete(key);
        if (this.keys.size === 0) {
            this.direction = null;
        }
        // Soltar no produce movimiento adicional
        return true;
    }

    clear() {
        this.keys.clear();
        this.bufferedInput = null;
        this.isStepping = false;
        this.direction = null;
        this.lastFrame = null;
        if (this.player) {
            const px = Math.round(this.player.x);
            const py = Math.round(this.player.y);
            this.stepFrom = { x: px, y: py };
            this.stepTo = { x: px, y: py };
        }
    }

    reset() {
        this.clear();
        this.pending = [];
        this.provisionalBombs = {};
        this.exitedBombs.clear();
        this.lastAcknowledged = 0;
    }

    attemptStep(direction, now = performance.now()) {
        return this.initiateStep(direction, now);
    }

    initiateStep(direction, now = performance.now()) {
        if (!this.player || !this.player.alive) return false;
        const vectors = { up: [0, -1], down: [0, 1], left: [-1, 0], right: [1, 0] };
        const vec = vectors[direction];
        if (!vec) return false;

        this.player.direction = direction;
        const curX = Math.round(this.player.position_x ?? this.player.x);
        const curY = Math.round(this.player.position_y ?? this.player.y);
        const targetX = curX + vec[0];
        const targetY = curY + vec[1];

        const state = this.predictionState || {};
        if (!isTileFree(state, this.player, targetX, targetY)) {
            // Blocked: position remains unchanged, face direction
            this.isStepping = false;
            this.bufferedInput = null;
            return false;
        }

        // Valid discrete step
        this.isStepping = true;
        this.stepFrom = { x: curX, y: curY };
        this.stepTo = { x: targetX, y: targetY };
        this.stepStart = now;

        // Advance logical coordinates immediately
        this.player.x = targetX;
        this.player.y = targetY;
        this.player.position_x = targetX;
        this.player.position_y = targetY;

        if (this.visualResponseMs === null && this.lastPressedAt !== null) {
            this.visualResponseMs = Math.max(0, now - this.lastPressedAt);
        }

        // Sequence increment and queue frame for server
        this.sequence++;
        const sampleTime = this.getNowServerTime();
        const frame = {
            sequence: this.sequence,
            duration: 0.05,
            direction,
            sampled_at: sampleTime,
            from_x: curX,
            from_y: curY,
            to_x: targetX,
            to_y: targetY,
        };
        this.pending.push(frame);
        if (typeof this.onSendFrame === 'function') {
            this.onSendFrame(frame);
        }

        // Remove pass-through if stepping off a bomb
        for (const [key, bomb] of Object.entries(state.bombs || {})) {
            if (bomb.x === curX && bomb.y === curY) {
                this.exitedBombs.add(key);
            }
        }

        // Power-up instant local collection
        const puKey = `${targetX}_${targetY}`;
        if (state.powerups?.[puKey]) {
            this.lastCollectedPowerup = state.powerups[puKey];
            delete state.powerups[puKey];
            this.player.powerups_collected = (this.player.powerups_collected || 0) + 1;
        }

        return true;
    }

    frame(duration, direction, extra = {}) {
        if (this.pending.length >= 64) {
            this.discardPending();
            this.needsResync = true;
            return null;
        }
        const sampleTime = this.getNowServerTime();
        const entry = { sequence: ++this.sequence, duration, direction, sampled_at: sampleTime, ...extra };
        this.pending.push(entry);
        return entry;
    }

    discardPending() {
        this.pending = [];
        this.keys.clear();
        this.bufferedInput = null;
        this.isStepping = false;
        this.provisionalBombs = {};
        this.exitedBombs.clear();
        this.direction = null;
        this.frame(0, null, { reset: true });
    }

    reconcile(state, id) {
        id = id || this.player?.id;
        const confirmed = state.players?.[id];
        if (!confirmed) return;

        if (this.roundId === state.round_id && ((state.version ?? 0) < (this.stateVersion ?? 0) || (state.tick ?? 0) < (this.stateTick ?? 0))) {
            return;
        }

        if (this.roundId && this.roundId !== state.round_id) {
            this.pending = [];
            this.keys.clear();
            this.bufferedInput = null;
            this.isStepping = false;
            this.exitedBombs.clear();
            this.provisionalBombs = {};
            this.lastAcknowledged = 0;
        }

        this.roundId = state.round_id;
        this.stateVersion = state.version ?? 0;
        this.stateTick = state.tick ?? 0;
        this.predictionState = state;
        this.ordered = true;

        const ack = state.input_ack?.[id] ?? 0;
        this.lastAcknowledged = Math.max(this.lastAcknowledged, ack);
        this.sequence = Math.max(this.sequence, ack);

        // Filter out confirmed inputs
        this.pending = this.pending.filter(input => input.sequence > this.lastAcknowledged);

        if (!confirmed.alive || state.is_game_over) {
            this.pending = [];
            this.keys.clear();
            this.bufferedInput = null;
            this.isStepping = false;
            this.direction = null;
        }

        // Replay unacknowledged moves from confirmed position
        let replayX = Math.round(confirmed.x);
        let replayY = Math.round(confirmed.y);
        for (const input of this.pending) {
            if (input.direction) {
                const vec = { up: [0, -1], down: [0, 1], left: [-1, 0], right: [1, 0] }[input.direction];
                if (vec && isTileFree(state, confirmed, replayX + vec[0], replayY + vec[1])) {
                    replayX += vec[0];
                    replayY += vec[1];
                }
            }
        }

        if (this.player) {
            const targetX = this.isStepping ? this.stepTo.x : this.player.x;
            const targetY = this.isStepping ? this.stepTo.y : this.player.y;
            this.positionError = Math.hypot(replayX - targetX, replayY - targetY);
            if (this.positionError > 0.001) {
                this.corrections++;
                this.player.x = replayX;
                this.player.y = replayY;
                this.player.position_x = replayX;
                this.player.position_y = replayY;
                this.isStepping = false;
                this.bufferedInput = null;
                this.stepFrom = { x: replayX, y: replayY };
                this.stepTo = { x: replayX, y: replayY };
            }
        } else {
            this.player = {
                ...confirmed,
                x: confirmed.x,
                y: confirmed.y,
                position_x: confirmed.x,
                position_y: confirmed.y,
            };
            this.stepFrom = { x: confirmed.x, y: confirmed.y };
            this.stepTo = { x: confirmed.x, y: confirmed.y };
        }

        // Confirm provisional bombs
        const curServerNow = (this.serverTime || Date.now() / 1000);
        for (const [action, bomb] of Object.entries(this.provisionalBombs)) {
            const isConfirmed = Object.values(state.bombs || {}).some(b => b.action_id === action || (b.x === bomb.x && b.y === bomb.y && b.owner_id === id));
            const isExpired = curServerNow > (bomb.explodes_at + 0.5);
            if (state.action_results?.[id]?.[action] || ack >= bomb.sequence || isConfirmed || isExpired) {
                this.bombConfirmationMs = Math.max(0, performance.now() - bomb.started);
                delete this.provisionalBombs[action];
            }
        }

        if (this.player && confirmed) {
            this.player.max_bombs = confirmed.max_bombs || this.player.max_bombs || 1;
            this.player.bomb_range = confirmed.bomb_range || this.player.bomb_range || 2;
            this.player.speed = confirmed.speed || this.player.speed || 1;
        }
    }

    update(stateOrElapsed, id, now = performance.now(), serverTime = Date.now() / 1000) {
        if (typeof stateOrElapsed === 'number') {
            // Delta time simulation: advances the stepping animation
            this.stepStart -= stateOrElapsed * 1000;
            now = performance.now();
        } else if (stateOrElapsed) {
            this.predictionState = stateOrElapsed;
            this.serverTime = serverTime;
            this.ordered = true;
        }

        if (!this.player && stateOrElapsed && typeof stateOrElapsed === 'object') {
            this.reconcile(stateOrElapsed, id);
        }
        if (!this.player) return null;

        let visualX = this.player.x;
        let visualY = this.player.y;

        if (this.isStepping) {
            const elapsed = now - this.stepStart;
            const progress = Math.min(1, Math.max(0, elapsed / this.stepDuration));
            visualX = this.stepFrom.x + (this.stepTo.x - this.stepFrom.x) * progress;
            visualY = this.stepFrom.y + (this.stepTo.y - this.stepFrom.y) * progress;

            if (progress >= 1) {
                this.isStepping = false;
                visualX = this.stepTo.x;
                visualY = this.stepTo.y;

                // If an input was buffered during animation, consume it now
                if (this.bufferedInput) {
                    const nextDir = typeof this.bufferedInput === 'string' ? this.bufferedInput : this.bufferedInput.direction;
                    this.bufferedInput = null;
                    if (nextDir) {
                        this.initiateStep(nextDir, now);
                    }
                }
            }
        }

        return {
            id: id || this.player?.id,
            x: visualX,
            y: visualY,
            logicalX: this.player.x,
            logicalY: this.player.y,
            direction: this.player.direction,
            continuous: false,
            isMoving: this.isStepping,
        };
    }

    queueBomb(actionId, serverTime = null) {
        if (!this.player?.alive) return false;
        const nowServer = serverTime || this.getNowServerTime();
        this.serverTime = nowServer;
        actionId = actionId || ('bomb_' + Math.random().toString(36).substring(2, 10));

        const curX = Math.round(this.player.x);
        const curY = Math.round(this.player.y);

        // Check active bomb count
        let activeCount = 0;
        const bombs = this.predictionState?.bombs || {};
        for (const b of Object.values(bombs)) {
            if (b.owner_id === this.player.id && b.state !== 'detonated') activeCount++;
        }
        for (const b of Object.values(this.provisionalBombs)) {
            if (b.owner_id === this.player.id) activeCount++;
        }
        if (activeCount >= (this.player.max_bombs || 1)) return false;

        // Check if current tile already contains an active bomb
        for (const b of Object.values(bombs)) {
            if (b.x === curX && b.y === curY && b.state !== 'detonated') return false;
        }
        for (const b of Object.values(this.provisionalBombs)) {
            if (b.x === curX && b.y === curY) return false;
        }

        const fuseMs = this.predictionState?.rules?.bomb_fuse_ms ?? 2000;
        this.sequence++;
        const frame = {
            sequence: this.sequence,
            duration: 0,
            direction: null,
            action_id: actionId,
            sampled_at: nowServer,
            to_x: curX,
            to_y: curY,
            from_x: curX,
            from_y: curY,
        };
        this.pending.push(frame);

        this.provisionalBombs[actionId] = {
            id: actionId,
            x: curX,
            y: curY,
            owner_id: this.player.id,
            sequence: this.sequence,
            started: performance.now(),
            planted_at: nowServer,
            explodes_at: nowServer + fuseMs / 1000,
            range: this.player.bomb_range || 2,
            state: 'active',
            pass_through_players: [this.player.id],
        };

        if (typeof this.onSendBomb === 'function') {
            this.onSendBomb({
                action_id: actionId,
                x: curX,
                y: curY,
                sequence: this.sequence,
            });
        }

        return true;
    }

    batch(serverTime) {
        if (serverTime) this.serverTime = serverTime;
        const now = serverTime || this.getNowServerTime();
        return this.pending
            .slice(-24)
            .map(input => ({
                ...input,
                sampled_at: input.sampled_at || now,
                ...(input.reset ? { sampled_at: now } : {})
            }));
    }
}
