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
        this.pending = [];
        this.partial = 0;
        this.committed = null;
        this.ordered = false;
        this.serverTime = 0;
        this.provisionalBombs = {};
        this.positionError = 0;
        this.bombConfirmationMs = null;
    }
    press(key,direction) {
        if (this.keys.has(key)) return false;
        this.flushPartial();
        this.keys.set(key,direction);
        this.direction = direction;
        this.lastPressedAt = performance.now();this.visualResponseMs = null;
        this.sequence++;
        return true;
    }
    release(key) {
        if (this.keys.has(key)) this.flushPartial();
        if (!this.keys.delete(key)) return false;
        this.direction = [...this.keys.values()].at(-1) ?? null;
        this.sequence++;
        if (this.ordered) this.frame(0, this.direction);
        return true;
    }
    clear() {
        this.flushPartial();
        this.keys.clear();this.direction=null;this.sequence++;this.lastFrame=null;
        if (this.ordered) this.frame(0, null);
    }
    reconcile(state,id) {
        if (state?.input_protocol === 3) return this.reconcileOrdered(state,id);
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
    update(state,id,now,serverTime = Date.now()/1000) {
        if (state?.input_protocol === 3) return this.updateOrdered(state,id,now,serverTime);
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

    frame(duration, direction, extra = {}) {
        if (this.pending.length >= 64) {
            this.discardPending();
            this.needsResync=true;
            return null;
        }
        const entry = {sequence:++this.sequence,duration,direction,sampled_at:this.serverTime,...extra};
        this.pending.push(entry);
        return entry;
    }
    flushPartial() {
        if (!this.ordered || this.partial <= 0 || !this.committed || !this.predictionState) return;
        if (!this.frame(this.partial,this.direction)) return;
        this.committed = this.stepOrdered(this.predictionState,this.committed,this.direction,this.partial);
        this.partial = 0;
        this.player = {...this.committed};
    }
    stepOrdered(state, player, direction, duration) {
        const bombs = Object.fromEntries(Object.entries(state.bombs || {}).map(([key,bomb]) => [key,this.exitedBombs.has(key) ? {...bomb,pass_through_players:(bomb.pass_through_players || []).filter(id => id !== player.id)} : bomb]));
        const next = movementStep({...state,bombs},player,direction,duration);
        for (const [key,bomb] of Object.entries(bombs)) {
            if (Math.abs(next.position_x-bomb.x)>=0.78 || Math.abs(next.position_y-bomb.y)>=0.78) this.exitedBombs.add(key);
        }
        return next;
    }
    discardPending() {
        this.pending=[];this.partial=0;this.committed=null;this.player=null;this.lastFrame=null;
        this.provisionalBombs={};this.exitedBombs.clear();
        this.keys.clear();this.direction=null;
        if (this.ordered) this.frame(0,null,{reset:true});
    }
    reconcileOrdered(state,id) {
        const confirmed=state.players?.[id];
        if (!confirmed) return;
        if (this.roundId === state.round_id && ((state.version ?? 0) < (this.stateVersion ?? 0) || (state.tick ?? 0) < (this.stateTick ?? 0))) return;
        if (this.roundId && this.roundId !== state.round_id) {
            this.pending=[];this.partial=0;this.committed=null;this.exitedBombs.clear();this.provisionalBombs={};this.lastAcknowledged=0;
        }
        this.roundId=state.round_id;this.stateVersion=state.version ?? 0;this.stateTick=state.tick ?? 0;
        this.ordered=true;this.predictionState=state;
        const ack=state.input_ack?.[id] ?? 0;
        this.lastAcknowledged=Math.max(this.lastAcknowledged,ack);
        this.sequence=Math.max(this.sequence,ack);
        this.pending=this.pending.filter(input => input.sequence>this.lastAcknowledged);
        if (!confirmed.alive || state.is_game_over) {this.pending=[];this.partial=0;this.keys.clear();this.direction=null;}
        let replay={...confirmed,position_x:confirmed.position_x ?? confirmed.x,position_y:confirmed.position_y ?? confirmed.y};
        // Permission to leave a bomb is replayed from the confirmed position.
        this.exitedBombs.clear();
        for (const input of this.pending) replay=this.stepOrdered(state,replay,input.direction,input.duration);
        this.committed=replay;
        const predicted=this.stepOrdered(state,replay,this.direction,this.partial);
        if (this.player) {
            this.positionError=Math.hypot(predicted.position_x-this.player.position_x,predicted.position_y-this.player.position_y);
            if (this.positionError>0.001) {
                this.corrections++;
                this.lastCorrection={error:this.positionError,ack,confirmed:{...confirmed},before:{...this.player},after:{...predicted},pending:this.pending.map(input=>({...input}))};
            }
        }
        this.player=predicted;
        for (const [action,bomb] of Object.entries(this.provisionalBombs)) {
            if (state.action_results?.[id]?.[action] || ack>=bomb.sequence) {
                this.bombConfirmationMs=Math.max(0,performance.now()-bomb.started);
                delete this.provisionalBombs[action];
            }
        }
    }
    updateOrdered(state,id,now,serverTime) {
        this.serverTime=serverTime;
        if (!this.committed) this.reconcileOrdered(state,id);
        this.predictionState=state;
        const dt=this.lastFrame===null?0:Math.min(0.05,Math.max(0,(now-this.lastFrame)/1000));
        this.lastFrame=now;
        if (!this.committed) return null;
        const before=this.player?.position_x, beforeY=this.player?.position_y;
        if (this.connected && this.direction !== null && state.players[id]?.alive && !state.is_game_over) {
            this.partial+=dt;
            const interval=1/(state.rules?.simulation_hz ?? 20);
            while (this.partial+0.000001>=interval) {
                if (!this.frame(interval,this.direction)) break;
                this.committed=this.stepOrdered(state,this.committed,this.direction,interval);
                this.partial=Math.max(0,this.partial-interval);
            }
        }
        if (!this.committed) return null;
        this.player=this.stepOrdered(state,this.committed,this.direction,this.partial);
        const moving=this.direction!==null && Math.hypot(this.player.position_x-before,this.player.position_y-beforeY)>0.00001;
        if (moving && this.visualResponseMs===null && this.lastPressedAt!==null) this.visualResponseMs=Math.max(0,performance.now()-this.lastPressedAt);
        if (state.rules?.local_prediction === false) {
            const confirmed=state.players[id];
            return {id,x:confirmed.position_x ?? confirmed.x,y:confirmed.position_y ?? confirmed.y,direction:confirmed.direction,continuous:true,isMoving:false};
        }
        return {id,x:this.player.position_x,y:this.player.position_y,direction:this.player.direction,continuous:true,isMoving:moving};
    }
    queueBomb(actionId,serverTime) {
        if (!this.ordered || !this.connected || !this.player?.alive) return false;
        this.serverTime=serverTime;
        this.flushPartial();
        if (!this.player) return false;
        const input=this.frame(0,null,{action_id:actionId});
        if (!input) return false;
        this.provisionalBombs[actionId]={id:actionId,x:this.player.x,y:this.player.y,owner_id:this.player.id,sequence:input.sequence,started:performance.now(),planted_at:serverTime,explodes_at:serverTime+(this.predictionState.rules?.bomb_fuse_ms ?? 2000)/1000,range:this.player.bomb_range,state:'active'};
        return true;
    }
    batch(serverTime) {
        this.serverTime=serverTime;
        return this.pending.filter(input => input.reset || serverTime-input.sampled_at<=0.5).slice(-24).map(input => ({...input,...(input.reset ? {sampled_at:serverTime} : {})}));
    }
}
