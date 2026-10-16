# Auditoría y corrección de sincronización de Bomber Arena

Fecha: 16/10/2026. PHP 8.4; Laravel 13.35; Reverb 1.12; Canvas 2D.

## Respaldo y alcance

Estado previo conservado en `backup/social-before-ordered-input-20261016`, commit `6f7a92b`. Trabajo en `fix/ordered-input-synchronization`. Se conservaron personajes, salas, revancha, configuración de reglas, geometría del tablero y capacidades iniciales. No se incorporaron dependencias, migraciones ni Three.js.

Las partidas nuevas y revanchas anuncian `input_protocol: 3`. Se mantiene el protocolo anterior para rondas ya creadas sin ese indicador. Para comprobar la corrección se debe abrir una partida nueva y cargar el cliente actualizado.

## Diagnóstico con evidencia

| Causa | Archivo y función responsables | Evidencia reproducida | Corrección |
|---|---|---|---|
| C1: confirmación de recepción confundida con confirmación de movimiento simulado | `RoomService::mutateState`, `updateInput`; `ClassicController.reconcile` | La prueba inicial retrocedía de 1.8 a 1.7872 al recibir una confirmación retrasada mientras seguía pulsada la dirección. | Cada fragmento tiene secuencia y duración; `input_ack` avanza después de consumirlo, o descartarlo expresamente por caducidad. Reconciliación desde la confirmación y reproducción exclusiva de fragmentos pendientes. |
| C2: carreras entre canales de movimiento y bomba | `sendCurrentInput`, `sendBomb`, `RoomService::handleActions` | Movimiento viajaba por Reverb y la bomba por HTTP. `mutateState` avanzaba primero el movimiento y la bomba consultaba después la casilla actual. No existía una relación ordenada entre ambas acciones. | Un marcador de bomba con `action_id` y secuencia viaja en el mismo flujo de fragmentos. El servidor coloca la bomba entre los movimientos anteriores y posteriores. |
| C3: reloj del cliente retrocedía con entregas lentas | `syncServerTime` | La traza real pasó de 1792162101.422092 a 1792162100.850814 en unos 21 ms. Las siguientes entradas parecían antiguas al servidor. | Estimación de desfase a partir de la muestra con menor retraso observado; una entrega tardía no disminuye el reloj estimado. Prueba específica en `ArenaClient.test.js`. |
| C4: pérdida de tiempo bajo carga | `BomberGameEngine::advanceOrderedInputs` | Con intervalos reales de 120 y 170 ms, la prueba obtenía posición 41 cuando correspondían 49 y 69. | Presupuesto basado en tiempo transcurrido, con recuperación máxima de 200 ms y un fragmento de margen. Los fragmentos individuales siguen limitados a 50 ms y los antiguos de más de 500 ms se descartan. No se recuperan pausas de varios segundos. |
| C5: corrección por cuantización al contactar una pared | `ClassicMovement::step`, `movementStep` | Un fragmento visual parcial alcanzaba 8.7801 y el paso completo volvía a 8.8 por descartar todo el último subpaso. | Cálculo acotado del punto de contacto en PHP y JS, con redondeo hacia el espacio válido. La hitbox y las reglas de obstáculos permanecen iguales. |
| C6: trabajo de red y almacenamiento en la ruta crítica | `RoomService::mutateState`, `broadcastGameEvent`; `OrderedInputBuffer` | Había publicaciones síncronas de snapshots y escrituras SQLite para estado/bloqueos. La medición local del buzón con bloqueo adicional dio aproximadamente 10.2 ms por entrada; con escritor único dio 4.4 ms. | Estado/bloqueos de arena configurables aparte; en este equipo se usa archivo. Publicador independiente y coalescente, sin cola creciente de snapshots. Evita bloquear archivos cuando la versión no cambió. Reverb es el único escritor de buzones de archivo; HTTP fusiona directamente dentro del bloqueo de sala. |
| C7: historial de predicción y tráfico innecesarios | `ClassicController.updateOrdered/frame/batch`; ciclo de la arena | Se generaban fragmentos estando quieto. También se reutilizó inicialmente el límite de transporte para el historial, perdiendo entradas todavía no confirmadas cuando se retrasaban las confirmaciones. | Historial de hasta 64 fragmentos separado del lote de transporte de hasta 24; al agotarse se solicita resincronización. Estando quieto solo se envía un heartbeat sin desplazamiento cada segundo. HTTP de respaldo se evita mientras llegan estados por WebSocket. |
| C9: presencia social bloqueaba la simulación | `RoomSocialService::heartbeat` | Con el lock de simulación ocupado, un heartbeat válido esperaba tres segundos y fallaba. La prueba reproduce el problema y pasa tras la corrección. | Actualización de presencia fuera del lock del motor; la expiración de votos pendientes conserva su bloqueo y sus validaciones. |
| C8: simulador ausente | Procesos de desarrollo | Al iniciar esta auditoría estaban activos HTTP y Reverb, pero no `arena:tick`. | Se iniciaron los procesos necesarios y se añadieron a `composer run dev`, usando la API `DevCommands` instalada en Laravel 13. |

