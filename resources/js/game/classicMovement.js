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
        if (!isFree(state,player,nx,ny)) break;
        x=nx;y=ny;
    }
    return {...player,position_x:Number(x.toFixed(6)),position_y:Number(y.toFixed(6)),x:Math.round(x),y:Math.round(y),direction};
}

export class ClassicController {
    constructor() {
        this.direction = null;
        this.keys = new Map();
        this.player = null;
        this.sequence = 0;
        this.lastAcknowledged = 0;
        this.lastFrame = null;
        this.corrections = 0;
        this.connected = true;
        this.exitedBombs = new Set();
        this.lastPressedAt = null;
        this.visualResponseMs = null;
        this.correction = {x:0,y:0};
    }
    press(key,direction) {
        if (this.keys.has(key)) return false;
        this.keys.set(key,direction);
        this.direction = direction;
        this.lastPressedAt = performance.now();this.visualResponseMs = null;
        this.sequence++;
        return true;
    }
    release(key) {
        if (!this.keys.delete(key)) return false;
        this.direction = [...this.keys.values()].at(-1) ?? null;
        this.sequence++;
        return true;
    }
    clear() {
        this.keys.clear();this.direction=null;this.sequence++;this.lastFrame=null;
    }
    reconcile(state,id) {
        const confirmed=state?.players?.[id];
        if (!confirmed) {this.player=null;return;}
        const ack=state.input_ack?.[id] ?? 0;
        const x=confirmed.position_x ?? confirmed.x, y=confirmed.position_y ?? confirmed.y;
        if (!this.player || !confirmed.alive || state.rules?.local_prediction === false) {
            this.player={...confirmed,position_x:x,position_y:y};this.correction={x:0,y:0};
        } else if (ack >= this.sequence || !isFree(state,this.player,this.player.position_x,this.player.position_y)) {
            const ex=x-this.player.position_x, ey=y-this.player.position_y;
            const invalid=!isFree(state,this.player,this.player.position_x,this.player.position_y);
            if (invalid || Math.hypot(ex,ey) > (state.rules?.visual_correction_limit ?? 0.75)) {
                this.player={...confirmed,position_x:x,position_y:y};this.correction={x:0,y:0};this.corrections++;
            } else {
                this.correction={x:ex,y:ey};
            }
        }
        this.lastAcknowledged=Math.max(this.lastAcknowledged,ack);
        if (!confirmed.alive || state.is_game_over) this.clear();
    }
    update(state,id,now) {
        if (!this.player) this.reconcile(state,id);
        const dt=this.lastFrame===null?0:Math.min(0.05,Math.max(0,(now-this.lastFrame)/1000));
        this.lastFrame=now;
        if (!this.player) return null;
        const previousX=this.player.position_x,previousY=this.player.position_y;
        const collisionState = {...state,bombs:Object.fromEntries(Object.entries(state.bombs || {}).map(([key,bomb]) => [key,this.exitedBombs.has(key) ? {...bomb,pass_through_players:(bomb.pass_through_players || []).filter(playerId => playerId !== id)} : bomb]))};
        if (this.connected && state.rules?.local_prediction !== false && !state.is_game_over) this.player=movementStep(collisionState,this.player,this.direction,dt);
        for (const [key,bomb] of Object.entries(state.bombs || {})) {
            if (Math.abs(this.player.position_x-bomb.x)>=0.78 || Math.abs(this.player.position_y-bomb.y)>=0.78) this.exitedBombs.add(key);
        }
        if (this.direction !== null || this.lastAcknowledged >= this.sequence) {
            const factor=Math.min(1,dt*8);
            const x=this.player.position_x+this.correction.x*factor,y=this.player.position_y+this.correction.y*factor;
            if (isFree(state,this.player,x,y)) {this.player.position_x=x;this.player.position_y=y;}
            this.correction.x*=1-factor;this.correction.y*=1-factor;
        }
        const moving=Math.abs(previousX-this.player.position_x)+Math.abs(previousY-this.player.position_y)>0.00001 && this.direction!==null;
        if(moving && this.visualResponseMs===null && this.lastPressedAt!==null) this.visualResponseMs=Math.max(0,performance.now()-this.lastPressedAt);
        return {id,x:this.player.position_x,y:this.player.position_y,direction:this.player.direction,continuous:true,isMoving:moving};
    }
}
