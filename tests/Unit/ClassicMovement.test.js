import assert from 'node:assert/strict';
import test from 'node:test';
import {ClassicController, movementStep} from '../../resources/js/game/classicMovement.js';

function state() {
    return {rules:{movement_speed:4,player_collisions:false,local_prediction:true,corner_assist:0.2},grid:Array.from({length:11},()=>Array(13).fill(0)),bombs:{},players:{p0:{id:'p0',x:1,y:1,alive:true,speed:1},p1:{id:'p1',x:3,y:1,alive:true,speed:1}},input_ack:{}};
}

for (const fps of [30,60,120]) {
    test(`constant speed is independent of rendering at ${fps} FPS`,()=>{
        const s=state(),controller=new ClassicController();
        controller.press('right','right');controller.update(s,'p0',0);
        for(let frame=1;frame<=fps;frame++) controller.update(s,'p0',frame*1000/fps);
        assert.ok(Math.abs(controller.player.position_x-5)<0.0001);
        assert.equal(s.players.p0.x,1);
    });
}

test('ten-second hold hits the wall without accelerating and release stops on the first frame',()=>{
    const s=state(),c=new ClassicController();s.grid.forEach(row=>row[12]=1);
    c.press('right','right');c.update(s,'p0',0);
    for(let frame=1;frame<=600;frame++) c.update(s,'p0',frame*1000/60);
    assert.ok(c.player.position_x<=11.22);
    c.release('right');const stopped=c.player.position_x;
    for(let frame=601;frame<=720;frame++) c.update(s,'p0',frame*1000/60);
    assert.equal(c.player.position_x,stopped);
});

test('opposite direction is last pressed, OS repeat does not change it and release restores the held key',()=>{
    const c=new ClassicController();
    c.press('a','left');c.press('d','right');
    assert.equal(c.press('a','left'),false);assert.equal(c.direction,'right');
    c.release('d');assert.equal(c.direction,'left');c.release('a');assert.equal(c.direction,null);
});

for(const event of ['blur','visibilitychange','disconnect','pointercancel']) {
    test(`${event} clears current input without a backlog on reconnect`,()=>{
        const s=state(),c=new ClassicController();c.press('key','right');c.update(s,'p0',0);c.update(s,'p0',16);
        c.clear();c.connected=false;const stopped=c.player.position_x;
        c.update(s,'p0',3000);c.connected=true;c.update(s,'p0',6000);
        assert.equal(c.direction,null);assert.equal(c.keys.size,0);assert.equal(c.player.position_x,stopped);
    });
}

test('a suspended frame cannot replay the time spent paused',()=>{
    const s=state(),c=new ClassicController();c.press('key','right');c.update(s,'p0',0);c.update(s,'p0',10000);
    assert.ok(c.player.position_x<=1.200001);
});

test('players cross and dead markers never obstruct, while configured solid collisions still work',()=>{
    const s=state();s.players.p1.x=2;
    let p=s.players.p0;
    for(let i=0;i<10;i++) p=movementStep(s,p,'right',0.05);
    assert.ok(Math.abs(p.position_x-3)<0.000001);
    s.rules.player_collisions=true;p=s.players.p0;
    for(let i=0;i<10;i++) p=movementStep(s,p,'right',0.05);
    assert.ok(p.position_x<1.45);
    s.players.p1.alive=false;
    for(let i=0;i<10;i++) p=movementStep(s,p,'right',0.05);
    assert.ok(p.position_x>2);
});

test('bomb exit permission is removed locally after leaving even with an old server snapshot',()=>{
    const s=state(),c=new ClassicController();s.bombs.b={id:'b',x:1,y:1,pass_through_players:['p0']};
    c.press('right','right');c.update(s,'p0',0);
    for(let i=1;i<=20;i++) c.update(s,'p0',i*16);
    c.release('right');c.press('left','left');
    for(let i=21;i<=60;i++) c.update(s,'p0',i*16);
    assert.ok(c.player.position_x>=1.78);
    assert.equal(s.bombs.b.pass_through_players[0],'p0');
});

test('corners center along one axis with bounded movement, without diagonal teleport',()=>{
    const s=state();let p={...s.players.p0,position_x:1.15,position_y:1};
    p=movementStep(s,p,'down',0.016);
    assert.ok(Math.abs(p.position_x-1.086)<0.000001);assert.equal(p.position_y,1);
});

for(const latency of [30,100,200,300]) {
    test(`delayed snapshots at ${latency}ms cannot resume released input or override elimination`,()=>{
        const s=state(),c=new ClassicController();c.press('key','right');c.update(s,'p0',0);c.update(s,'p0',latency);
        c.release('key');const stopped=c.player.position_x;
        s.input_ack.p0=0;c.reconcile(s,'p0');c.update(s,'p0',latency+16);
        assert.equal(c.player.position_x,stopped);
        s.players.p0.alive=false;c.reconcile(s,'p0');
        assert.equal(c.player.alive,false);assert.equal(c.direction,null);
    });
}
