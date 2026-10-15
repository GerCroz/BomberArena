# Recuperación de jugabilidad de Bomber Arena

Fecha: 2026-10-15. Rama de trabajo: `recovery/classic-gameplay-20261014`.

El movimiento clásico continuo está implementado y probado con cuatro contextos aislados de Chrome conectados al servidor Laravel y a Reverb reales. La validación de esta máquina no certifica dispositivos móviles físicos, redes WAN ni carga de muchas salas con transporte real.

## Respaldo y diagnóstico previo

Antes de modificar se preservó el estado completo, incluidos los cambios que aún no estaban en Git, en `backup/gameplay-before-recovery-20261014`, commit `ac3a7af`. No se revirtió el proyecto.

Se compararon `e499d68` (cola, rendimiento y tablero), `64ac44f` (colisiones y temporizadores), `f15b10b` (sincronización de rondas), `5e1a318` (revancha/resultados) y el estado preservado. El último commit de respaldo reúne varias correcciones intermedias; no se atribuye cada regresión a un commit independiente inexistente.

| Prioridad | Causa identificada | Efecto | Corrección |
|---|---|---|---|
| Crítica | Un paso por HTTP y repetición condicionada por respuesta/temporizador | Retardo, pérdida de control y bloqueo al colocar bombas | Estado de entrada actual por WebSocket; predicción local continua |
| Crítica | Intenciones walk con pasos discretos y stop retrasado | Pasos posteriores a soltar, cola atrasada | Una intención vigente, secuencias, expiración de 500 ms y máximo dos pasos de recuperación |
| Crítica | Reserva obligatoria del origen de cada jugador vivo | Bloqueos al cruzarse, congestión | Jugadores sin colisión física por defecto; opción sólida explícita |
| Alta | Predicción de una casilla y render interpolado del jugador propio | Sensación de retraso y correcciones | Posición predicha separada; dibujo local directo |
| Alta | Publicar únicamente si cambió la versión en la llamada actual | ACK final de parada podía no llegar | Versión publicada separada de versión actual; publicación diferida del estado pendiente |
| Alta | Refrescar entrada reiniciaba su ventana de integración | Pérdida de pasos al mantener una dirección | Conservar inicio de intención mientras sea la misma y no haya vencido |
| Alta | SQLite usado simultáneamente por HTTP, Reverb y simulación | Contención y entradas perdidas con cuatro navegadores | WAL y busy_timeout de 5000 ms; mailbox pequeño y locks separados |
| Alta | Falta de lease global del proceso de simulación | Posibles workers duplicados | Lease renovable y locks por sala |
| Media | Revancha restringida a perdedores y sin empate válido | Ganador/empate sin reinicio práctico | Cualquier participante puede pedir revancha en el modelo clásico |
| Media | RTT de telemetría generado con rand | Métricas engañosas | RTT medido en cliente; dato ausente permanece null |

Se reprodujeron fallos en pruebas automatizadas y con navegador real. Durante la validación, una prueba usaba incorrectamente Echo.connect(), que sustituye el conector y pierde sus listeners; se corrigió el arnés para reconectar el Pusher existente. No se atribuye ese error del arnés al motor.

## Arquitectura y contrato vigente

- Autoridad viva: Cache bajo lock por sala en RoomService; DB conserva configuración y estado inicial/final. No hay un servidor Node adicional.
- Renderizador existente: Canvas 2D en gameRenderer.js. No se sustituyó ni se agregaron funcionalidades gráficas.
- Movimiento: modelo híbrido. position_x/position_y son coordenadas físicas continuas, x/y indican la casilla redondeada para bombas y reglas del tablero.
- Hitbox: semiancho 0,28 casillas; pasos internos de hasta 0,025 casillas evitan atravesar sólidos. Servidor y navegador usan la misma fórmula.
- Velocidad base: 4 casillas/s. Mejora de velocidad: +15% por nivel adicional. Cuatro direcciones; última tecla pulsada tiene prioridad; al soltarla se restaura otra que siga presionada.
- Giro: asistencia de hasta 0,25 casillas. Primero se centra el eje perpendicular a velocidad limitada; no hay movimiento diagonal ni teleportación por asistencia.
- Jugadores vivos pueden compartir posición y cruzarse. Muertos no bloquean. Paredes, bloques, bordes y bombas conservan colisión.
- Posición autoritativa: estado de Cache. Posición confirmada: gameState del cliente. Predicha: ClassicController.player. Visual: displayPos del renderer. Modelo gráfico: proyección de esas coordenadas a píxeles. Las animaciones no cambian gameState.
- Predicción local respeta sólidos conocidos; eliminación, explosiones, mejoras y resultado permanecen en el servidor. Cambios grandes/inválidos se ajustan inmediatamente; diferencias pequeñas se amortiguan tras ACK válido.
- Remotos usan interpolación ortogonal del renderer existente. Saltos discontinuos/diagonales se ajustan al snapshot para evitar cortar esquinas. No hay extrapolación remota ilimitada ni buffer histórico completo.
- Teclado usa estado controlado, no repetición del sistema. blur, visibilitychange oculto, desconexión y cancelación de puntero limpian entradas. Listeners de teclado y controles tienen guardas de inicialización.