No se encontró una posición global compartida ni fue necesario reemplazar el motor. La modificación del estado conserva un bloqueo por sala; el publicador tiene un bloqueo independiente y no escribe posiciones.

## Flujo corregido de movimiento

1. Teclado y controles de puntero actualizan una intención cardinal; la repetición del sistema operativo no determina la velocidad.
2. `ClassicController` integra fragmentos de duración fija según la frecuencia configurada; al cambiar o soltar dirección cierra el fragmento parcial. La presentación propia responde sin esperar red.
3. Se envían lotes de entradas por el transporte Reverb existente, con firma individual, sala, ronda y secuencias. `/input` acepta el mismo formato como respaldo. No se envían coordenadas autoritativas desde el navegador.
4. `ArenaInputService` valida la identidad firmada y los límites del lote. El buzón se identifica por sala, ronda y jugador. El proceso de simulación incorpora los fragmentos bajo el bloqueo de esa sala.
5. El motor consume los fragmentos por secuencia, con presupuesto temporal por jugador, colisiones conocidas y orden estable de jugadores por identificador. Los jugadores se atraviesan en el modo clásico.
6. El estado confirma la última secuencia consumida. Los fragmentos expirados también se reconocen para que no se reintenten indefinidamente; no producen desplazamiento.
7. `arena:publish` lee el estado más reciente, respeta `publish_hz` y omite versiones ya publicadas y rondas anteriores. La red deja de bloquear cada tick de movimiento.
8. El cliente descarta versiones/ticks obsoletos. Reconstruye su predicción desde la posición confirmada más las entradas sin confirmar. Los remotos conservan la interpolación existente; la posición propia no utiliza esa interpolación.

Posiciones físicas continuas (`position_x/y`); casillas lógicas redondeadas (`x/y`). Las animaciones y el Canvas no deciden posiciones lógicas, daño, eliminaciones ni resultados.

## Flujo corregido de bomba

`sendBomb` cierra el fragmento de movimiento actual y añade un marcador de duración cero con identificador único. Los movimientos posteriores llevan secuencias mayores. El servidor procesa el marcador en esa posición de la secuencia y llama al mismo `handlePlaceBomb` existente, con el instante lógico de procesamiento.

`seen_actions` impide reutilizar la acción en la ronda. `action_results` conserva las últimas confirmaciones con secuencia, tick, instante y casilla. Las bombas conservan temporizador, propagación, encadenamientos y permiso inicial de salida existentes.

La representación provisional se guarda fuera de `gameState`; no genera daño ni modifica colisiones autoritativas. Se elimina al confirmar o rechazar la acción, sin duplicar la bomba confirmada.

La prueba determinista parte de `(4,5)`, procesa movimiento hasta `4.2`, coloca la bomba en `(4,5)` y continúa hasta `5.2`. Reenviar la acción, incluso con otra secuencia, no crea otra bomba.

## Matriz de validación

Los estados de esta tabla corresponden a pruebas ejecutadas; las mediciones reales y sus límites se detallan a continuación.

