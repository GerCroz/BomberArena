export function mountLobbyRooms({join}) {
    const list=document.getElementById('public-rooms-list');
    if(!list) return;
    const status=document.getElementById('rooms-update-status'),search=document.getElementById('room-search'),available=document.getElementById('available-only');
    let rooms=[],request=null,generation=0,timer=null;
    function render() {
        list.replaceChildren();
        const term=search.value.trim().toLowerCase();
        const filtered=rooms.filter(room=>(!available.checked||room.available)&&(!term||(room.name+' '+room.code).toLowerCase().includes(term)));
        for(const room of filtered) {
            const card=document.createElement('article');card.className='rounded-xl border border-slate-700 bg-slate-900/70 p-4 flex flex-col gap-2';
            const name=document.createElement('h3');name.className='font-bold text-cyan-300 break-words';name.textContent=room.name;
            const code=document.createElement('p');code.className='text-xs text-slate-400';code.textContent=room.code+' · Anfitrión: '+room.host;
            const info=document.createElement('p');info.className='text-sm text-slate-200';
            const state={waiting:'Esperando',ready:'Preparada',playing:'Jugando',finished:'Finalizada'}[room.status]||room.status;
            info.textContent=room.players_count+'/'+room.max_players+' jugadores · '+room.connected_count+' conectados · '+(room.players_count>=room.max_players?'Completa':state);
            const button=document.createElement('button');button.type='button';button.disabled=!room.available;button.textContent=room.available?'Unirse':'No disponible';
            button.className='rounded-lg px-4 py-2 font-bold text-sm bg-cyan-400 text-slate-950 disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer';
            button.addEventListener('click',()=>join(room.code));
            card.dataset.roomCode=room.code;card.append(name,code,info,button);list.append(card);
        }
        if(!filtered.length) { const empty=document.createElement('p');empty.className='text-sm text-slate-400';empty.textContent='No hay salas que coincidan. Puedes crear una nueva.';list.append(empty); }
    }
    async function refresh() {
        const seq=++generation;request?.abort();request=new AbortController();
        try {
            const response=await fetch('/api/public-rooms',{signal:AbortSignal.any([request.signal,AbortSignal.timeout(5000)])});
            if(!response.ok) throw new Error('No se pudo actualizar el listado.');
            const data=await response.json();if(seq!==generation)return;
            rooms=data.rooms;render();status.textContent='Actualización automática · '+new Date().toLocaleTimeString();
        } catch(error) {if(seq===generation&&error.name!=='AbortError')status.textContent='Reconectando el listado…';}
    }
    function schedule() {clearTimeout(timer);timer=setTimeout(refresh,120);}
    search.addEventListener('input',render);available.addEventListener('change',render);
    window.Echo?.channel('arena-lobby').listen('.LobbyRoomsChanged',schedule);
    window.Echo?.connector?.pusher?.connection?.bind('connected',refresh);
    document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh();});
    setInterval(refresh,15000);refresh();
}
window.BomberLobby={mount:mountLobbyRooms};