## Transporte y frecuencias

Entradas firmadas por HMAC se envían como extensión de aplicación del ping Pusher; Reverb procesa el payload con MessageReceived y escribe únicamente el último estado de entrada. No se retransmite el token a otros clientes ni se ejecuta la simulación dentro de Reverb. Esto requiere el listener de este proyecto y no representa una API estándar de entradas de Laravel Echo.

Se envían cambios de intención inmediatamente y renovaciones cada 150 ms mientras se mantiene una dirección (aproximadamente 6,7/s). No se acumula una lista de desplazamientos. El fallback HTTP tiene como máximo dos solicitudes y cancela la antigua ante una entrada prioritaria. Cada entrada identifica sala, ronda, jugador y secuencia. Se rechazan duplicados, secuencias anteriores y movimientos vencidos; una parada nueva puede aceptarse aunque su fecha sea antigua.

El worker consulta a 30 Hz y cada sala integra a 20, 25 o 30 Hz según su snapshot. Publicación por defecto: hasta 10 snapshots/s cuando hay cambios; no se publican estados estáticos sin necesidad. Eventos semánticos de bombas/resultados son adicionales. Render: requestAnimationFrame. HTTP de liveness/resincronización: aproximadamente una vez/s, sin esperar su respuesta para mover localmente.

El tick de simulación ejecutado se expone como simulation_tick; tick conserva el reloj de compatibilidad de 20 Hz. Se descartan intervalos excesivos después de pausas en lugar de simular toda la interrupción. Entradas recientes pueden integrar como máximo dos pasos al recuperarse.

El lease global impide iniciar otro daemon normal mientras existe uno activo; --once sigue siendo una operación de diagnóstico y los locks por sala serializan todas las mutaciones.

Comprobación operativa ejecutada: otra invocación de arena:tick respondió «Ya existe un proceso de simulacion activo.» y terminó sin arrancar otro daemon. La inspección de procesos confirmó un worker y un proceso Reverb.

## Bombas, partida y administración

Las bombas se colocan en la casilla confirmada del servidor. Los jugadores que ya intersectan su área al colocarla reciben permiso de salida; se revoca al abandonar el área y no se puede regresar. La predicción revoca también ese permiso localmente aunque el snapshot llegue tarde.

Fusibles independientes, propagación en cruz, paredes, destrucción de bloques, cadena y daño físico se ejecutan en el servidor. Una bomba no detiene la entrada de los demás. Se conserva la resolución agrupada de explosiones vencidas antes de decidir empate/victoria.

Por defecto: 2–4 jugadores, 180 segundos, último superviviente gana, cero vivos empata y tiempo agotado con varios vivos empata. Se conservan los modos administrativos explícitos most_bombs y most_kills. Las mejoras o destrucciones sin eliminación no terminan la partida. La revancha genera otra ronda y reinicia predicción, efectos y secuencias confirmadas sin aceptar mensajes de la anterior.

En el panel existente se separaron parámetros técnicos: velocidad 2–6, simulación 20/25/30 Hz, publicación 5–simulación Hz, asistencia 0–0,25, predicción, interpolación y límite visual 0,25–1 casillas. Se reutilizan controles de fusible, alcance, duración y reconexión. HTTP rechaza valores incompatibles; el servicio normaliza entradas internas. Los cambios afectan snapshots de nuevas partidas.

La normalización de configuraciones heredadas adopta sin colisión y empate por tiempo para las partidas nuevas; no cambia salas activas. Muertos sólidos queda deshabilitado.

F3 muestra diagnóstico únicamente en local o para administrador: coordenadas, hitboxes, tick, versión, secuencia/ACK, intención, pendientes HTTP, conexión, RTT de transporte, estados descartados y ajustes. No incluye tokens ni claves.

## Validación ejecutada