| ID | Escenario | Esperado | Observado | Estado | Causa raíz | Corrección | Evidencia |
|---|---|---|---|---|---|---|---|
| A | Mantener dirección cinco segundos y soltar | Sin retrocesos; detención local inmediata | Predicción y confirmación convergen; no se reanuda al soltar | Aprobado | C1, C7 | ACK procesado y reproducción pendiente | `OrderedInputController.test.js`, simulación de cinco segundos |
| B | Tres jugadores en distintas direcciones diez segundos | Posiciones independientes | Posiciones finales 41, 40 y 45 en los ejes correspondientes | Aprobado | C1, C4 | Flujo por jugador y presupuesto temporal | `test_three_players_keep_different_directions_for_ten_seconds` |
| C | Avanzar, colocar bomba y seguir | Bomba en (4,5), jugador continúa hacia (5,5) | Bomba (4,5), posición física final 5.2 | Aprobado | C2 | Marcador ordenado y deduplicación | `test_bomb_is_placed_between_prior_and_subsequent_motion_and_retries_do_not_duplicate_it` |
| D | Dos bombas simultáneas mientras se mueven | Dos bombas en casillas correctas | Dos propietarios y dos posiciones independientes; sin duplicados | Aprobado | C2 | Secuencia por jugador y acción única | `test_two_players_bomb_simultaneously_without_shared_positions_or_duplicate_actions` |
| E | RTT 30/100/200/300 ms, jitter y duplicados | Sin retrocesos continuos ni movimientos posteriores a soltar | Convergencia y detención correctas en las cuatro redes virtuales | Aprobado | C1, C3, C7 | Historial, secuencias y reloj estable | Pruebas `E ordered prediction` y dataset PHP de 1–4 actores |
| F | Estado antiguo después del nuevo | No reemplazar estado ni reproducir entradas confirmadas | Estado más reciente preservado; publicación anterior de ronda rechazada | Aprobado | C1 | Guardas de versión/tick/ronda y publicador coalescente | JS `F obsolete snapshots`; prueba del publicador |
| G | Cuatro productores concurrentes | Sin sobrescrituras ni secuencias compartidas | Cuatro historiales y confirmaciones independientes | Aprobado | C1, C6 | Bloqueo por sala y buffers por identidad/ronda | Dataset PHP con cuatro actores; ensayo de E/S con 480 aserciones |
| H | Desconexión durante movimiento | Descartar intención pendiente y resincronizar | Reinicio sin reproducción de entradas antiguas; pausa larga no genera ráfaga | Aprobado | C7 | Marcador reset, descarte y límites temporales | PHP `expired_frames_and_reconnect_reset`; JS `H reconnect` |
| I | Salas simultáneas | Sin mezclar posición, ronda ni secuencia | Aislamiento de buzones, estado y cuatro simulaciones simultáneas | Aprobado | C6 | Claves y bloqueos específicos por sala/ronda | Prueba de aislamiento, `MultiplayerAuditTest` y benchmark de cuatro salas |
| T1 | Intervalos del worker de 75/120/170 ms | Conservar velocidad sin acumular retraso | Posiciones 31/49/69, sin pérdida temporal | Aprobado | C4 | Presupuesto transcurrido acotado | Dataset `irregular_worker_intervals` |
| T2 | Renderizado a 30/60/120 FPS | Misma velocidad y detención | Mismo desplazamiento en un segundo | Aprobado | C1 | Fragmentos independientes de FPS | Dataset JS `ordered input preserves speed` |
| T3 | Contacto parcial con muro | No oscilar ni atravesar | Contacto estable en 8.78, dentro del espacio válido | Aprobado | C5 | Resolución del último subpaso | JS `contact with a wall` |
| T4 | Historial completo o confirmación muy tardía | No borrar silenciosamente entradas sin confirmar | Historial separado del transporte; al agotarse pide resincronización | Aprobado | C7 | Límite de 64 con reset explícito | Dos pruebas JS de historial |
| T5 | Firma inválida, duración no finita, ronda incorrecta | Rechazo en servidor | Solicitudes inválidas rechazadas; duplicados no duplican comandos | Aprobado | Integridad del protocolo | Validación servidor | Pruebas HTTP, buzones y transporte firmado |
| T6 | Heartbeat con el lock del motor ocupado | Presencia válida sin bloquear movimiento | Heartbeat autorizado correcto; identidad ajena rechazada | Aprobado | C9 | Separar timestamps de presencia del lock de simulación | `test_presence_heartbeat_does_not_wait_for_or_take_the_simulation_lock` |

