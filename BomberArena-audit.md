# Auditoría técnica de Bomber Arena

> Informe histórico. Las reglas de ocupación obligatoria y controles discretos descritos aquí fueron sustituidos para partidas nuevas por la recuperación clásica. Consultar [el informe vigente](BomberArena-recovery-report.md) y su evidencia de cuatro navegadores reales.

Corrección de movimientos involuntarios: los controles actuales dejaron de enviar intenciones continuas `walk`, porque la latencia de `stop` permitía varios pasos después de una pulsación corta. Cada pulsación envía ahora un único `move`. Solo una presión mantenida durante 300 ms habilita la repetición local; cada repetición requiere que no haya otra solicitud/orden pendiente. Al soltar se cancela el temporizador y se eliminan las repeticiones todavía no enviadas. Las pulsaciones explícitas pendientes se conservan y se envían con un máximo de un movimiento por petición para no perderlas en lotes. Una repetición que ya llegó al servidor puede completar su único paso, pero no genera recorrido autónomo. El umbral de 300 ms distingue pulsación de control mantenido; no retrasa el primer paso ni modifica la simulación. La anticipación visual sigue separada del estado confirmado.

Validación de esta corrección: 46 tests JavaScript aprobados, con pulsación corta y respuesta rápida, petición de bomba retrasada, dos pulsaciones independientes y liberación durante una petición pendiente. Regresión HTTP PHP ejecutada: una pulsación con copia retrasada de la misma secuencia conserva exactamente una casilla durante doce ticks posteriores, sin intención continua. Pint y compilación aprobados. No hubo cambio del motor en esta corrección y no fue necesario reiniciar el proceso de simulación; es necesario recargar la página para cargar los controles nuevos.

Segunda corrección de la demostración: se reprodujo en JavaScript el bloqueo al pulsar/soltar una dirección mientras la petición de una bomba seguía pendiente. La coalescencia sustituía `walk` por `stop` y perdía el movimiento antes de enviarlo. Ahora conserva esa pulsación como un paso discreto; las renovaciones de una dirección ya enviada no generan pasos adicionales al soltar. Se agregó anticipación visual de una casilla en `localPreview`, separada de `gameState.players.x/y`, con reconciliación por secuencia/tick, vencimiento y restricciones espaciales del renderizador. La animación no decide colocación de bombas, daño, colisiones ni resultados.

Mediciones reales, solo localhost: cinco GET de estado tardaron 288/268/419/196/220 ms. La conexión WebSocket directa a Reverb tardó 36 ms y un único ping/pong dio 1 ms. Son muestras pequeñas sin carga controlada; no certifican RTT remoto, FPS ni distribución de rendimiento. Se amplió SAFE_ZONES para incluir un giro fuera de la cruz inicial de fuego en cada esquina. Esto cambia la distribución de bloques alrededor de los spawns de las partidas nuevas; no altera las paredes ni modifica tableros ya iniciados. La prueba usa el generador real con densidad de bloques 100%, coloca cuatro bombas y verifica que los cuatro jugadores escapan antes de los dos segundos originales del fusible.

Validación de esta corrección: 120 pruebas PHP y 44 JavaScript aprobadas; Pint y build correctos. Casos nuevos: pulsación de escape con petición de bomba pendiente, renovación sin paso extra al soltar, anticipación visual sin modificar la posición confirmada y rutas de escape de los cuatro spawns. El proceso de simulación se reinició. La sesión real de cuatro navegadores y la percepción de fluidez siguen pendientes de validación.

Actualización por movimiento lento observado en la demostración: se corrigió la dependencia de repetición del teclado y de una petición HTTP por cada paso. Teclado y controles táctiles envían `walk` al pulsar, renuevan la dirección cada 250 ms y envían `stop` al soltar. El servidor produce los pasos en sus ticks, conserva un paso para pulsaciones cortas y vence la intención tras un segundo sin renovación. Velocidades actuales 1/2/3: 100/75/50 ms; la velocidad de 75 ms se distribuye entre ticks de 50 ms conservando el deadline acumulado. Se añadió tolerancia de un microsegundo en las comparaciones de cooldown para evitar rechazos por redondeo. El polling HTTP es ahora una vez por segundo; WebSockets siguen publicando estados de movimiento. Los párrafos de 150/100/50 ms y las métricas sintéticas siguientes describen la auditoría inicial, no un benchmark de esta actualización.