| ID | Escenario | Esperado | Observado | Estado | Causa raíz | Corrección | Evidencia |
|---|---|---|---|---|---|---|---|
| C01 | Mantener 10 s y soltar, 30/60/120 FPS | Velocidad independiente del render y parada | Sin replay | Aprobado | Repetición/HTTP condicionaban pasos | Controlador por delta limitado | ClassicMovement.test.js |
| C02 | Teclas opuestas/cambio rápido | Última prioridad y restauración al soltar | Coincide | Aprobado | Entrada acumulativa | Map de teclas activas | Node + BROWSER08 |
| C03 | Blur, visibilidad, cancelación, desconexión | Limpiar intención | Sin cola | Aprobado | Entrada retenida | clear y handlers de ciclo de vida | Node; blur/puntero/reconexión en navegador |
| C04 | Simulación 20/25/30 Hz | 4 casillas por segundo | Igual velocidad | Aprobado | Dependencia de cadencia | Integración por intervalo | ClassicGameplayTest |
| J01-C | Cruce frontal | Compartir y atravesar zona | Ambos atraviesan | Aprobado | Reserva de ocupación | Colisión entre jugadores false | ClassicGameplayTest J01 |
| J02-C | Cruce perpendicular | Compartir centro | Ambos en centro | Aprobado | Destino disputado | Modo clásico | ClassicGameplayTest J02 |
| J03-C | Intercambio | Permitido | Intercambio correcto | Aprobado | Anti-swap obligatorio | Modo clásico | ClassicGameplayTest J03 |
| J04-C | Cuatro convergen | Sin bloquearse | Cuatro en centro | Aprobado | Congestión artificial | Modo clásico | ClassicGameplayTest J04 |
| J10-C | Seguir al que sale | Permitido | Ambos avanzan | Aprobado | Origen reservado | Modo clásico | ClassicGameplayTest J10 |
| J14-C | Dos cruces simultáneos | Ambos destinos compartidos | Coincide | Aprobado | Conflictos de destino | Modo clásico | ClassicGameplayTest J14 |
| J15-C | Entrar en zona viva quieta | Permitido por defecto | Permitido | Aprobado | Regla anterior obligatoria | Default false | ClassicGameplayTest J15 |
| C05 | Muros, límites y bombas | Nunca atravesar sólidos | Hitbox válida | Aprobado | Discreto frente a continuo | Subpasos y geometría común | PHP + Node |
| C06 | Bomba propia, salida y reentrada | Salir una vez, no regresar | Coincide | Aprobado | Permiso basado solo en celda | Revocación por hitbox | PHP + Node |
| C07 | Entrada tardía después de stop | No reiniciar movimiento | Ignorada | Aprobado | Falta de orden/expiración | Secuencia y antigüedad | API + mailbox |
| C08 | Stop sin cambios posteriores | ACK llega en siguiente publicación | Publicado | Aprobado | Publicación dependía de cambio inmediato | last_published_version | ClassicGameplayTest |
| C09 | Renovación de dirección | No reiniciar integración ni liveness | Movimiento y heartbeat vigentes | Aprobado | received_at reutilizado | Separar recepción de inicio | ClassicGameplayTest |
| C10 | Red 30/100/200/300 ms y corte | No recuperar ráfaga | Integración acotada | Aprobado | Backlog de entradas | Una intención, expiración, 2 pasos | PHP + Node |
| C11 | Cambiar reglas activas | Sala actual inmutable | Snapshot igual | Aprobado | Configuración mutable potencial | Snapshot inicial | ClassicGameplayTest |
| C12 | Parámetros protegidos/incompatibles | Solo admin, rechazo 30>20 Hz | 403/422 y persistencia válida | Aprobado | Restricciones técnicas ausentes | Validación administrativa | ClassicGameplayTest |
| BROWSER01 | Mantener/soltar con Reverb | Respuesta inmediata y convergencia | Cumple | Aprobado | Esperar HTTP | Predicción + WS | recovery-browser.json |
| BROWSER02 | Blur real de ventana | Intención null | null | Aprobado | Teclas retenidas | Limpieza | recovery-browser.json |
| BROWSER03 | Cuatro clientes aislados | Misma ronda, cuatro jugadores | Coincide | Aprobado | Estados divergentes | Ronda/versionado | recovery-browser.json |
| BROWSER04 | Render Canvas | Medir FPS reales | 60,00 FPS estables | Aprobado | Sin medición previa | Contador del renderer | recovery-browser.json |
| BROWSER05 | Cuatro entradas simultáneas | Todos avanzan en servidor | Los cuatro avanzan | Aprobado | Contención/serialización HTTP | Mailbox + WAL | recovery-browser.json |
| LATENCY30 | Retardo de entrada 30 ms + jitter | Soltar para local y converger | Cumple | Aprobado | Cola | Estado actual | recovery-browser.json |
| LATENCY100 | Retardo de entrada 100 ms + jitter | Igual | Cumple | Aprobado | Cola | Estado actual | recovery-browser.json |
| LATENCY200 | Retardo de entrada 200 ms + jitter | Igual | Cumple | Aprobado | Cola | Estado actual | recovery-browser.json |
| LATENCY300 | Retardo de entrada 300 ms + jitter | Igual | Cumple | Aprobado | Cola | Estado actual | recovery-browser.json |
| BROWSER06 | Corte con tecla presionada y reconexión | Quieto y confirmado, sin replay | X visual=autoritativa=3,20; ACK=64 | Aprobado | Entradas retenidas | Limpiar y resincronizar | recovery-browser.json |
| BROWSER07 | Tres bombas, resultado y revancha | Servidor elimina y nueva ronda acepta movimiento | Cumple | Aprobado | Revancha restringida | Reinicio clásico | recovery-browser.json |
| BROWSER08 | Opuestas y pointercancel | Dirección actual correcta y parada | Cumple | Aprobado | Entradas inconsistentes | Estado compartido | recovery-browser.json |
| BROWSER09 | Ventana activa tras revancha | Medir eventos, ajustes, error y FPS | Muestras guardadas, pendientes ≤2 | Aprobado | Faltaban medidas de actividad separada | Muestreo observacional de 25 ms | recovery-browser.json |
| REAL-WAN | Cuatro dispositivos en WAN | Control fluido con red real | Sin datos | No ejecutado | Entorno no disponible | Pendiente validación externa | Sin evidencia |
| REAL-MOBILE | Móvil físico/touch prolongado | Sin entrada retenida por suspensión | Sin datos | No ejecutado | Hardware no disponible | Pendiente validación física | Puntero sintético no equivale a dispositivo |