## Instrumentación y operación

F3 activa el panel existente exclusivamente en desarrollo o para un administrador autorizado. Muestra coordenadas e hitboxes, tick, versión, secuencia, ACK, intención, entradas pendientes, conexión, RTT, correcciones, diferencia de predicción y demora de confirmación de bomba. El servidor agrega tiempo de simulación, frecuencia real y descartes por caducidad solo en entorno local. No se muestran firmas ni tokens en el panel.

Frecuencias separadas: renderizado por `requestAnimationFrame`; simulación configurada a 20–30 Hz; envío de lotes hasta aproximadamente 10 Hz más cambios/acciones; publicación según el snapshot de reglas, normalmente 10 Hz. Ninguna de estas cifras sustituye la medición de las demás.

En este equipo se configuró `.env` con `ARENA_STATE_CACHE_STORE=file`; `.env.example` incluye los almacenes de arena. El resto de la aplicación mantiene su caché configurada. El lector conserva compatibilidad con estados anteriores en el almacén predeterminado.

`composer run dev` incluye ahora simulación, publicador y Reverb además de los procesos habituales. Si se gestionan por separado, mantener activos:

```powershell
php artisan reverb:start --no-interaction
php artisan arena:tick --no-interaction
php artisan arena:publish --no-interaction
```

No iniciar simultáneamente el conjunto de desarrollo y copias manuales de esos mismos servicios. `arena:tick` y `arena:publish` tienen leases únicos; sus opciones `--restart` permiten reemplazarlos. Los procesos residentes deben reiniciarse después de modificar su código.

## Alcance y límites de las mediciones

Las redes virtuales usan reloj controlado; sus RTT son de ida y vuelta. El navegador real inyecta retraso de salida y jitter en Reverb, que se identifica separadamente en el JSON; no equivale a simular retraso simétrico en ambas direcciones. El RTT mostrado mide ping/pong por orden, separado del tiempo de simulación y de confirmación de bombas.

El benchmark puro mide integración del motor, sin Laravel HTTP, disco ni publicación. El ensayo de E/S mide Laravel y archivos con base de datos de pruebas en memoria y broadcasting falso. Los navegadores reales incluyen Laravel, Reverb, almacenamiento y Canvas locales.

Los escenarios de uno a cuatro actores son simulados; una partida conserva el mínimo de dos participantes, por lo que el escenario de un actor mantiene un rival quieto. El ensayo real utiliza cuatro navegadores de juego y uno de lobby. No se ejecutó una prueba distribuida en varias máquinas ni una prueba prolongada de horas. En despliegue con varios hosts deben configurarse almacenes compartidos y sus bloqueos; la configuración de archivos/escritor único corresponde a este host con un proceso Reverb.

## Archivos principales

- `app/Services/OrderedInputBuffer.php`: validación, almacenamiento, fusión y filtrado de snapshots.
- `app/Services/ArenaInputService.php`: transporte firmado de fragmentos por Reverb.
- `app/Services/BomberGameEngine.php`: consumo ordenado, ACK procesado, acciones y presupuesto temporal.
- `app/Services/ClassicMovement.php` y `resources/js/game/classicMovement.js`: contacto con obstáculos y predicción/reconciliación.
- `app/Services/ArenaStateStore.php`: acceso consistente al almacén y bloqueo de la arena.
- `app/Services/ArenaSnapshotPublisher.php`, `routes/console.php`: publicación independiente de estados recientes.
- `app/Services/RoomService.php`, `RoomSocialService.php`: integración y uso del mismo bloqueo; reglas sociales conservadas.
- `app/Http/Controllers/BomberGameController.php`, `AdminRulesController.php`: formato de entradas y acceso al estado actualizado.
- `app/Providers/AppServiceProvider.php`: procesos de desarrollo.
- `resources/views/bomber/arena.blade.php`, `resources/js/game/rendering/gameRenderer.js`: entrada ordenada, bomba provisional, reloj, respaldo HTTP y diagnóstico.
- `config/cache.php`, `.env.example`, `phpunit.xml`: configuración e independencia de pruebas.
- `tests/Feature/OrderedInputSynchronizationTest.php`, `tests/Unit/OrderedInputController.test.js`, `ArenaClient.test.js`, `ClassicGameplay.browser.js`: regresiones y reproducción real.


