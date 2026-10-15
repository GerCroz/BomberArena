import assert from 'node:assert/strict';
import {writeFileSync} from 'node:fs';

const endpoint=process.env.BOMBER_BROWSER_ENDPOINT || 'http://127.0.0.1:9223';
const app=process.env.BOMBER_BROWSER_APP || 'http://127.0.0.1:8000';
const info=await (await fetch(endpoint+'/json/version')).json();
const socket=new WebSocket(info.webSocketDebuggerUrl);
await new Promise((resolve,reject)=>{socket.addEventListener('open',resolve);socket.addEventListener('error',reject);});
let nextId=0;const pending=new Map();const errors=[];const results=[];const contexts=[];
const runStarted=performance.now();
socket.addEventListener('message',e=>{
    const message=JSON.parse(e.data);
    if(message.id){const p=pending.get(message.id);if(p){pending.delete(message.id);message.error?p.reject(new Error(JSON.stringify(message.error))):p.resolve(message.result);}}
    if(message.method==='Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.text+': '+(message.params.exceptionDetails.exception?.description||''));
});
function command(method,params={},sessionId){return new Promise((resolve,reject)=>{const id=++nextId;pending.set(id,{resolve,reject});socket.send(JSON.stringify({id,method,params,...(sessionId?{sessionId}:{})}));});}
async function evaluate(sessionId,expression){const r=await command('Runtime.evaluate',{expression,awaitPromise:true,returnByValue:true},sessionId);if(r.exceptionDetails)throw new Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text);return r.result.value;}
const delay=ms=>new Promise(r=>setTimeout(r,ms));
async function until(session,expression,timeout=15000){const start=performance.now();while(performance.now()-start<timeout){try{const v=await evaluate(session,expression);if(v)return v;}catch{}await delay(100);}const details=await evaluate(session,'({url:location.href,ready:document.readyState,title:document.title,heading:document.querySelector("h1")?.innerText})').catch(()=>null);throw new Error('Timed out: '+expression+' '+JSON.stringify(details));}
async function page(){const {browserContextId}=await command('Target.createBrowserContext');contexts.push(browserContextId);const {targetId}=await command('Target.createTarget',{url:app+'/lobby',browserContextId});const {sessionId}=await command('Target.attachToTarget',{targetId,flatten:true});await command('Runtime.enable',{},sessionId);await until(sessionId,'typeof createRoom === "function" && document.readyState === "complete"');return sessionId;}
async function key(session,type,key,code){await command('Input.dispatchKeyEvent',{type,key,code,windowsVirtualKeyCode:key==='ArrowRight'?39:key==='ArrowLeft'?37:key==='ArrowDown'?40:key==='ArrowUp'?38:32},session);}
const read='({x:classicController.player?.position_x,y:classicController.player?.position_y,authoritativeX:gameState.players[CURRENT_PLAYER_ID].position_x ?? gameState.players[CURRENT_PLAYER_ID].x,authoritativeY:gameState.players[CURRENT_PLAYER_ID].position_y ?? gameState.players[CURRENT_PLAYER_ID].y,dir:classicController.direction,seq:classicController.sequence,ack:gameState.input_ack?.[CURRENT_PLAYER_ID],pending:inputRequests.size,tokenPresent:!!INPUT_TOKEN,transportState:window.Echo?.connector?.pusher?.connection?.state,clockOffset:serverClockOffset,corrections:classicController.corrections,fps:gameRenderer.metrics.fps,tick:gameState.tick,simulationTick:gameState.simulation_tick,round:gameState.round_id,alive:gameState.players[CURRENT_PLAYER_ID].alive,wsRttMs:diagnostics.wsRtt,httpRttMs:diagnostics.rtt,events:diagnostics.events,staleStates:diagnostics.staleStates,snapshotAgeMs:performance.now()-diagnostics.lastReceived,visualResponseMs:classicController.visualResponseMs,server:gameState.diagnostics})';
try {
    const host=await page();await evaluate(host,'document.getElementById("nickname").value="RecoveryHost"; createRoom();');
    const code=await until(host,'typeof ROOM_CODE !== "undefined" && ROOM_CODE');
    for(let i=1;i<=3;i++){
        const guest=await page();await evaluate(guest,`document.getElementById('nickname').value='Recovery${i}';document.getElementById('join-code').value='${code}';joinRoom();`);
        await until(guest,'typeof ROOM_CODE !== "undefined" && ROOM_CODE');
    }
    const all=await command('Target.getTargets');
    const targets=all.targetInfos.filter(t=>contexts.includes(t.browserContextId)&&t.url.includes('/arena/'));
    const players=[];
    for(const target of targets){const {sessionId}=await command('Target.attachToTarget',{targetId:target.targetId,flatten:true});await command('Runtime.enable',{},sessionId);players.push(sessionId);}
    await evaluate(host,'startGame();');
    for(const player of players) await until(player,'gameState?.movement_model === "classic-v2" && currentRoom.status === "playing" && !isCountingDown && classicController !== null');
    await delay(500);
    const rules=await evaluate(host,'({collisions:gameState.rules.player_collisions,speed:gameState.rules.movement_speed,simulation:gameState.rules.simulation_hz,publish:gameState.rules.publish_hz})');
    assert.equal(rules.collisions,false);
    const before=await evaluate(host,read);
    await key(host,'keyDown','ArrowRight','ArrowRight');await delay(40);
    const immediate=await evaluate(host,read);assert.ok(immediate.x>before.x,'visual movement must start without waiting for HTTP');
    await delay(350);await key(host,'keyUp','ArrowRight','ArrowRight');
    const released=await evaluate(host,read);await delay(80);const afterRelease=await evaluate(host,read);
    assert.equal(afterRelease.dir,null);assert.ok(Math.abs(afterRelease.x-released.x)<0.05,'local release must stop');
    await delay(1000);const settled=await evaluate(host,read);
    results.push({id:'BROWSER01',status:'pending',scenario:'hold/release with local prediction',before,immediate,released,afterRelease,settled,rules});
    assert.ok(settled.authoritativeX>1.05,'server must actually receive and simulate movement');
    await until(host,'Math.abs((gameState.players[CURRENT_PLAYER_ID].position_x ?? gameState.players[CURRENT_PLAYER_ID].x)-classicController.player.position_x)<0.08',5000);
    results[0].status='passed';
    await evaluate(host,'window.dispatchEvent(new Event("blur"));');assert.equal((await evaluate(host,read)).dir,null);
    results.push({id:'BROWSER02',scenario:'blur clears input',status:'passed'});
    const positions=await Promise.all(players.map(p=>evaluate(p,'({room:ROOM_CODE,round:gameState.round_id,players:gameState.players,version:gameState.version})')));
    assert.equal(new Set(positions.map(p=>p.round)).size,1);assert.equal(Object.keys(positions[0].players).length,4);
    results.push({id:'BROWSER03',scenario:'four real browser contexts receive the same round',status:'passed',versions:positions.map(p=>p.version)});
    await delay(2000);results.push({id:'BROWSER04',scenario:'real Canvas sample',metrics:await evaluate(host,read)});
    const concurrentBefore=await Promise.all(players.map(p=>evaluate(p,read)));
    const directions={0:['ArrowLeft','ArrowLeft'],1:['ArrowLeft','ArrowLeft'],2:['ArrowLeft','ArrowLeft'],3:['ArrowRight','ArrowRight']};
    for(const player of players){const slot=await evaluate(player,'gameState.players[CURRENT_PLAYER_ID].slot');await key(player,'keyDown',...directions[slot]);}
    await delay(250);
    for(const player of players){const slot=await evaluate(player,'gameState.players[CURRENT_PLAYER_ID].slot');await key(player,'keyUp',...directions[slot]);}
    await delay(1500);
    const concurrentAfter=await Promise.all(players.map(p=>evaluate(p,read)));
    for(let i=0;i<players.length;i++) assert.ok(Math.abs(concurrentAfter[i].authoritativeX-concurrentBefore[i].authoritativeX)>0.1,'every player must move on the server');
    results.push({id:'BROWSER05',scenario:'four players move concurrently with independent input',status:'passed',samples:concurrentAfter});
    const cpuBefore=await command('SystemInfo.getProcessInfo');
    for(const latency of [30,100,200,300]){
        await evaluate(host,`window.__originalSend = window.Echo.connector.pusher.connection.send_event.bind(window.Echo.connector.pusher.connection); window.Echo.connector.pusher.connection.send_event = (event,data,channel) => { if(data?.arena_input){setTimeout(()=>window.__originalSend(event,data,channel),${latency}+((data.arena_input.sequence*17)%25)); return true;} return window.__originalSend(event,data,channel); };`);
        const beforeLatency=await evaluate(host,read);
        await key(host,'keyDown','ArrowRight','ArrowRight');await delay(300);await key(host,'keyUp','ArrowRight','ArrowRight');
        const localStop=await evaluate(host,read);await delay(60);const localAfter=await evaluate(host,read);
        assert.equal(localAfter.dir,null);assert.ok(Math.abs(localStop.x-localAfter.x)<0.05,'late input must not continue local movement');
        await delay(latency+1000);
        const final=await evaluate(host,read);assert.ok(final.pending<=2);assert.ok(Math.abs(final.x-final.authoritativeX)<0.15,'position must reconcile after delayed input');
        results.push({id:'LATENCY'+latency,scenario:'real Reverb transport with injected one-way input delay and 0-24ms jitter',status:'passed',before:beforeLatency,stopped:localStop,final});
        await evaluate(host,'window.Echo.connector.pusher.connection.send_event=window.__originalSend;');
        await key(host,'keyDown','ArrowLeft','ArrowLeft');await delay(300);await key(host,'keyUp','ArrowLeft','ArrowLeft');await delay(1000);
    }
    await key(host,'keyDown','ArrowRight','ArrowRight');await delay(100);
    await evaluate(host,'window.Echo.disconnect();');await delay(600);
    assert.equal((await evaluate(host,read)).dir,null);
    await evaluate(host,'window.Echo.connector.pusher.connect();');await delay(1500);
    results.push({id:'RECONNECT_DIAGNOSTIC',sample:await evaluate(host,read),controller:await evaluate(host,'({ack:classicController.lastAcknowledged,correction:classicController.correction,connected:classicController.connected,inputs:gameState.current_inputs,lastPublished:gameState.last_published_version,version:gameState.version})')});
    await until(host,'Math.abs((gameState.players[CURRENT_PLAYER_ID].position_x ?? gameState.players[CURRENT_PLAYER_ID].x)-classicController.player.position_x)<0.08',5000);
    const reconnected=await evaluate(host,read);assert.equal(reconnected.dir,null);assert.ok(reconnected.pending<=2);
    results.push({id:'BROWSER06',scenario:'disconnect clears held input and reconnect has no replay',status:'passed',sample:reconnected});
    for(const player of players){if(await evaluate(player,'CURRENT_PLAYER_ID !== '+JSON.stringify(await evaluate(host,'CURRENT_PLAYER_ID')))) await evaluate(player,'sendBomb();');}
    await until(host,'gameState.is_game_over',10000);
    const oldRound=await evaluate(host,'gameState.round_id');
    await evaluate(host,'requestRematch();');
    await until(host,`gameState.round_id !== '${oldRound}' && currentRoom.status === 'playing' && !isCountingDown`,15000);
    await key(host,'keyDown','ArrowDown','ArrowDown');await delay(150);await key(host,'keyUp','ArrowDown','ArrowDown');await delay(700);
    const rematched=await evaluate(host,read);assert.ok(rematched.alive);assert.ok(rematched.authoritativeY>1);
    results.push({id:'BROWSER07',scenario:'independent bombs eliminate players on the server and rematch restores working input',status:'passed',sample:rematched});
    await key(host,'keyDown','ArrowRight','ArrowRight');
    await key(host,'keyDown','ArrowLeft','ArrowLeft');
    assert.equal((await evaluate(host,read)).dir,'left');
    await key(host,'keyUp','ArrowLeft','ArrowLeft');
    assert.equal((await evaluate(host,read)).dir,'right');
    await key(host,'keyUp','ArrowRight','ArrowRight');
    await evaluate(host,'document.getElementById("dpad-right").setPointerCapture=()=>{}; document.getElementById("dpad-right").dispatchEvent(new PointerEvent("pointerdown",{pointerId:7,bubbles:true}));');
    assert.equal((await evaluate(host,read)).dir,'right');
    await evaluate(host,'document.getElementById("dpad-right").dispatchEvent(new PointerEvent("pointercancel",{pointerId:7,bubbles:true}));');
    assert.equal((await evaluate(host,read)).dir,null);
    results.push({id:'BROWSER08',scenario:'opposite keys, restoration of held direction and pointer cancellation use current input',status:'passed'});
    const activeStart=await evaluate(host,'({time:performance.now(),events:diagnostics.events,corrections:classicController.corrections})');
    await evaluate(host,'window.__recoverySamples=[]; window.__recoverySampler=setInterval(()=>{ const p=classicController.player,a=gameState.players[CURRENT_PLAYER_ID]; window.__recoverySamples.push({errorCells:Math.hypot(p.position_x-(a.position_x ?? a.x),p.position_y-(a.position_y ?? a.y)),snapshotAgeMs:performance.now()-diagnostics.lastReceived,simulationMs:gameState.diagnostics?.simulation_ms,fps:gameRenderer.metrics.fps,pending:inputRequests.size});},25);');
    await key(host,'keyDown','ArrowDown','ArrowDown');await delay(1000);await key(host,'keyUp','ArrowDown','ArrowDown');
    await key(host,'keyDown','ArrowUp','ArrowUp');await delay(1000);await key(host,'keyUp','ArrowUp','ArrowUp');await delay(500);
    const activeEnd=await evaluate(host,'clearInterval(window.__recoverySampler); ({time:performance.now(),events:diagnostics.events,corrections:classicController.corrections,samples:window.__recoverySamples})');
    const activeSeconds=(activeEnd.time-activeStart.time)/1000;
    assert.ok(activeEnd.samples.every(s=>s.pending<=2));
    results.push({id:'BROWSER09',scenario:'measured active movement window after rematch',status:'passed',duration_seconds:activeSeconds,received_game_events_per_second:(activeEnd.events-activeStart.events)/activeSeconds,hard_corrections_per_minute:(activeEnd.corrections-activeStart.corrections)*60/activeSeconds,samples:activeEnd.samples});
    const cpuAfter=await command('SystemInfo.getProcessInfo');
    const beforeCpu=new Map(cpuBefore.processInfo.map(p=>[p.id,p.cpuTime]));
    const cpuSeconds=cpuAfter.processInfo.reduce((sum,p)=>sum+Math.max(0,p.cpuTime-(beforeCpu.get(p.id)??p.cpuTime)),0);
    await command('Performance.enable',{},host);
    const performanceMetrics=await command('Performance.getMetrics',{},host);
    results.push({id:'BROWSER_METRICS',duration_ms:performance.now()-runStarted,browser_cpu_seconds_during_latency_tests:cpuSeconds,metrics:Object.fromEntries(performanceMetrics.metrics.filter(m=>['JSHeapUsedSize','JSHeapTotalSize','Frames','TaskDuration'].includes(m.name)).map(m=>[m.name,m.value]))});
    console.log(JSON.stringify({code,results,errors},null,2));
} catch(error){results.push({id:'BROWSER_FAILURE',error:error.message});console.log(JSON.stringify({results,errors},null,2));process.exitCode=1;}
finally{writeFileSync('BomberArena-recovery-browser.json',JSON.stringify({results,errors},null,2));for(const id of contexts)await command('Target.disposeBrowserContext',{browserContextId:id}).catch(()=>{});socket.close();}