Los cruces nuevos se ejecutan también con orden de iteración inverso. Las filas Jxx-C reemplazan las expectativas de ocupación obligatoria en el modo predeterminado.

Suite completa final: **147 pruebas PHP aprobadas**, **87 226 assertions**, 7,219 s. JavaScript: **63/63**, 146,105 ms. Build Vite y Pint aprobados. Advertencia: fontaine opcional ausente; no se añadieron dependencias. La última ejecución real de navegador terminó con código 0 y cero excepciones de Runtime.

## Métricas y límites de interpretación

Evidencia cruda: BomberArena-recovery-browser.json, BomberArena-recovery-server.json y BomberArena-recovery-metrics.json.

| Medición | Valor observado | Alcance |
|---|---|---|
| FPS | 60,00 estable; 42,71–60,22 en ventana activa | Cuatro contextos Chrome headless; no confundir con 20 Hz del servidor |
| Respuesta visual inicial | 11,60 ms | Primera entrada; muestra, no percentil de una población |
| RTT WS | 3,60 ms estable; 18,20 ms al primer input | Ping/pong local; excluye aplicación en tick |
| RTT HTTP | 910,80 ms estable; 1084,00 ms tras reconectar | Liveness/resync; movimiento local no espera HTTP |
| Tick real, motor dentro de RoomService | p95 0,5609 ms | Ventana activa; excluye locks, DB y broadcast; no es tiempo total de mutación |
| Motor puro 2/3/4 jugadores, p95 | 0,0199 / 0,0186 / 0,0241 ms | Una sala, escenario virtual 30 ms; otras demoras están en JSON |
| Motor puro 10 salas × 4, p95 | 0,0389 ms por llamada/sala | Sin transporte ni persistencia, no representa costo de diez salas reales |
| Motor puro, memoria máxima proceso | 56 623 104 bytes | Incluye proceso de pruebas/framework, no memoria incremental por sala |
| Worker, CPU | 3,953125 s CPU en 43,849 s | PID 6328; 9,02% de un núcleo lógico durante prueba completa |
| Worker, memoria | 59387904 bytes working set; 44208128 privada | Muestra al terminar |
| Chrome, CPU | 35,429149 s agregados | Procesos Chrome durante latencia/bombas/revancha/ventana activa; no porcentaje del servidor |
| JS heap host | 2322320 bytes usados | Muestra CDP de un contexto, no memoria total de cuatro navegadores |
| Eventos de juego recibidos/s | 6,30 | Ventana activa de 2,542 s; un cliente, excluye ping/pong y HTTP |
| Desfase espacial predicho/confirmado | p95 0,5514; máximo 0,8102 casillas | Ventana activa; posición final se reconcilia; predicción no decide daño |
| Pasos acumulados | 0 en los 16 escenarios virtuales | 2, 3, 4 jugadores y 10 salas; corte temporal incluido |
| HTTP pendientes | 0 en muestras reales | Fallback limitado a 2; mailbox WS conserva una sola intención |
| Ajustes inmediatos | 0 hasta reconexión; 0,00 por minuto normalizado en ventana activa | Ventana de 2,542 s; no cuenta todas las amortiguaciones suaves |
| Estados obsoletos descartados | 0 hasta reconexión | Pruebas también inyectan estados anteriores y verifican rechazo |
| Rechazos de colisión | 0 en muestras reales de pasillo libre | No cuenta intentos continuos contra sólidos; no se fabrica un contador completo |
| Última recepción | p95 309,00 ms en ventana activa | Edad de snapshot, no RTT ni demora visual exacta |

La inyección de 30/100/200/300 ms retrasa entradas salientes con jitter determinista de 0–24 ms. **No es RTT WAN simétrico ni pérdida aleatoria del enlace completo**. Además se desconecta realmente Pusher y se reconecta su instancia existente.

Se midieron eventos recibidos/s y correcciones inmediatas/minuto en una ventana activa separada del countdown y reposo. No se obtuvo una distribución temporal completa de demora autoritativa→visual ni de correcciones suaves/minuto. La diferencia espacial no permite deducir por sí sola una latencia exacta. Los límites configurados de publicación no sustituyen tasas medidas.

## Matrices heredadas y casos faltantes

No se entregaron los enunciados originales J01–J15, B01–B15, L01–L12, V01–V14 y E01–E12. Los 68 casos siguientes siguen siendo una reconstrucción de la auditoría previa, no una certificación de documentos externos ausentes.

Todos se ejecutaron nuevamente en las suites completas. J y L ejercitan el camino discreto/preset sólido de compatibilidad; V usa Canvas simulado y el cliente heredado salvo donde se indica. Sus aprobaciones certifican esas expectativas concretas. **No describen la jugabilidad clásica predeterminada**; para cruces clásicos y control continuo se aplican Cxx, Jxx-C y BROWSERxx anteriores. B/E ejercitan el núcleo compartido. Most_bombs/most_kills son opciones explícitas, no el default actual.