Validación de la actualización: 119 pruebas PHP aprobadas, incluidas movimiento mantenido, escape de una bomba con fusible sin modificar, conflictos entre dos direcciones mantenidas, caducidad y pulsación corta. 41 pruebas JavaScript comprueban también pulsar, ignorar repetición del sistema y detenerse al soltar. Pint y compilación Vite aprobados. Se reinició el proceso `arena:tick` para cargar el código actualizado. Sigue pendiente verificar la fluidez real en los navegadores de la demostración; no se declara un FPS real medido.

Fecha: 2026-10-14. Resultado: correcciones implementadas y regresiones automatizadas aprobadas. **La aceptación de cuatro navegadores reales conectados a Reverb queda sin certificar.** No se midieron FPS, RTT WebSocket ni CPU de una partida real.

## Alcance y evidencia

Se confirmaron PHP 8.4.20, Laravel 13.35.0, Reverb 1.12.0, PHPUnit 12.5.39 y Vite 8.3.4 instalado. No hay `.ai/rules` en el repositorio. Se aplicaron las habilidades Laravel y testing del proyecto. Boost no estaba expuesto como herramienta en esta sesión; se inspeccionó código instalado y documentación oficial de [locks de Laravel 13](https://laravel.com/framework/docs/13.x/cache) y [PHPUnit 12.5](https://docs.phpunit.de/en/12.5/).

No se localizaron archivos con las matrices originales J01–J15, B01–B15, L01–L12, V01–V14 y E01–E12. Los 68 IDs siguientes son una **reconstrucción explícita** basada en los escenarios de la solicitud. No se afirma equivalencia con especificaciones externas ausentes. Los escenarios cuyo enunciado original no fue entregado quedan sin verificar contra ese original.

Última ejecución completa: `php artisan test --compact`: **116/116**, 47 837 assertions, 5.926 s. `node --test tests/Unit/ArenaClient.test.js`: **40/40**, 134.391 ms. Las duraciones cambian entre ejecuciones. Pint pasó. La compilación Vite pasó; advertencia preexistente sobre la dependencia opcional fontaine, sin instalar dependencias.

## Arquitectura y diagnóstico

| Área | Implementación localizada | Hallazgo / decisión |
|---|---|---|
| Movimiento y colisiones | app/Services/BomberGameEngine.php | Casillas enteras; un paso ortogonal por comando. No hay física continua ni Three.js. |
| Autoridad de sala | app/Services/RoomService.php | Estado vivo en Cache; lock por sala. DB conserva estado inicial/final, no cada movimiento. No debe confundirse la copia de DB con la autoridad viva. |
| Comandos | BomberGameController; POST /actions, /move, /bomb | HTTP lleva comandos. WebSockets publican eventos; no transportan las entradas de movimiento. |
| Resolución anterior | handleActions | Hasta 40 movimientos consecutivos por petición, sin velocidad del servidor; el primer mensaje podía ganar un destino común. |
| Jugadores / ingreso | RoomService, RoomPlayer | Se agregó lock de ingreso para evitar asignar el mismo slot en dos joins concurrentes. El lock real entre procesos requiere validación de carga externa. |
| Bombas / eliminación | BomberGameEngine | Fusible del servidor; cadenas; eliminación alive=false. Se corrige ejecución antes/después de movimientos y propiedad del daño de cadena. |
| Finalización | evaluateMatchOutcome + RoomService | Sobreviviente, eliminación total, timeout por bombas/bajas. Persistencia de transición final. |
| Broadcast | GameRoomEvent, ShouldBroadcastNow | Canal público arena.CODE; eventos incluyen ronda, versión y UUID. Broadcast sucede fuera del lock y puede llegar desordenado. |
| Cliente | resources/views/bomber/arena.blade.php | La predicción sobrescribía coordenadas de colisión y consumo de powerups; además ignoraba snapshots mientras había entradas pendientes. |
| Renderizador | resources/js/game/rendering/gameRenderer.js | Canvas 2D; tablero cacheado; posiciones visuales en displayPos. Lerp independiente podía cruzar esquinas o aproximar demasiado personajes. |
| Temporizadores | Cliente anterior + motor PHP | No había proceso autónomo. Se agrega arena:tick; las consultas del cliente siguen sirviendo como respaldo. |
| Diagnóstico | F3, Canvas | Habilitado en local o administrador autenticado; tres casos de autorización ejecutados. No muestra cookies, claves, contraseñas ni payloads privados. |

Se corrigieron primero las invariantes de ocupación y la autoridad de coordenadas; después secuencias/snapshots, interpolación, IDs visuales residuales, temporizadores y aleatoriedad local. No se sustituyó el renderizador ni se reescribió el motor completo.

## Contrato de simulación y cambios de jugabilidad

Ticks lógicos de **50 ms (20 Hz)**. Un comando de movimiento recibido por el servidor pertenece al siguiente tick abierto, según su reloj. Solo se resuelven ticks cerrados. Para el mismo conjunto de entradas del mismo tick, el resultado no depende de la inserción de los mensajes. Diferente latencia puede asignar una entrada a otro tick: no se promete un resultado idéntico para líneas temporales diferentes.

Los orígenes de todos los vivos se reservan durante el tick. Se rechazan todos los contendientes de un destino compartido; se prohíben swaps, ciclos y seguir a otro jugador entrando en su origen durante ese mismo tick. Esto es deliberadamente conservador y queda documentado como regla añadida. Los muertos conservan marcador visual, pero jamás ocupación. player_collisions=true y dead_players_block_movement=false quedan forzados en snapshots nuevos y en colisiones, aun si una configuración antigua decía otra cosa; los controles administrativos heredados todavía pueden guardar valores que el motor ignora.

Velocidades 1/2/3: **150/100/50 ms por casilla**, máximos teóricos 6.67/10/20 pasos/s. Antes teclado=90 ms y touch=120 ms, con backend sin límite. La cuantización y los tres niveles uniformes son un cambio documentado, no una medición de rendimiento. Cliente y servidor usan los mismos intervalos; los paquetes no pueden acelerar la física. Un lote admite solo un movimiento por jugador por tick; la secuencia más alta reemplaza una intención anterior del mismo jugador en el tick. Secuencias duplicadas o anteriores no repiten acciones. Entradas pendientes con más de 500 ms de antigüedad se descartan al avanzar para evitar replay tras interrupción del proceso. La cola cliente está limitada a cuatro comandos.

Las bombas se colocan en la **posición confirmada existente al procesar el comando**. Los movimientos quedan para su tick; por tanto un lote mixto movimiento+bomba no significa que la bomba se coloque después de ese movimiento pendiente. Se retiró la colocación optimista dentro del estado confirmado. La salida de la propia bomba y el bloqueo de reentrada se conservan. La explosión hace daño al detonar; su animación posterior sigue siendo visual, no se añadió daño persistente de llamas.

Se procesan fusibles antes de los movimientos del instante correspondiente; no se permite escapar mediante un mensaje recibido después del vencimiento. Bombas vencidas se ordenan por tiempo/posición/propietario. El daño en cadena usa los dueños de las bombas que alcanzan cada casilla; si varios dueños elegibles alcanzan una víctima, el ID ordenado sirve como desempate de atribución. La aleatoriedad del tablero y drops se aisló para que otra sala no altere el generador global.

## Coordenadas, reconciliación y transporte

Servidor: players.x/y de Cache. Cliente confirmado: gameState.players.x/y, alimentado por snapshots o eventos confirmados. Posición visual: displayPos en píxeles. Modelo gráfico: drawCharacter dibuja alrededor de ese centro; bobbing, recoil y dirección de presentación no modifican x/y. Hitbox lógica: **una casilla de 50×50 px** al proyectar. El núcleo visual usado para evitar aproximaciones indebidas mide 35×35 px; no sustituye la hitbox del servidor. F3 muestra ambas: cian lógica, magenta visual.

Horizontal: centro = (50x+25, 50y+25). Vertical: centro = (50(10-y)+25, 50x+25). Las pruebas verifican ambas proyecciones y las cuatro direcciones inversas. El cliente interpola segmentos ortogonales de una casilla; correcciones diagonales o saltos múltiples se ajustan al destino confirmado para no atravesar casillas intermedias. Si los candidatos visuales se solapan, se reconcilia el conjunto vivo contra posiciones confirmadas únicas. Puede existir un salto de corrección: no se ocultaron sprites ni se añadió espera artificial para disimular colisiones.

Estados viejos se descartan por round_id, version y tick. Eventos HTTP/WS se deduplican por event_id, con caché acotado a 512 IDs. Tombstones de bombas detonadas evitan resurrección por mensajes tardíos; powerups obsoletos no vuelven a aparecer. Se acepta reconciliación aun con solicitudes pendientes. La reconexión de Echo solicita un snapshot; polling de tick mantiene un máximo de una solicitud pendiente por cliente. Se emite state_updated al cambiar versión y se recibe el estado completo en respuestas HTTP. Los endpoints heredados sin secuencia mantienen compatibilidad; no ofrecen exactamente una ejecución por comando frente a reintentos con nuevos identificadores.

El panel muestra sala, tick, versión, secuencia confirmada, coordenadas, hitboxes, RTT **HTTP** estimado, antigüedad del último mensaje, cambios discontinuos, descartes, eventos recibidos, FPS del renderizador y estado vivo/eliminado. FPS mide requestAnimationFrame; 20 Hz describe la simulación. El contador de reconciliación lógica cuenta saltos mayores a una casilla, no cada paso normal. Los contadores visuales son acumulados; no se implementó una ventana móvil de correcciones/minuto. maxInterpolationAgeMs es una aproximación de la fase visual, no latencia total servidor→pantalla.

## Matriz ejecutada

Evidencia PHP: tests/Feature/MultiplayerAuditTest.php, providers movementCases/bombCases/networkCases/outcomeCases, incluidos en la ejecución completa anterior. Evidencia JS: tests/Unit/ArenaClient.test.js, nombres V01–V14. “Aprobado” certifica el alcance automatizado indicado, no una sesión real de cuatro navegadores.

| ID | Escenario | Esperado | Observado | Estado | Causa raíz | Corrección | Evidencia |
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
| J15 | Colisiones configuradas como desactivadas | Ocupación viva sigue bloqueando | Rechazado | Aprobado | Reglas administrativas contrarias al requerimiento | Invariante obligatoria | PHP movementCases; orden inverso ejecutado |
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

Pruebas adicionales ejecutadas: desconexión de uno de cuatro con los demás enviando heartbeat, liberación de su casilla y no resurrección; cuatro variantes de gracia/reconexión/eliminación; colisión HTTP/servicio en tick compartido; secuencias HTTP duplicadas e inválidas; secuencia más alta en ambos órdenes de llegada; explosión anterior a entrada nueva; propiedad del daño de cadena con autodaño deshabilitado; cooldown; timer autónomo con arena:tick --once; diagnóstico para anónimo/usuario/admin; aislamiento de dos salas en Cache; rematches y rondas antiguas; final simultáneo por bombas; broadcast fallido; eventos duplicados; snapshots terminales y resultados de victoria/derrota/empate. Tres pruebas JS usan una línea temporal virtual con 30/150/300 ms, demoras distintas por cliente, jitter 0–89 ms, duplicados y pérdida temporal en un cliente; los cuatro convergen al snapshot 101. Estas pruebas no miden RTT de red.

## Rendimiento reproducible

Artefacto: BomberArena-audit-metrics.json. Una prueba ejecutó 12 configuraciones; cada una simuló 60 segundos, 1 200 ticks de 50 ms, jitter determinista 0–39 ms, demoras asimétricas por jugador y pausa de entradas durante ticks 400–460. Con 40 jugadores son diez salas de cuatro. Se comprueba unicidad de posiciones en cada tick. Se usa hrtime para costo del motor; la latencia es virtual y no causa sleeps de red.

**Estos números excluyen HTTP, Cache/DB, broadcasts, Reverb, Canvas real y GPU.** El tiempo de muestra incluye las assertions de unicidad; por ello es una medición del bucle de prueba, no un benchmark aislado de producción. Memoria es el pico del proceso PHPUnit completo; no consumo incremental por sala. Eventos son resultados del motor; no paquetes WebSocket enviados.

| Jugadores | Salas | Demora virtual ms | Media tick ms | p95 ms | Máximo ms | Eventos motor | Rechazos de movimiento | Rechazos de colisión | Pico proceso MiB |
|---|---|---|---|---|---|---|---|---|---|
| 2 | 1 | 30 | 0.0033 | 0.0073 | 0.0306 | 566 | 0 | 0 | 56 |
| 2 | 1 | 150 | 0.0033 | 0.0068 | 0.0124 | 467 | 99 | 0 | 56 |
| 2 | 1 | 300 | 0.0034 | 0.0055 | 0.0091 | 519 | 46 | 0 | 56 |
| 3 | 1 | 30 | 0.0037 | 0.0084 | 0.0268 | 795 | 54 | 0 | 56 |
| 3 | 1 | 150 | 0.0034 | 0.0063 | 0.0143 | 552 | 297 | 118 | 56 |
| 3 | 1 | 300 | 0.0041 | 0.0077 | 0.0199 | 689 | 158 | 40 | 56 |
| 4 | 1 | 30 | 0.0045 | 0.0114 | 0.0253 | 1024 | 108 | 0 | 56 |
| 4 | 1 | 150 | 0.0048 | 0.0087 | 0.0154 | 834 | 297 | 118 | 56 |
| 4 | 1 | 300 | 0.0052 | 0.0091 | 0.0914 | 971 | 158 | 40 | 56 |
| 40 | 10 | 30 | 0.0383 | 0.0925 | 0.1485 | 10240 | 1080 | 0 | 56 |
| 40 | 10 | 150 | 0.0434 | 0.0768 | 0.1597 | 8340 | 2970 | 1180 | 56 |
| 40 | 10 | 300 | 0.0465 | 0.0810 | 0.1623 | 9710 | 1580 | 400 | 56 |

Los rechazos pueden incluir cooldown y muros; se separan de conflictos de ocupación. La invariancia espacial no depende de que la demora sea pequeña. No hay umbral de FPS ni CPU certificado con estos datos.

| ID adicional | Escenario / métrica | Esperado | Observado | Estado | Causa / limitación | Corrección pendiente | Evidencia |
|---|---|---|---|---|---|---|---|
| REAL01 | Cuatro navegadores reales y Reverb | Misma autoridad, sin cruces persistentes | Sin observación de sesión real | No ejecutado | Herramienta de navegador no disponible; no se instalaron dependencias | Ejecutar sesión end to end y capturar trazas | Ninguna medición real |
| REAL02 | FPS desktop/móvil | Registrar distribución de frames | Sin medición real | No ejecutado | Canvas de tests es un doble | Capturar panel y perfil del navegador | Instrumentación disponible |
| REAL03 | RTT WebSocket 30/150/300 ms | Distribución de RTT real | Solo demoras virtuales y contador HTTP | No ejecutado | No existe ping/ACK WebSocket instrumentado | Instrumentar eco/ACK y probar Reverb | Simulador JS no reemplaza RTT |
| REAL04 | CPU y memoria de Reverb + PHP bajo carga | Registrar procesos y duración | Solo memoria pico PHPUnit | No ejecutado | Benchmark no integra servicios | Perfil de procesos durante sesión de carga | JSON de motor únicamente |
| REAL05 | Retraso autoridad→pantalla | Medir recepción y presentación con reloj correlacionado | Sin distribución end to end | No ejecutado | Aproximación visual no incluye transporte | Capturar timestamps correlacionados | maxInterpolationAgeMs disponible |
| REAL06 | Correcciones/minuto real | Tasa por minuto en red real | Solo contadores acumulados | No ejecutado | Falta ventana temporal y sesión | Añadir/medir ventana de métricas | Panel parcial |
| REAL07 | Paquetes WebSocket reales y obsoletos | Registrar emisión/recepción/descartes | Solo eventos recibidos y resultados sintéticos | No ejecutado | No se ejecutó carga real | Capturar tráfico de Reverb y ambos extremos | Contadores parciales |
| REAL08 | Locks entre varios procesos/hosts | Una autoridad por sala | Servicio probado en un proceso | No ejecutado | Backend array de test no demuestra locking distribuido | Ejecutar contra el cache compartido configurado | Tests de lock y sala existentes |
| REAL10 | Heartbeats con pestañas suspendidas / móviles | Política de gracia observada en dispositivos | Solo tests de reloj controlado | No ejecutado | Throttling del navegador no simulado | Medir sesiones reales y ajustar presupuesto documentado | Cinco escenarios PHP de liveness aprobados |
| REAL09 | Enunciados originales de 68 IDs | Correspondencia exacta | Enunciados no disponibles | No ejecutado | No existen archivos suministrados con esas matrices | Contrastar cuando se aporten los enunciados | Matriz reconstruida explícita |

## Pendientes y límites de aceptación

1. **No se declara aprobación end to end.** Hace falta ejecutar REAL01–REAL08 en el entorno de demostración con cuatro dispositivos/navegadores y Reverb. Los tests automatizados prueban reglas y convergencia de los componentes, no su rendimiento real en conjunto.
2. **Liveness implementado y pendiente de validar con navegadores reales:** cada tick HTTP de un participante vivo y sus acciones actualizan last_seen_at en el servidor. El proceso autónomo elimina ausentes después de 1 segundo de presupuesto de heartbeat más reconnect_grace_seconds; con reconnection_enabled=false se omite la gracia, y con eliminate_on_disconnect=false el jugador permanece vivo. La gracia es temporal y no resucita eliminados. Se ejecutaron pruebas de las cuatro combinaciones de política y una sala de cuatro donde el ausente muere, libera la casilla y no revive al volver. Queda medir el efecto del throttling de pestañas en segundo plano y suspensiones móviles.
3. **Identidad y privacidad:** PlayerIdentityService acepta X-Player-ID/player_id y el canal de sala es público. Los IDs que aparecen en estado no deben considerarse credenciales seguras frente a suplantación. La sesión del administrador sí controla el diagnóstico; el transporte de jugadores necesita identidad vinculada a sesión y canales autorizados si se amplía más allá de la demostración educativa.
4. **Countdown:** la cuenta regresiva bloquea entradas en el cliente; el servidor todavía no valida una fase de countdown contra solicitudes HTTP manuales. Se conservó esa regla existente; requiere una decisión explícita sobre inicio del reloj de ronda/fusibles y prueba correspondiente.
5. **Compatibilidad:** clientes antiguos sin secuencia y lotes mixtos con expectativas de movimiento inmediato deben actualizarse. Bombas permanecen en coordenadas confirmadas. Se documentó el comportamiento nuevo; no se ofrece replay exacto de comandos heredados sin ID.
6. **Operación y costo:** el proceso usa locks por sala y broadcasts síncronos fuera del lock. Se agrega un snapshot por cambio de versión, además de eventos semánticos; el tráfico real y la duración del procesamiento con Cache/DB no están cuantificados. Los canales públicos también publican metadatos de intención pendiente; no hay ocultación de estrategias en este juego.
7. **Resiliencia de Cache:** la autoridad viva depende del store. Si se pierde completamente durante una partida, el fallback de DB es su estado inicial; hace falta una política de checkpoint o cancelación explícita antes de prometer recuperación ante pérdida del servidor/cache.

## Ejecución y archivos

Para temporizadores autónomos, mantener **`php artisan arena:tick --no-interaction`** junto a Reverb y al servidor HTTP. `--once` sirve para una pasada y tiene test. El comando está definido en routes/console.php y es necesario para evaluar ausencias cuando dejan de llegar peticiones; no se arrancó un proceso persistente ni se modificó infraestructura. Las consultas del cliente se hacen cada 100 ms cuando no hay otra pendiente; no son la frecuencia autoritativa de 20 Hz.

Reproducir:

```powershell
php artisan test --compact
node --test tests/Unit/ArenaClient.test.js
$env:BOMBER_AUDIT_METRICS = '1'
php artisan test --compact tests/Feature/MultiplayerAuditTest.php --filter=test_simulation_cost
vendor/bin/pint --dirty --format agent
npm.cmd run build
```

F3 activa diagnóstico solo cuando el servidor habilita el modo para local/admin. El overlay se dibuja en Canvas y no envía comandos ni modifica la simulación. Las coordenadas V se expresan en casillas proyectadas de pantalla; las coordenadas L permanecen en el tablero lógico.

Archivos modificados: app/Services/BomberGameEngine.php; app/Services/RoomService.php; app/Http/Controllers/BomberGameController.php; resources/js/game/rendering/gameRenderer.js; resources/views/bomber/arena.blade.php; routes/console.php; tests/Feature/RoomServiceTest.php; tests/Unit/ArenaClient.test.js. Archivos añadidos: tests/Feature/MultiplayerAuditTest.php, BomberArena-audit-metrics.json y este informe. Vite regeneró public/build, ignorado por Git. No se cambiaron dependencias, migraciones ni reglas persistidas de usuarios.