## Resultados finales ejecutados

- `php artisan test --compact`: **193 aprobadas**, 93 380 aserciones; última ejecución 10.782 s.
- `node --test tests/Unit/*.test.js`: **80 aprobadas**.
- `vendor/bin/pint --dirty --format agent`: aprobado.
- `npm.cmd run build`: compilación correcta.
- `git diff --check`: sin errores de whitespace.
- Chrome y Reverb reales: **18 escenarios aprobados**, sin excepciones de navegador. Duración total del ensayo: 83.59 s.

### Cuatro navegadores moviéndose continuamente

Se alternaron direcciones cada 200 ms en los pasillos de salida, durante al menos **11.44 s**. Así se evita dar por buena una prueba donde los jugadores solamente permanecen detenidos contra paredes. Se recogieron entre 571 y 573 muestras por cliente, aproximadamente cada 20 ms.

- Retrocesos mientras se mantiene una dirección: **0**.
- Error de reconciliación registrado en esos cuatro historiales: **0**.
- Entradas descartadas por caducidad en esa ronda: **0**.
- Historial pendiente máximo: **14** fragmentos; no hubo desbordamiento ni reproducción tras desconexión.
- Las cuatro posiciones autoritativas cambiaron independientemente y terminaron confirmadas.

`BROWSER09` midió otra ventana activa de 2.530 s después de la revancha: **0 correcciones/minuto en esa ventana**. No se extrapola esa observación a sesiones de horas.

### Latencia real inyectada

| Retraso de salida añadido | último RTT ping/pong observado | Correcciones nuevas durante esas pruebas | Historial pendiente al finalizar |
|---|---|---|---|
| 30 ms + jitter 0–24 ms | 58.5 ms | 0 | 1 |
| 100 ms + jitter 0–24 ms | 129.1 ms | 0 | 1 |
| 200 ms + jitter 0–24 ms | 230.3 ms | 0 | 0 |
| 300 ms + jitter 0–24 ms | 308.0 ms | 0 | 1 |

Las pruebas virtuales adicionales distribuyen el RTT entre ida y vuelta. Ambos ensayos verifican detención y convergencia; la tabla anterior registra una muestra final, no percentiles de red.

### Bomba durante movimiento real

Acción `bomb_33e1397b9f574914981ef2ef0a3f5cc6`, secuencia **596**, tick **73**: casilla prevista y confirmada **(1,1)**. Una sola bomba confirmada; tiempo pulsación→confirmación **38.5 ms**. La prueba PHP separada cubre expresamente el ejemplo obligatorio (4,5)→(5,5).

### Renderizado, simulación y transporte

| Métrica | Resultado obtenido |
|---|---|
| FPS reales de Canvas, ventana activa | Media 55.96; rango 52.61–60.99 |
| Frecuencia configurada de simulación/publicación | 20 Hz / 10 Hz |
| Frecuencia real medida por el servidor, muestra tras cuatro jugadores | 19.79 Hz |
| Tiempo del motor por invocación, ventana activa | Media 0.097 ms; máximo 0.339 ms |
| Eventos de partida recibidos, ventana activa | 7.90/s |
| Antigüedad de última actualización, ventana activa | Media 73.6 ms; máximo 633.3 ms |
| Entradas pendientes, ventana activa | Media 2.94; máximo 7 |
| Primera respuesta visual observada a la entrada | 7.8 ms |
| CPU agregada de procesos Chrome en tramo latencia/reconexión/revancha | 54.84 segundos de CPU |
| Heap JS del navegador anfitrión, muestra final | 2.30 MiB |
| Buzón de archivo, ensayo Laravel con cuatro actores | Media 4.41 ms/entrada |
| Mutación completa con archivo, ensayo Laravel con cuatro actores | Media 11.37 ms/tick |

El campo `observed_tick_arrival_hz` del JSON divide el avance de ticks recibidos por el tiempo del cliente; incluye variaciones de retraso entre snapshots y **no es la frecuencia real del servidor**. Para esta última se utilizó `server.actual_simulation_hz`, calculada con reloj y contador del servidor. Los FPS son una medición distinta.