| ID | Escenario heredado | Resultado esperado en ese caso | Resultado observado | Estado | Causa raíz original | Corrección o conservación | Evidencia de regresión |
|---|---|---|---|---|---|---|---|
| J01 | Frontal, destino común | Ambos rechazados | Ambos permanecen en origen | Aprobado | Recepción secuencial favorecía al primero | Resolver destinos contra ocupación inicial | PHP movementCases; orden inverso ejecutado |
| J02 | Perpendicular, destino común | Ambos rechazados | Sin ocupación doble | Aprobado | Ausencia de resolución conjunta | Resolver el tick completo | PHP movementCases; orden inverso ejecutado |
| J03 | Intercambio de casillas | Ambos rechazados | No intercambian | Aprobado | Riesgo de resolver movimientos por separado | Reservar todos los orígenes vivos | PHP movementCases; orden inverso ejecutado |
| J04 | Cuatro entradas al centro | Cuatro rechazados | Los cuatro permanecen separados | Aprobado | Conflictos dependientes de recepción | Rechazar todos los contendientes | PHP movementCases; orden inverso ejecutado |
| J05 | Movimiento hacia muro | Rechazado | Rechazado | Aprobado | Sin defecto detectado en esta regla | Conservar colisión sólida | PHP movementCases; orden inverso ejecutado |
| J06 | Movimiento hacia bloque | Rechazado | Rechazado | Aprobado | Sin defecto detectado | Conservar bloque sólido | PHP movementCases; orden inverso ejecutado |
| J07 | Salida de límites | Rechazado | Rechazado | Aprobado | Sin defecto detectado | Conservar límites | PHP movementCases; orden inverso ejecutado |
| J08 | Movimiento libre | Aceptado | Un paso ortogonal | Aprobado | Sin defecto detectado | Conservar casillas discretas | PHP movementCases; orden inverso ejecutado |
| J09 | Entrar en casilla de muerto | Aceptado | Aceptado aun con regla heredada de bloqueo | Aprobado | dead_players_block_movement podía activar ocupación fantasma | Excluir muertos siempre | PHP movementCases; orden inverso ejecutado |
| J10 | Seguir a jugador que sale en el mismo tick | Seguidor rechazado; líder avanza | Se conserva el origen reservado | Aprobado | Desocupación secuencial permitía atravesamientos temporales | Reserva conservadora por tick | PHP movementCases; orden inverso ejecutado |
| J11 | Cuatro movimientos independientes | Cuatro aceptados | Cuatro destinos distintos | Aprobado | Sin defecto detectado | Resolución ordenada por ID | PHP movementCases; orden inverso ejecutado |
| J12 | Dirección inválida | Rechazada | Rechazada | Aprobado | Sin defecto detectado | Validación de dirección | PHP movementCases; orden inverso ejecutado |
| J13 | Jugador eliminado intenta moverse | Rechazado | Rechazado | Aprobado | Sin defecto detectado | Mantener alive como filtro lógico | PHP movementCases; orden inverso ejecutado |
| J14 | Dos destinos disputados simultáneamente | Cuatro rechazados | Nadie gana por orden de recepción | Aprobado | Resolución secuencial | Agrupar destinos | PHP movementCases; orden inverso ejecutado |
| J15 | Preset sólido explícito | Ocupación viva bloquea | Rechazado con player_collisions=true | Aprobado | Antes se imponía en todos los modos | Ahora solo preset explícito; default clásico permite cruce | PHP movementCases; orden inverso |
| B01 | Límite simultáneo de bombas | Segunda bomba rechazada | Rechazada | Aprobado | Sin defecto detectado | Conservar límite | PHP bombCases; reloj congelado |
| B02 | Bomba duplicada en casilla, capacidad disponible | Rechazada | Rechazada | Aprobado | Sin defecto detectado | Conservar unicidad | PHP bombCases; reloj congelado |
| B03 | Salir de la propia bomba | Permitido | Permitido | Aprobado | Sin defecto detectado | Conservar regla de salida | PHP bombCases; reloj congelado |
| B04 | Reingresar a bomba activa | Bloqueado | Bloqueado | Aprobado | Sin defecto detectado | Conservar bomba sólida | PHP bombCases; reloj congelado |
| B05 | Antes del fusible, 1.999 s | No detona | No detona | Aprobado | Sin defecto detectado | Reloj controlado | PHP bombCases; reloj congelado |
| B06 | Al cumplir 2 s | Detona | Una detonación | Aprobado | Dependencia anterior del navegador para avanzar | Proceso arena:tick; reloj servidor | PHP bombCases; reloj congelado |
| B07 | Detonación repetida | Sin segundo efecto | Rechazada | Aprobado | Sin defecto detectado | Conservar eliminación de entidad | PHP bombCases; reloj congelado |
| B08 | Reacción en cadena habilitada | Dos bombas detonadas | Dos detonadas | Aprobado | Crédito de daño atribuía toda la cadena a la raíz | Propietarios por casilla de fuego; contador activo | PHP bombCases; reloj congelado |
| B09 | Cadena deshabilitada | Solo raíz detonada | Una detonada | Aprobado | Sin defecto detectado | Conservar regla | PHP bombCases; reloj congelado |
| B10 | Muro detiene fuego | Sin fuego detrás; muro intacto | Correcto | Aprobado | Sin defecto detectado | Conservar propagación | PHP bombCases; reloj congelado |
| B11 | Bloque detiene fuego y se destruye | Sin fuego detrás; bloque vacío | Correcto | Aprobado | Sin defecto detectado | Conservar destrucción | PHP bombCases; reloj congelado |
| B12 | Autodaño habilitado | Propietario eliminado | Eliminado | Aprobado | Sin defecto detectado | Conservar filtro | PHP bombCases; reloj congelado |
| B13 | Autodaño deshabilitado | Propietario sobrevive | Sobrevive | Aprobado | Filtro de cadena usaba dueño raíz | Aplicar dueño del daño real | PHP bombCases; reloj congelado |
| B14 | Daño a otros deshabilitado | Rival sobrevive | Sobrevive | Aprobado | Sin defecto detectado | Conservar regla | PHP bombCases; reloj congelado |
| B15 | Daño a otros habilitado | Rival muerto y baja acreditada | Muerto; una baja | Aprobado | Contadores activos no se actualizaban | Actualizar bombas activas y propietario de daño | PHP bombCases; reloj congelado |
| L01 | 2 jugadores, demora virtual 30 ms y jitter | Sin posiciones duplicadas; cola expira tras corte | 40 iteraciones comprobadas; sin replay tras salto a 20 s | Aprobado | Lotes podían acelerar movimiento | Cooldown, ticks, caducidad | PHP networkCases; simulación del motor, sin sockets |
| L02 | 3 jugadores, demora virtual 30 ms y jitter | Sin posiciones duplicadas; cola expira tras corte | 40 iteraciones comprobadas; sin replay tras salto a 20 s | Aprobado | Lotes podían acelerar movimiento | Cooldown, ticks, caducidad | PHP networkCases; simulación del motor, sin sockets |
| L03 | 4 jugadores, demora virtual 30 ms y jitter | Sin posiciones duplicadas; cola expira tras corte | 40 iteraciones comprobadas; sin replay tras salto a 20 s | Aprobado | Lotes podían acelerar movimiento | Cooldown, ticks, caducidad | PHP networkCases; simulación del motor, sin sockets |
| L04 | 2 salas × 4, demora virtual 30 ms y jitter | Sin posiciones duplicadas; cola expira tras corte | 40 iteraciones comprobadas; sin replay tras salto a 20 s | Aprobado | Lotes podían acelerar movimiento | Cooldown, ticks, caducidad | PHP networkCases; simulación del motor, sin sockets |
| L05 | 2 jugadores, demora virtual 150 ms y jitter | Sin posiciones duplicadas; cola expira tras corte | 40 iteraciones comprobadas; sin replay tras salto a 20 s | Aprobado | Lotes podían acelerar movimiento | Cooldown, ticks, caducidad | PHP networkCases; simulación del motor, sin sockets |
| L06 | 3 jugadores, demora virtual 150 ms y jitter | Sin posiciones duplicadas; cola expira tras corte | 40 iteraciones comprobadas; sin replay tras salto a 20 s | Aprobado | Lotes podían acelerar movimiento | Cooldown, ticks, caducidad | PHP networkCases; simulación del motor, sin sockets |
| L07 | 4 jugadores, demora virtual 150 ms y jitter | Sin posiciones duplicadas; cola expira tras corte | 40 iteraciones comprobadas; sin replay tras salto a 20 s | Aprobado | Lotes podían acelerar movimiento | Cooldown, ticks, caducidad | PHP networkCases; simulación del motor, sin sockets |
| L08 | 2 salas × 4, demora virtual 150 ms y jitter | Sin posiciones duplicadas; cola expira tras corte | 40 iteraciones comprobadas; sin replay tras salto a 20 s | Aprobado | Lotes podían acelerar movimiento | Cooldown, ticks, caducidad | PHP networkCases; simulación del motor, sin sockets |
| L09 | 2 jugadores, demora virtual 300 ms y jitter | Sin posiciones duplicadas; cola expira tras corte | 40 iteraciones comprobadas; sin replay tras salto a 20 s | Aprobado | Lotes podían acelerar movimiento | Cooldown, ticks, caducidad | PHP networkCases; simulación del motor, sin sockets |
| L10 | 3 jugadores, demora virtual 300 ms y jitter | Sin posiciones duplicadas; cola expira tras corte | 40 iteraciones comprobadas; sin replay tras salto a 20 s | Aprobado | Lotes podían acelerar movimiento | Cooldown, ticks, caducidad | PHP networkCases; simulación del motor, sin sockets |
| L11 | 4 jugadores, demora virtual 300 ms y jitter | Sin posiciones duplicadas; cola expira tras corte | 40 iteraciones comprobadas; sin replay tras salto a 20 s | Aprobado | Lotes podían acelerar movimiento | Cooldown, ticks, caducidad | PHP networkCases; simulación del motor, sin sockets |
| L12 | 2 salas × 4, demora virtual 300 ms y jitter | Sin posiciones duplicadas; cola expira tras corte | 40 iteraciones comprobadas; sin replay tras salto a 20 s | Aprobado | Lotes podían acelerar movimiento | Cooldown, ticks, caducidad | PHP networkCases; simulación del motor, sin sockets |
| V01 | Proyección horizontal | (1,1) → (75,75) px | Correcta | Aprobado | Sin defecto detectado | Conservar transformación | Node V01; Canvas simulado |
| V02 | Proyección vertical | (1,1) → (475,75) px | Correcta | Aprobado | Sin defecto detectado | Conservar rotación | Node V02; Canvas simulado |
| V03 | Direcciones rotadas | Transformación y su inversa coinciden | Cuatro direcciones correctas | Aprobado | Sin defecto detectado | Conservar inversa | Node V03; Canvas simulado |
| V04 | Paso adyacente interpolado | A 25 ms: x=100, y=75 px | Correcto | Aprobado | Sin defecto detectado en paso simple | Interpolar solo segmentos ortogonales seguros | Node V04; Canvas simulado |
| V05 | Corrección diagonal | Ajustar al destino confirmado sin cortar esquina | Correcto | Aprobado | Lerp podía atravesar sólidos | Reconciliar salto diagonal | Node V05; Canvas simulado |
| V06 | Salto de varias casillas | Ajuste al destino confirmado | Correcto | Aprobado | Lerp podía cruzar casillas intermedias | Reconciliar snapshots discontinuos | Node V06; Canvas simulado |
| V07 | Conflicto de núcleos visuales | Separación ≥35 px | Correcta | Aprobado | Interpolación independiente sin restricción espacial | Validar candidatos; reconciliar conjunto | Node V07; Canvas simulado |
| V08 | Indicador de muerto | Visible, sin impedir interpolación viva | Correcto | Aprobado | Reglas de muerto podían bloquear lógica | Separar representación de ocupación | Node V08; Canvas simulado |
| V09 | Entidad ausente | Eliminar displayPos residual | Caché vacío | Aprobado | Caché visual retenía IDs ausentes | Purgar posiciones | Node V09; Canvas simulado |
| V10 | Renderizado inmutable | Estado lógico sin cambios | JSON idéntico | Aprobado | Predicción del cliente sí modificaba lógica | Animación opera sobre displayPos | Node V10; Canvas simulado |
| V11 | Tablero distinto con igual cantidad de bloques | Reconstruir caché | Reconstruido | Aprobado | Firma usaba solo cantidad de bloques | Firma del contenido del tablero | Node V11; Canvas simulado |
| V12 | Snapshot obsoleto | No reemplaza versión 90 | Conserva versión 90 | Aprobado | Faltaba comprobar tick en versiones iguales | Versionado y tick monotónicos | Node V12; Canvas simulado |
| V13 | Movimiento anterior a snapshot | No cambia posición confirmada | Conserva x=11 | Aprobado | Solo se comparaba versión del último movimiento | Comparar también versión del snapshot | Node V13; Canvas simulado |
| V14 | Entrada local | Enviar secuencia sin modificar x lógico | x=1; comando con sequence=1 | Aprobado | Predicción editaba posición y consumía powerups | Esperar confirmación; reconciliar con solicitudes pendientes | Node V14; Canvas simulado |
| E01 | Cuatro vivos | Continúa; sin ganador | Continúa; sin ganador | Aprobado | No se detectó defecto en este criterio | Conservar evaluador; impedir acciones tras cierre | PHP outcomeCases |
| E02 | Tres vivos | Continúa; sin ganador | Continúa; sin ganador | Aprobado | No se detectó defecto en este criterio | Conservar evaluador; impedir acciones tras cierre | PHP outcomeCases |
| E03 | Dos vivos | Continúa; sin ganador | Continúa; sin ganador | Aprobado | No se detectó defecto en este criterio | Conservar evaluador; impedir acciones tras cierre | PHP outcomeCases |
| E04 | Un sobreviviente de cuatro | Finaliza; p0 gana | Finaliza; p0 gana | Aprobado | No se detectó defecto en este criterio | Conservar evaluador; impedir acciones tras cierre | PHP outcomeCases |
| E05 | Cero sobrevivientes | Finaliza; empate | Finaliza; empate | Aprobado | No se detectó defecto en este criterio | Conservar evaluador; impedir acciones tras cierre | PHP outcomeCases |
| E06 | Un sobreviviente de dos iniciales | Finaliza; p0 gana | Finaliza; p0 gana | Aprobado | No se detectó defecto en este criterio | Conservar evaluador; impedir acciones tras cierre | PHP outcomeCases |
| E07 | Timeout con puntuaciones iguales | Finaliza; empate | Finaliza; empate | Aprobado | No se detectó defecto en este criterio | Conservar evaluador; impedir acciones tras cierre | PHP outcomeCases |
| E08 | Timeout, más bombas de p0 | Finaliza; p0 gana | Finaliza; p0 gana | Aprobado | No se detectó defecto en este criterio | Conservar evaluador; impedir acciones tras cierre | PHP outcomeCases |
| E09 | Timeout, más bajas de p0 | Finaliza; p0 gana | Finaliza; p0 gana | Aprobado | No se detectó defecto en este criterio | Conservar evaluador; impedir acciones tras cierre | PHP outcomeCases |
| E10 | Dos jugadores antes del límite | Continúa; sin ganador | Continúa; sin ganador | Aprobado | No se detectó defecto en este criterio | Conservar evaluador; impedir acciones tras cierre | PHP outcomeCases |
| E11 | Último sobreviviente deshabilitado | Continúa | Continúa | Aprobado | No se detectó defecto en este criterio | Conservar evaluador; impedir acciones tras cierre | PHP outcomeCases |
| E12 | Cero vivos al llegar al límite | Empate por eliminación total | Empate por eliminación total | Aprobado | No se detectó defecto en este criterio | Conservar evaluador; impedir acciones tras cierre | PHP outcomeCases |

