import assert from 'node:assert/strict';
import test from 'node:test';
import {ClassicController, movementStep} from '../../resources/js/game/classicMovement.js';

function state() {
    return {input_protocol:3,round_id:'round-a',version:1,tick:0,rules:{movement_speed:4,simulation_hz:20,player_collisions:false},grid:Array.from({length:20},()=>Array(100).fill(0)),bombs:{},input_ack:{p:0},players:{p:{id:'p',x:1,y:5,alive:true,speed:1}}};
}
test('A delayed processed snapshot must replay pending input instead of reversing the local player',()=>{
    const s=state(),c=new ClassicController();c.press('right','right');
    for(let time=0;time<=200;time+=50)c.update(s,'p',time,1000+time/1000);
    const before=c.player.position_x;
    s.version=2;s.tick=1;s.players.p={...s.players.p,position_x:1.2,position_y:5};s.input_ack.p=2;
    c.reconcile(s,'p');c.update(s,'p',216,1000.216);
    assert.ok(c.player.position_x>=before,'delayed ACK reversed movement: '+before+' -> '+c.player.position_x);
});

for (const rtt of [30,100,200,300]) test(`E ordered prediction at RTT ${rtt}ms with jitter and duplicates never reverses or resumes after release`,()=>{
    let authoritative=state();const c=new ClassicController(),network=[],inbox=new Map();let credit=0,maxPending=0;
    c.press('right','right');
    let last=1,stopped;
    for(let ms=0;ms<=5600;ms+=10){
        const time=1000+ms/1000;
        for(let i=network.length-1;i>=0;i--){
            if(network[i].at>ms)continue;
            const packet=network.splice(i,1)[0];
            if(packet.frames){for(const frame of packet.frames)if(frame.sequence>(authoritative.input_ack.p||0))inbox.set(frame.sequence,frame);}
            else if(packet.snapshot){c.reconcile(packet.snapshot,'p');c.reconcile(packet.snapshot,'p');}
        }
        // Process snapshots independently of frame insertion (no dangling else).
        if(ms%50===0 && ms>0){
            credit=Math.min(.1,credit+.05);
            for(const [seq,frame] of [...inbox].sort((a,b)=>a[0]-b[0])){
                if(frame.duration>credit+1e-6)break;
                credit-=frame.duration;inbox.delete(seq);
                authoritative.players.p=movementStep(authoritative,authoritative.players.p,frame.direction,frame.duration);
                authoritative.input_ack.p=seq;authoritative.version++;
            }
            authoritative.tick=ms/50;
            network.push({at:ms+rtt/2+(ms%3)*2,snapshot:structuredClone(authoritative)});
        }
        // Deliver snapshots explicitly, including a duplicate of every confirmation.
        for(let i=network.length-1;i>=0;i--)if(network[i].snapshot && network[i].at<=ms){const p=network.splice(i,1)[0];c.reconcile(p.snapshot,'p');c.reconcile(p.snapshot,'p');}
        c.update(authoritative,'p',ms,time);
        if(ms===5000){c.release('right');stopped=c.player.position_x;}
        if(ms%100===0 || ms===5000){const frames=c.batch(time);if(frames.length)network.push({at:ms+rtt/2+(ms%7),frames});}
        maxPending=Math.max(maxPending,c.pending.length);
        assert.ok(c.player.position_x>=last-1e-5,`reversal at ${ms}: ${last}->${c.player.position_x}`);
        if(ms>5000)assert.ok(Math.abs(c.player.position_x-stopped)<1e-5,'released input moved again');
        last=c.player.position_x;
    }
    assert.ok(maxPending<=24);
    assert.ok(Math.abs(c.player.position_x-authoritative.players.p.position_x)<1e-5);
});

test('F obsolete snapshots and confirmed inputs cannot replace or replay a newer position',()=>{
    const s=state(),c=new ClassicController();c.update(s,'p',0,1000);c.press('r','right');c.update(s,'p',50,1000.05);
    const seq=c.pending.at(-1).sequence;
    const latest=structuredClone(s);latest.version=3;latest.tick=2;latest.input_ack.p=seq;latest.players.p.position_x=1.2;latest.players.p.position_y=5;
    c.reconcile(latest,'p');assert.equal(c.pending.length,0);const x=c.player.position_x;
    c.reconcile(s,'p');c.reconcile(latest,'p');assert.equal(c.player.position_x,x);
});

