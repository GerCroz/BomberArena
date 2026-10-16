export function mountRoomSocial(options) {
    const {roomCode,playerId,getRoom,getState,refresh,started,canRequest,characters,csrf,lobbyUrl,now=()=>Date.now()/1000}=options;
    let vote=getRoom().rematch_request,busy=false;
    const panel=document.getElementById('rematch-voting');
    const selectionIds=['room-character','result-character'];
    async function post(path,body={}) {
        const response=await fetch('/api/rooms/'+roomCode+'/'+path,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf},body:JSON.stringify(body),signal:AbortSignal.timeout(10000)});
        const data=await response.json();
        if(!response.ok||!data.success)throw new Error(data.error||Object.values(data.errors||{}).flat()[0]||'No se pudo completar la acción.');
        return data;
    }
    function sync(room) {
        if(room) vote=room.rematch_request;
        for(const id of selectionIds) {
            const select=document.getElementById(id);if(!select)continue;
            const me=getRoom().players?.find(p=>p.player_id===playerId);
            const rules=getRoom().rules_snapshot||{};
            select.disabled=rules.character_selection_enabled===false||rules.character_changes_enabled===false||getRoom().status==='playing';
            if(me)select.value=me.character_id||me.color;
        }
        render();
    }
    function setVote(next) {
        if(next&&next.round_id!==getState()?.round_id)return;
        vote=next;getRoom().rematch_request=next;render();
    }
    function render() {
        if(!panel)return;
        const state=getState();
        if(vote?.round_id!==state?.round_id)vote=null;
        panel.replaceChildren();
        const message=document.createElement('p');message.className='text-sm text-slate-200';
        const pending=vote?.status==='pending'&&vote.expires_at>now();
        const button=document.getElementById('rematch-button');
        if(button){button.classList.toggle('hidden',!canRequest()||pending);button.disabled=busy;}
        const rules=state?.rules||{};
        if(rules.allow_rematch===false||(!state?.winner_id&&rules.rematch_on_draw===false)||state?.finish_reason==='ADMIN_CANCELLED'||(rules.max_consecutive_rematches>0&&getRoom().rematch_count>=rules.max_consecutive_rematches)) {
            message.textContent='No hay revancha disponible según las reglas de esta sala.';panel.append(message);return;
        }
        if(pending) {
            const name=id=>getRoom().players?.find(p=>p.player_id===id)?.nickname||'Participante';
            message.textContent=name(vote.requested_by)+' solicita revancha · '+vote.accepted.length+'/'+vote.required.length+' confirmados · '+Math.max(0,Math.ceil(vote.expires_at-now()))+' s';
            const names=document.createElement('p');names.className='text-xs text-slate-400';names.textContent='Aceptaron: '+vote.accepted.map(name).join(', ');
            panel.append(message,names);
            if(!vote.accepted.includes(playerId))for(const [accept,label]of [[true,'Aceptar'],[false,'Rechazar']]) {
                const action=document.createElement('button');action.type='button';action.textContent=label;action.disabled=busy;action.dataset.rematchResponse=String(accept);
                action.className='px-4 py-2 rounded-lg bg-slate-700 text-white text-sm font-bold m-1 cursor-pointer disabled:opacity-40';
                action.addEventListener('click',()=>respond(accept));panel.append(action);
            }
            return;
        }
        if(vote&&['rejected','cancelled'].includes(vote.status)) {
            message.textContent=vote.status==='rejected'?'La revancha fue rechazada. Pueden volver al lobby o formar otra sala.':(vote.reason||'La solicitud venció o fue cancelada.');
            const link=document.createElement('a');link.href=lobbyUrl;link.textContent='Formar una nueva sala';link.className='text-cyan-300 underline text-sm';link.addEventListener('click',event=>{event.preventDefault();leave();});
            panel.append(message,link);return;
        }
        message.textContent=state?.winner_id===playerId?'Esperando solicitud de revancha':state?.winner_id?'Los jugadores derrotados pueden solicitar revancha.':'Empate: cualquiera puede proponer otra partida.';
        panel.append(message);
    }
    async function requestRematch() {
        if(busy||!canRequest())return;
        busy=true;render();
        try{const result=await post('rematch',{round_id:getState().round_id});setVote(result.rematch);}catch(error){alert(error.message);}finally{busy=false;render();}
    }
    async function respond(accept) {
        if(busy||!vote)return;
        busy=true;render();
        try{
            const result=await post('rematch/respond',{request_id:vote.id,round_id:vote.round_id,accept});
            if(result.state)started(result);else setVote(result.rematch);
        }catch(error){alert(error.message);refresh();}finally{busy=false;render();}
    }
    async function leave() {
        try{await post('leave');location.href=lobbyUrl;}catch(error){alert(error.message);}
    }
    for(const id of selectionIds) {
        const select=document.getElementById(id);if(!select)continue;
        select.replaceChildren(...Object.entries(characters).map(([id,meta])=>{const option=document.createElement('option');option.value=id;option.textContent=meta.name;return option;}));
        select.addEventListener('change',async()=>{
            try{await post('character',{character_id:select.value});try{localStorage.setItem('bomber_character',select.value);}catch{}await refresh();}catch(error){alert(error.message);sync(getRoom());}
        });
    }
    window.Echo?.private('arena-social.'+roomCode).listen('.RoomSocialEvent',event=>{
        if(event.type==='room.player_left'&&event.data.reason==='presence_timeout'&&event.data.player_ids?.includes(playerId)){alert('Tu plaza se liberó por inactividad. Puedes volver a ingresar.');location.href=lobbyUrl;return;}
        if(event.type.startsWith('rematch.')&&event.data.rematch)setVote(event.data.rematch);
        if(['player.character.updated','room.player_left','rematch.started'].includes(event.type))refresh();
        if(event.type==='room.closed'){alert('La sala se cerró por inactividad.');location.href=lobbyUrl;}
    });
    async function presence() {
        if(document.hidden)return;
        try{const data=await post('presence');setVote(data.rematch);}catch{}
    }
    setInterval(presence,10000);setInterval(render,1000);
    document.addEventListener('visibilitychange',()=>{if(!document.hidden){presence();refresh();}});
    sync(getRoom());presence();
    return {sync,requestRematch,respond,leave};
}
window.BomberRoomSocial={mount:mountRoomSocial};