## Archivos y operación

Motor/transporte: app/Services/ClassicMovement.php, ArenaInputService.php, BomberGameEngine.php, RoomService.php, GameRulesService.php, GameTelemetryService.php; app/Providers/AppServiceProvider.php; app/Models/GameRule.php; app/Events/InstructorTelemetryEvent.php; config/database.php; routes/console.php y routes/web.php.

HTTP/admin: app/Http/Controllers/BomberGameController.php y AdminRulesController.php. Cliente: resources/js/app.js, game/classicMovement.js, game/rendering/gameRenderer.js; resources/views/bomber/arena.blade.php, admin-rules.blade.php e instructor.blade.php.

Pruebas: tests/Feature/ClassicGameplayTest.php, MultiplayerAuditTest.php, RoomServiceTest.php y RoundSynchronizationTest.php; tests/Unit/ClassicMovement.test.js, ClassicGameplay.browser.js, ArenaClient.test.js y BomberGameEngineTest.php. Evidencia: los tres JSON recovery y este informe. El reporte anterior se conserva como histórico. public/build se regeneró y está ignorado por Git.

Reproducción:

```powershell
php artisan test --compact
node --test tests/Unit/ArenaClient.test.js tests/Unit/ClassicMovement.test.js
$env:BOMBER_RECOVERY_METRICS='1'
php artisan test --compact tests/Feature/ClassicGameplayTest.php
vendor/bin/pint --dirty --format agent
npm.cmd run build
# Con HTTP, Reverb, worker y Chrome CDP ya iniciados:
$env:BOMBER_BROWSER_APP='http://127.0.0.1:8002'
node tests/Unit/ClassicGameplay.browser.js
```