test('C bomb marker is between flushed motion and future motion, without changing authoritative state',()=>{
    const s=state(),c=new ClassicController();s.players.p.x=4;c.update(s,'p',0,1000);c.press('r','right');
    c.update(s,'p',30,1000.03);assert.equal(c.queueBomb('unique_bomb_action',1000.03),true);c.update(s,'p',80,1000.08);
    const frames=c.batch(1000.08),actionIndex=frames.findIndex(f=>f.action_id);
    assert.equal(frames[actionIndex-1].duration,.03);assert.equal(frames[actionIndex].duration,0);assert.ok(actionIndex<frames.length-1);
    assert.equal(c.provisionalBombs.unique_bomb_action.x,4);assert.deepEqual(s.bombs,{});assert.equal(s.players.p.x,4);
});

test('H reconnect discards unacknowledged motion and sends only a reset before new input',()=>{
    const s=state(),c=new ClassicController();c.update(s,'p',0,1000);c.press('r','right');c.update(s,'p',50,1000.05);
    c.discardPending();const frames=c.batch(1005);assert.equal(frames.length,1);assert.equal(frames[0].sampled_at,1005);assert.equal(frames[0].reset,true);assert.equal(frames[0].duration,0);
    c.update(s,'p',5000,1005);assert.equal(c.player.position_x,1);assert.equal(c.direction,null);
});

test('contact with a wall cannot reverse when a partial visual frame becomes a complete input frame',()=>{
    const s=state(),c=new ClassicController();s.players.p.x=9;s.grid[5][8]=1;
    c.update(s,'p',0,1000);c.press('l','left');
    let last=9;
    for(let ms=10;ms<=500;ms+=10){c.update(s,'p',ms,1000+ms/1000);assert.ok(c.player.position_x<=last+0.000002,`${last}->${c.player.position_x}`);last=c.player.position_x;}
    assert.ok(last>=8.78 && last<=8.780002);
});

test('idle rendering never creates an accumulating queue of movement frames',()=>{
    const s=state(),c=new ClassicController();for(let ms=0;ms<=10000;ms+=10)c.update(s,'p',ms,1000+ms/1000);
    assert.equal(c.pending.length,0);c.press('r','right');c.update(s,'p',10050,1010.05);assert.equal(c.pending.length,1);
});

test('administrative prediction setting changes the preview while ordered input still reaches the server',()=>{
    const s=state(),c=new ClassicController();s.rules.local_prediction=false;c.update(s,'p',0,1000);c.press('r','right');
    const preview=c.update(s,'p',50,1000.05);assert.equal(preview.x,1);assert.equal(c.batch(1000.05).length,1);
});

for(const fps of [30,60,120])test(`ordered input preserves speed and immediate release at ${fps} FPS`,()=>{
    const s=state(),c=new ClassicController();c.update(s,'p',0,1000);c.press('r','right');
    for(let step=1;step<=fps;step++)c.update(s,'p',step*1000/fps,1000+step/fps);
    assert.ok(Math.abs(c.player.position_x-5)<.00001);c.release('r');const stopped=c.player.position_x;
    c.update(s,'p',1100,1001.1);assert.equal(c.player.position_x,stopped);
});

test('late confirmations retain the full prediction history, independently of the 24-frame transport batch',()=>{
    const s=state(),c=new ClassicController();c.update(s,'p',0,1000);c.press('r','right');
    for(let step=1;step<=40;step++)c.update(s,'p',step*50,1000+step*.05);
    assert.equal(c.pending.length,40);assert.ok(c.batch(1002).length<=24);
    const expected=c.player.position_x;s.version=2;s.tick=1;s.input_ack.p=c.pending[0].sequence;s.players.p.position_x=1.2;s.players.p.position_y=5;
    c.reconcile(s,'p');assert.ok(Math.abs(c.player.position_x-expected)<.00001);
});

test('an exhausted prediction history requests resynchronization instead of silently deleting inputs',()=>{
    const s=state(),c=new ClassicController();c.update(s,'p',0,1000);c.press('r','right');
    for(let step=1;step<=65;step++)c.update(s,'p',step*50,1000+step*.05);
    assert.equal(c.needsResync,true);assert.equal(c.direction,null);assert.equal(c.pending.length,1);assert.equal(c.pending[0].reset,true);
});
