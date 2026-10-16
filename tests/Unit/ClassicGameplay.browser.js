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
const read='({x:classicController.player?.position_x,y:classicController.player?.position_y,authoritativeX:gameState.players[CURRENT_PLAYER_ID].position_x ?? gameState.players[CURRENT_PLAYER_ID].x,authoritativeY:gameState.players[CURRENT_PLAYER_ID].position_y ?? gameState.players[CURRENT_PLAYER_ID].y,dir:classicController.direction,seq:classicController.sequence,ack:gameState.input_ack?.[CURRENT_PLAYER_ID],pending:classicController.ordered ? classicController.pending.length : inputRequests.size,tokenPresent:!!INPUT_TOKEN,transportState:window.Echo?.connector?.pusher?.connection?.state,clockOffset:serverClockOffset,corrections:classicController.corrections,fps:gameRenderer.metrics.fps,tick:gameState.tick,simulationTick:gameState.simulation_tick,round:gameState.round_id,alive:gameState.players[CURRENT_PLAYER_ID].alive,wsRttMs:diagnostics.wsRtt,httpRttMs:diagnostics.rtt,events:diagnostics.events,staleStates:diagnostics.staleStates,snapshotAgeMs:performance.now()-diagnostics.lastReceived,visualResponseMs:classicController.visualResponseMs,server:gameState.diagnostics})';
try {
    const observer=await page();
    await evaluate(observer,"window.__lobbyEvents=0;window.Echo.channel('arena-lobby').listen('.LobbyRoomsChanged',()=>window.__lobbyEvents++);true;");
    const host=await page();await evaluate(host,'document.getElementById("nickname").value="RecoveryHost";document.getElementById("room-name").value="Social Recovery Arena";createRoom();');
    const code=await until(host,'typeof ROOM_CODE !== "undefined" && ROOM_CODE');
    await until(observer,`document.querySelector('[data-room-code="${code}"] button:not(:disabled)')`);
    assert.ok(await evaluate(observer,'window.__lobbyEvents>0'),'lobby must receive Reverb invalidation');
    await evaluate(observer,"document.getElementById('room-search').value='no-match-xyz';document.getElementById('room-search').dispatchEvent(new Event('input'));");
    assert.equal(await evaluate(observer,`!!document.querySelector('[data-room-code="${code}"]')`),false);
    await evaluate(observer,"document.getElementById('room-search').value='Social Recovery';document.getElementById('room-search').dispatchEvent(new Event('input'));");
    await until(observer,`!!document.querySelector('[data-room-code="${code}"]')`);
    for(let i=1;i<=3;i++){
        const guest=await page();
        await evaluate(guest,`document.getElementById('nickname').value='Recovery${i}';selectLobbyCharacter('${['neon-blue','neon-pink','neon-green','neon-yellow'][i]}');`);
        if(i===1) {
            await until(guest,`!!document.querySelector('[data-room-code="${code}"] button:not(:disabled)')`);
            await evaluate(guest,`document.querySelector('[data-room-code="${code}"] button').click();`);
        } else await evaluate(guest,`document.getElementById('join-code').value='${code}';joinRoom();`);
        await until(guest,'typeof ROOM_CODE !== "undefined" && ROOM_CODE');
    }
    const all=await command('Target.getTargets');
    const targets=all.targetInfos.filter(t=>contexts.includes(t.browserContextId)&&t.url.includes('/arena/'));
    const players=[];
    for(const target of targets){const {sessionId}=await command('Target.attachToTarget',{targetId:target.targetId,flatten:true});await command('Runtime.enable',{},sessionId);players.push(sessionId);}
    await until(observer,`document.querySelector('[data-room-code="${code}"] button:disabled')`);
    await evaluate(observer,"document.getElementById('available-only').checked=true;document.getElementById('available-only').dispatchEvent(new Event('change'));");
    assert.equal(await evaluate(observer,`!!document.querySelector('[data-room-code="${code}"]')`),false);
    await evaluate(observer,"document.getElementById('available-only').checked=false;document.getElementById('available-only').dispatchEvent(new Event('change'));");
    const temporary=await evaluate(host,`fetch('/api/rooms',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF_TOKEN},body:JSON.stringify({name:'Private capacity test',is_private:true,max_players:3,character_id:'neon-blue'})}).then(r=>r.json())`);
    const temporaryCode=temporary.room.code;
    const nonHost=[];
    for(const player of players)if(await evaluate(player,'CURRENT_PLAYER_ID')!==await evaluate(host,'CURRENT_PLAYER_ID'))nonHost.push(player);
    await evaluate(nonHost[0],`fetch('/api/rooms/join',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF_TOKEN},body:JSON.stringify({code:'${temporaryCode}',character_id:'neon-blue'})}).then(r=>r.json())`);
    const secondApp=process.env.BOMBER_BROWSER_SECOND_APP||app;
    const credentials=await Promise.all(nonHost.slice(1).map(async player=>{
        const cookies=await command('Network.getCookies',{urls:[app]},player);
        return {cookie:cookies.cookies.map(c=>c.name+'='+c.value).join('; '),csrf:await evaluate(player,'CSRF_TOKEN')};
    }));
    const attempts=await Promise.all(credentials.map(async (credential,index)=>{
        const response=await fetch((index===0?app:secondApp)+'/api/rooms/join',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':credential.csrf,'Cookie':credential.cookie},body:JSON.stringify({code:temporaryCode,character_id:'neon-blue'})});
        return {status:response.status,data:await response.json()};
    }));
    assert.deepEqual(attempts.map(a=>a.status).sort(),[200,422],'two HTTP workers must not overfill the last place');
    await evaluate(observer,"document.getElementById('room-search').value='';document.getElementById('room-search').dispatchEvent(new Event('input'));");
    assert.equal(await evaluate(observer,`!!document.querySelector('[data-room-code="${temporaryCode}"]')`),false,'private room must not appear');
    for(const player of players)await evaluate(player,`fetch('/api/rooms/${temporaryCode}/leave',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF_TOKEN},body:'{}'}).then(r=>r.json())`);
    results.push({id:'SOCIAL03',scenario:'name filters, availability filters, join via list, private visibility and last-slot race across HTTP workers',status:'passed',http_origins:[app,secondApp],last_place_statuses:attempts.map(a=>a.status)});
    await command('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true},observer);
    await delay(150);
    const mobile=await evaluate(observer,'({content:document.documentElement.scrollWidth,viewport:innerWidth})');
    assert.ok(mobile.content<=mobile.viewport+1,'mobile lobby must not overflow horizontally');
    const layout=await command('Page.getLayoutMetrics',{},observer);
    const screenshot=await command('Page.captureScreenshot',{format:'png',captureBeyondViewport:true,clip:{x:0,y:0,width:390,height:layout.cssContentSize.height,scale:1}},observer);
    writeFileSync('storage/app/social-lobby-mobile.png',Buffer.from(screenshot.data,'base64'));
    results.push({id:'SOCIAL04',scenario:'390px mobile viewport without horizontal overflow',status:'passed',metrics:mobile});
    await evaluate(host,"document.getElementById('room-character').value='neon-green';document.getElementById('room-character').dispatchEvent(new Event('change'));");
    await until(host,"currentRoom.players.find(p=>p.player_id===CURRENT_PLAYER_ID)?.character_id==='neon-green'");
    for(const player of players)await evaluate(player,"window.__rematchEvents=0;window.Echo.private('arena-social.'+ROOM_CODE).listen('.RoomSocialEvent',e=>{if(e.type.startsWith('rematch.'))window.__rematchEvents++;});true;");
    results.push({id:'SOCIAL01',scenario:'public listing updates from real Reverb and character selection changes before start',status:'passed'});
    await evaluate(host,'startGame();');
    for(const player of players) await until(player,'gameState?.movement_model === "classic-v2" && currentRoom.status === "playing" && !isCountingDown && classicController !== null');
    await delay(500);
    assert.equal(await evaluate(host,"gameState.players[CURRENT_PLAYER_ID].character_id"),'neon-green');
    assert.equal(await evaluate(host,"Object.values(gameState.players).filter(p=>p.color==='neon-green').length"),2);
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
    results.find(r=>r.id==='BROWSER01').status='passed';
    await evaluate(host,'window.dispatchEvent(new Event("blur"));');assert.equal((await evaluate(host,read)).dir,null);
    results.push({id:'BROWSER02',scenario:'blur clears input',status:'passed'});
    const positions=await Promise.all(players.map(p=>evaluate(p,'({room:ROOM_CODE,round:gameState.round_id,players:gameState.players,version:gameState.version})')));
    assert.equal(new Set(positions.map(p=>p.round)).size,1);assert.equal(Object.keys(positions[0].players).length,4);
    results.push({id:'BROWSER03',scenario:'four real browser contexts receive the same round',status:'passed',versions:positions.map(p=>p.version)});
    await delay(2000);results.push({id:'BROWSER04',scenario:'real Canvas sample',metrics:await evaluate(host,read)});
    const concurrentBefore=await Promise.all(players.map(p=>evaluate(p,read)));
    const directions={0:['ArrowLeft','ArrowLeft'],1:['ArrowLeft','ArrowLeft'],2:['ArrowLeft','ArrowLeft'],3:['ArrowRight','ArrowRight']};
    for(const player of players){const slot=await evaluate(player,'gameState.players[CURRENT_PLAYER_ID].slot');await key(player,'keyDown',...directions[slot]);}
    await Promise.all(players.map(player=>evaluate(player,'window.__motion=[];window.__motionTimer=setInterval(()=>window.__motion.push({x:classicController.player.position_x,y:classicController.player.position_y,dir:classicController.direction,ack:classicController.lastAcknowledged,pending:classicController.pending,committed:classicController.committed,authority:gameState.players[CURRENT_PLAYER_ID],error:classicController.positionError,serverTime:getCurrentServerTime(),local:performance.now(),diagnostics:gameState.diagnostics}),20);')));
    for(let turn=0;turn<50;turn++){
        await delay(200);
        if(turn===49)break;
        for(const player of players){
            const slot=await evaluate(player,'gameState.players[CURRENT_PLAYER_ID].slot');
            const original=directions[slot][0],previous=turn%2===0?original:(original==='ArrowLeft'?'ArrowRight':'ArrowLeft');
            const next=previous==='ArrowLeft'?'ArrowRight':'ArrowLeft';
            await key(player,'keyUp',previous,previous);await key(player,'keyDown',next,next);
        }
    }
    const motionSamples=await Promise.all(players.map(player=>evaluate(player,'clearInterval(window.__motionTimer);window.__motion')));
    for(const samples of motionSamples)for(let i=1;i<samples.length;i++){if(samples[i].dir===samples[i-1].dir && (samples[i].x-samples[i-1].x)*(samples[i].dir==='left'?-1:1)<-0.001)results.push({id:'REVERSE_FAILURE_TRACE',before:samples[i-1],after:samples[i]});assert.ok(samples[i].dir!==samples[i-1].dir || (samples[i].x-samples[i-1].x)*(samples[i].dir==='left'?-1:1)>=-0.001,'unjustified reverse during simultaneous movement');}
    results.push({id:'ORDERED_REVERSE',scenario:'four moving browsers change direction every 200ms for ten seconds without resting against walls; sampled every 20ms',status:'passed',samples:motionSamples.map(list=>list.map(({x,y,dir,ack,error,local,diagnostics,pending})=>({x,y,dir,ack,error,local,pending:pending.length,discarded:diagnostics.discarded_stale_frames ?? 0})))});
    for(const player of players){const slot=await evaluate(player,'gameState.players[CURRENT_PLAYER_ID].slot');const original=directions[slot][0],current=original==='ArrowLeft'?'ArrowRight':'ArrowLeft';await key(player,'keyUp',current,current);}
    await delay(1500);
    const concurrentAfter=await Promise.all(players.map(p=>evaluate(p,read)));
    for(const samples of motionSamples){const xs=samples.map(s=>s.authority.position_x ?? s.authority.x);assert.ok(Math.max(...xs)-Math.min(...xs)>.1,'every player must move on the server');}
    results.push({id:'BROWSER05',scenario:'four players move concurrently with independent input',status:'passed',samples:concurrentAfter});
    const cpuBefore=await command('SystemInfo.getProcessInfo');
    for(const latency of [30,100,200,300]){
        await evaluate(host,`window.__originalSend = window.Echo.connector.pusher.connection.send_event.bind(window.Echo.connector.pusher.connection); window.Echo.connector.pusher.connection.send_event = (event,data,channel) => { if(data?.arena_input){setTimeout(()=>window.__originalSend(event,data,channel),${latency}+((data.arena_input.sequence*17)%25)); return true;} return window.__originalSend(event,data,channel); };`);
        const beforeLatency=await evaluate(host,read);
        await key(host,'keyDown','ArrowRight','ArrowRight');await delay(300);await key(host,'keyUp','ArrowRight','ArrowRight');
        const localStop=await evaluate(host,read);await delay(60);const localAfter=await evaluate(host,read);
        assert.equal(localAfter.dir,null);assert.ok(Math.abs(localStop.x-localAfter.x)<0.05,'late input must not continue local movement');
        await delay(latency+1000);
        const final=await evaluate(host,read);assert.ok(final.pending<=64);assert.ok(Math.abs(final.x-final.authoritativeX)<0.15,'position must reconcile after delayed input');
        results.push({id:'LATENCY'+latency,scenario:'real Reverb transport with injected one-way input delay and 0-24ms jitter',status:'passed',before:beforeLatency,stopped:localStop,final});
        await evaluate(host,'window.Echo.connector.pusher.connection.send_event=window.__originalSend;');
        await key(host,'keyDown','ArrowLeft','ArrowLeft');await delay(300);await key(host,'keyUp','ArrowLeft','ArrowLeft');await delay(1000);
    }
    await key(host,'keyDown','ArrowRight','ArrowRight');await delay(100);
    await evaluate(host,'window.Echo.disconnect();');await delay(600);
    assert.equal((await evaluate(host,read)).dir,null);
    await evaluate(host,'window.Echo.connector.pusher.connect();');await delay(1500);
    results.push({id:'RECONNECT_DIAGNOSTIC',sample:await evaluate(host,read),controller:await evaluate(host,'({pendingFrames:classicController.pending,ack:classicController.lastAcknowledged,correction:classicController.correction,connected:classicController.connected,inputs:gameState.current_inputs,lastPublished:gameState.last_published_version,version:gameState.version})')});
    await until(host,'Math.abs((gameState.players[CURRENT_PLAYER_ID].position_x ?? gameState.players[CURRENT_PLAYER_ID].x)-classicController.player.position_x)<0.08',5000);
    const reconnected=await evaluate(host,read);assert.equal(reconnected.dir,null);assert.ok(reconnected.pending<=64);
    results.push({id:'BROWSER06',scenario:'disconnect clears held input and reconnect has no replay',status:'passed',sample:reconnected});
    for(const player of players){if(await evaluate(player,'CURRENT_PLAYER_ID !== '+JSON.stringify(await evaluate(host,'CURRENT_PLAYER_ID')))) await evaluate(player,'sendBomb();');}
    await until(host,'gameState.is_game_over',10000);
    const oldRound=await evaluate(host,'gameState.round_id');
    const winnerAttempt=await evaluate(host,`fetch('/api/rooms/'+ROOM_CODE+'/rematch',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF_TOKEN},body:JSON.stringify({round_id:gameState.round_id})}).then(async r=>({status:r.status,data:await r.json()}))`);
    assert.equal(winnerAttempt.status,422);
    const hostId=await evaluate(host,'CURRENT_PLAYER_ID');
    const losers=[];
    for(const player of players)if(await evaluate(player,'CURRENT_PLAYER_ID')!==hostId)losers.push(player);
    const requests=await Promise.all(losers.slice(0,2).map(player=>evaluate(player,`fetch('/api/rooms/'+ROOM_CODE+'/rematch',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF_TOKEN},body:JSON.stringify({round_id:gameState.round_id})}).then(async r=>({status:r.status,data:await r.json()}))`)));
    assert.deepEqual(requests.map(r=>r.status).sort(),[200,422],'only one concurrent request may be pending');
    await until(host,"currentRoom.rematch_request?.status==='pending'");
    assert.ok(await evaluate(host,'window.__rematchEvents>0'),'winner must receive a private Reverb notification');
    await evaluate(host,'window.ArenaSocial.respond(false);');
    await until(losers[0],"currentRoom.rematch_request?.status==='rejected'");
    assert.equal(await evaluate(host,'gameState.round_id'),oldRound);
    await evaluate(losers[0],'window.ArenaSocial.requestRematch();');
    await until(host,"currentRoom.rematch_request?.status==='pending'");
    const requester=await evaluate(host,'currentRoom.rematch_request.requested_by');
    for(const player of players)if(await evaluate(player,'CURRENT_PLAYER_ID')!==requester)await evaluate(player,'window.ArenaSocial.respond(true);');
    results.push({id:'SOCIAL02',scenario:'winner rejected; two concurrent losers create one request; rejection prevents restart; unanimous approval starts once',status:'passed'});
    await until(host,`gameState.round_id !== '${oldRound}' && currentRoom.status === 'playing' && !isCountingDown`,15000);
    await key(host,'keyDown','ArrowDown','ArrowDown');await delay(150);await key(host,'keyUp','ArrowDown','ArrowDown');await delay(700);
    const rematched=await evaluate(host,read);assert.ok(rematched.alive);
    assert.equal(await evaluate(host,"gameState.players[CURRENT_PLAYER_ID].character_id"),'neon-green');assert.ok(rematched.authoritativeY>1);
    results.push({id:'BROWSER07',scenario:'server bombs and voted rematch restore working input and preserve characters',status:'passed',sample:rematched});
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
    const activeStart=await evaluate(host,'({time:performance.now(),tick:gameState.simulation_tick,events:diagnostics.events,corrections:classicController.corrections})');
    await evaluate(host,'window.__recoverySamples=[]; window.__recoverySampler=setInterval(()=>{ const p=classicController.player,a=gameState.players[CURRENT_PLAYER_ID]; window.__recoverySamples.push({errorCells:Math.hypot(p.position_x-(a.position_x ?? a.x),p.position_y-(a.position_y ?? a.y)),lastCorrection:classicController.lastCorrection,correctionCount:classicController.corrections,discardedFrames:gameState.diagnostics?.discarded_stale_frames,snapshotAgeMs:performance.now()-diagnostics.lastReceived,simulationMs:gameState.diagnostics?.simulation_ms,fps:gameRenderer.metrics.fps,pending:classicController.ordered ? classicController.pending.length : inputRequests.size});},25);');
    await key(host,'keyDown','ArrowDown','ArrowDown');await delay(1000);await key(host,'keyUp','ArrowDown','ArrowDown');
    await key(host,'keyDown','ArrowUp','ArrowUp');await delay(1000);await key(host,'keyUp','ArrowUp','ArrowUp');await delay(500);
    const activeEnd=await evaluate(host,'clearInterval(window.__recoverySampler); ({time:performance.now(),tick:gameState.simulation_tick,events:diagnostics.events,corrections:classicController.corrections,samples:window.__recoverySamples})');
    const activeSeconds=(activeEnd.time-activeStart.time)/1000;
    assert.ok(activeEnd.samples.every(s=>s.pending<=64));
    results.push({id:'BROWSER09',scenario:'measured active movement window after rematch',status:'passed',duration_seconds:activeSeconds,observed_tick_arrival_hz:(activeEnd.tick-activeStart.tick)/activeSeconds,received_game_events_per_second:(activeEnd.events-activeStart.events)/activeSeconds,position_corrections_per_minute:(activeEnd.corrections-activeStart.corrections)*60/activeSeconds,samples:activeEnd.samples});
    await key(host,'keyDown','ArrowDown','ArrowDown');await delay(120);
    const expectedBomb=await evaluate(host,`sendBomb();const entry=Object.entries(classicController.provisionalBombs).at(-1);entry?{action:entry[0],x:entry[1].x,y:entry[1].y,sequence:entry[1].sequence}:null`);
    assert.ok(expectedBomb,'ordered action must have a local provisional representation');
    await delay(180);await key(host,'keyUp','ArrowDown','ArrowDown');
    await until(host,`!!gameState.action_results?.[CURRENT_PLAYER_ID]?.['${expectedBomb.action}']`,4000);
    const bombResult=await evaluate(host,`gameState.action_results[CURRENT_PLAYER_ID]['${expectedBomb.action}']`);
    assert.equal(bombResult.success,true);assert.deepEqual([bombResult.bomb.x,bombResult.bomb.y],[expectedBomb.x,expectedBomb.y]);
    assert.equal(bombResult.sequence,expectedBomb.sequence);
    assert.equal(await evaluate(host,`Object.values(gameState.bombs).filter(b=>b.action_id==='${expectedBomb.action}').length`),1);
    results.push({id:'ORDERED_BOMB',scenario:'bomb while moving retains the tile and sequence at keypress, without duplication',status:'passed',expected:expectedBomb,confirmed:bombResult,confirmation_ms:await evaluate(host,'classicController.bombConfirmationMs')});
    const cpuAfter=await command('SystemInfo.getProcessInfo');
    const beforeCpu=new Map(cpuBefore.processInfo.map(p=>[p.id,p.cpuTime]));
    const cpuSeconds=cpuAfter.processInfo.reduce((sum,p)=>sum+Math.max(0,p.cpuTime-(beforeCpu.get(p.id)??p.cpuTime)),0);
    await command('Performance.enable',{},host);
    const performanceMetrics=await command('Performance.getMetrics',{},host);
    results.push({id:'BROWSER_METRICS',duration_ms:performance.now()-runStarted,browser_cpu_seconds_during_latency_tests:cpuSeconds,metrics:Object.fromEntries(performanceMetrics.metrics.filter(m=>['JSHeapUsedSize','JSHeapTotalSize','Frames','TaskDuration'].includes(m.name)).map(m=>[m.name,m.value]))});
    console.log(JSON.stringify({code,results,errors},null,2));
} catch(error){results.push({id:'BROWSER_FAILURE',error:error.message});console.log(JSON.stringify({results,errors},null,2));process.exitCode=1;}
finally{writeFileSync(process.env.BOMBER_BROWSER_REPORT || 'BomberArena-synchronization-browser.json',JSON.stringify({results,errors},null,2));for(const id of contexts)await command('Target.disposeBrowserContext',{browserContextId:id}).catch(()=>{});if(process.env.BOMBER_BROWSER_CLOSE==='1')await command('Browser.close').catch(()=>{});socket.close();}