Mantener Reverb y un solo `php artisan arena:tick --no-interaction`. Para reemplazar el worker: `php artisan arena:tick --restart --no-interaction`, esperar su salida y arrancar uno nuevo. Reverb necesita reiniciarse cuando cambia el listener PHP; ya se reinició para esta validación.

Recargar las páginas y comenzar una partida nueva/revancha para adoptar el modelo/configuración nuevos. Las partidas activas conservan su snapshot.

## Comprobación manual pendiente en dispositivos físicos

1. Crear partida nueva de cuatro jugadores sin colisiones entre jugadores. Mantener una dirección diez segundos, soltar, invertir y doblar en un cruce.
2. Cruzar frontalmente y reunir cuatro personajes en la misma zona; ninguno debe detener a otro. Repetir pasando por un marcador de muerto.
3. Colocar bombas simultáneamente, salir y tratar de volver; escapar doblando una esquina. Comparar explosión y eliminación en los cuatro dispositivos.
4. Cambiar de ventana con tecla presionada, ocultar pestaña, cortar red y volver. Comprobar que no haya desplazamiento repetido.
5. Finalizar y pedir revancha desde ganador y desde empate. Repetir con cuatro redes diferentes y controles táctiles físicos.

Estado: **no ejecutado por personas en hardware físico**. Los equivalentes automatizados tienen su estado individual en la matriz.