La distancia instantánea entre posición predicha y confirmada llegó a 1.198 casillas mientras había entradas pendientes. Esa distancia incluye movimiento todavía no confirmado y no equivale al error de reconciliación: al reproducir las entradas pendientes, las posiciones coincidieron.

### Benchmark determinista del motor

Cada fila simula diez segundos, sin HTTP, disco ni broadcasting. El coste mostrado para cuatro salas es el agregado de actualizar las cuatro en la misma iteración.

| Actores por sala | Salas | Tiempo medio por tick | P95 | Máximo | Pico del proceso de pruebas |
|---|---|---|---|---|---|
| 1 | 1 | 0.0089 ms | 0.0103 ms | 0.0394 ms | 64.0 MiB |
| 2 | 1 | 0.0136 ms | 0.0145 ms | 0.0253 ms | 64.0 MiB |
| 3 | 1 | 0.0203 ms | 0.0247 ms | 0.0301 ms | 64.0 MiB |
| 4 | 1 | 0.0255 ms | 0.0272 ms | 0.0413 ms | 64.0 MiB |
| 4 | 4 | 0.1086 ms | 0.1309 ms | 0.2720 ms | 64.0 MiB |

El pico incluye Laravel y PHPUnit; no representa memoria exclusiva por sala. La CPU de Chrome es acumulada entre varios procesos, no un porcentaje del equipo. El heap indicado no incluye toda la memoria nativa de Canvas ni del navegador.

### Regresión de funcionalidades existentes

Los escenarios reales `SOCIAL01`–`SOCIAL04`, `BROWSER06`–`BROWSER08` verificaron listado por Reverb, filtros, ingreso, capacidad, privacidad, selección de personajes repetidos, viewport móvil, solicitud exclusiva de derrotados, rechazo del ganador solicitante, votos concurrentes, rechazo/aceptación, ronda nueva y conservación de personajes. La suite PHP completa cubrió además reglas administrativas, temporizadores, explosiones, eliminaciones, resultados y aislamiento.

No se ejecutó esta vez una carrera con dos servidores HTTP distintos: el ensayo del último cupo utilizó el mismo origen HTTP. La concurrencia principal auditada y ejecutada fue la de cuatro productores WebSocket, el simulador y las solicitudes HTTP de respaldo.

## Evidencia y reproducción

- `BomberArena-synchronization-browser.json`: resultados y muestras de los navegadores reales.
- `BomberArena-synchronization-metrics.json`: benchmark puro del motor.
- `BomberArena-synchronization-io.json`: 240 escrituras de entrada y 60 mutaciones con cuatro actores, usando archivos.

```powershell
php artisan test --compact
node --test tests/Unit/*.test.js
$env:BOMBER_SYNC_METRICS='1'; php artisan test --compact tests/Feature/OrderedInputSynchronizationTest.php
$env:BOMBER_SYNC_IO_METRICS='1'; php artisan test --compact tests/Feature/OrderedInputSynchronizationTest.php --filter=hot_path
$env:BOMBER_BROWSER_APP='http://127.0.0.1:8000'; node tests/Unit/ClassicGameplay.browser.js
```

El ensayo de Chrome necesita un navegador de pruebas con CDP en el puerto 9223 y los servicios HTTP/Reverb/simulación/publicación activos. Utiliza contextos aislados, crea sus propias salas y cierra esos contextos al finalizar. No requiere paquetes nuevos.

## Límites y pendientes

Los criterios de estabilidad se cumplieron en los escenarios ejecutados; no se afirma latencia constante ni ausencia universal de picos. Se observó una actualización con antigüedad aproximada de 633 ms en la ventana activa, conservando respuesta local y reconciliación correcta. Falta caracterizar esos picos en una sesión larga y en la red/hardware de la demostración.

No ejecutado: prueba de horas, carga distribuida entre hosts y comparación de CPU exclusiva del servidor por sala. La configuración de archivos requiere un único escritor Reverb por host; para despliegue distribuido se debe configurar almacenamiento compartido con bloqueos. Las pruebas y el diagnóstico distinguen explícitamente estos límites de los casos aprobados.