## Pendientes y riesgos conocidos

- Validación humana de sensación de control y cuatro dispositivos físicos/WAN, suspensión móvil y carga completa con muchas salas reales. Las pruebas ejecutadas no sustituyen estas condiciones.
- Identidad HTTP heredada acepta X-Player-ID/player_id y los canales son públicos. El nuevo token WS solo se entrega al propietario de la cookie; eso no convierte el resto de la API histórica en autenticación robusta. Endurecer antes de uso público hostil.
- Autoridad en Cache: pérdida total puede recuperar desde la copia inicial de DB. No se implementó checkpoint persistente ni recuperación de caída del servidor.
- Countdown continúa bloqueado por cliente; el servidor conserva su comportamiento previo frente a llamadas HTTP manuales durante countdown.
- Flags heredados random_map_generation y draw_on_simultaneous_elimination no tienen ramificaciones completas en el motor: mapa sigue por semilla y cero vivos siempre empata. No se redefinieron silenciosamente esos modos durante la recuperación. La regla clásica solicitada de empate sí está aplicada.
- La interpolación remota existente amortigua al último estado; no es un buffer de snapshots con reproducción exacta. Los cambios discontinuos pueden requerir ajuste inmediato.
- El preset opcional con colisión sólida no tiene la misma validación continua de resolución simultánea que el modo clásico sin colisiones; la matriz sólida heredada valida el camino discreto. La aceptación y mediciones principales corresponden al default sin colisión.
- No se declara auditoría integral completa de todos los escenarios externos faltantes ni certificación de rendimiento de producción. La recuperación del control y la integración de cuatro clientes sí cuentan con evidencia reproducible local.

