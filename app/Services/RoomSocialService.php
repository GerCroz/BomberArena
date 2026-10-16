<?php

namespace App\Services;

use App\Events\LobbyRoomsChanged;
use App\Events\RoomSocialEvent;
use App\Models\Room;
use App\Models\RoomPlayer;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RoomSocialService
{
    public function __construct(protected RoomService $rooms, protected GameRulesService $rules) {}

    public function changed(Room $room, string $type = 'room.updated'): void
    {
        if (! $room->is_private) {
            try {
                event(new LobbyRoomsChanged($type));
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
    }

    public function notify(Room $room, string $type, array $data = []): void
    {
        try {
            event(new RoomSocialEvent($room->code, $type, $data));
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    public function publicRooms(): array
    {
        $rules = $this->rules->getActiveRules();
        if (! ($rules['public_rooms_enabled'] ?? true)) {
            return [];
        }
        $statuses = [Room::STATE_WAITING, Room::STATE_READY];
        if ($rules['show_playing_rooms'] ?? true) {
            $statuses[] = Room::STATE_PLAYING;
            $statuses[] = Room::STATE_FINISHED;
        }

        return Room::query()->where('is_private', false)->whereIn('status', $statuses)
            ->withCount('players')->with(['players:id,room_id,nickname,is_host,last_seen_at'])
            ->orderByDesc('last_activity_at')->get()->map(function (Room $room): array {
                return [
                    'name' => $room->name ?: 'Arena '.$room->code,
                    'code' => $room->code,
                    'host' => $room->players->firstWhere('is_host', true)?->nickname ?? 'Sin anfitrión',
                    'players_count' => $room->players_count,
                    'connected_count' => $room->players->filter(fn (RoomPlayer $player): bool => $player->last_seen_at?->gte(now()->subSeconds(30)) ?? false)->count(),
                    'max_players' => $room->max_players,
                    'status' => $room->status,
                    'available' => in_array($room->status, [Room::STATE_WAITING, Room::STATE_READY], true) && $room->players_count < $room->max_players,
                ];
            })->all();
    }

    public function selectCharacter(Room $room, string $playerId, string $character): array
    {
        CharacterCatalog::validate($character);

        return $this->locked($room->code, function (Room $room) use ($playerId, $character): array {
            $player = $room->players()->where('player_id', $playerId)->first();
            if (! $player) {
                return $this->failure('No perteneces a esta sala.');
            }
            if (! in_array($room->status, [Room::STATE_WAITING, Room::STATE_READY, Room::STATE_FINISHED], true)) {
                return $this->failure('El personaje no puede cambiar durante la partida.');
            }
            $rules = $room->rules_snapshot ?? [];
            if (! ($rules['character_selection_enabled'] ?? true) || ! ($rules['character_changes_enabled'] ?? true)) {
                return $this->failure('El cambio de personaje está deshabilitado.');
            }
            $this->ensureCharacterAvailable($room, $character, $playerId);
            $player->update(['character_id' => $character, 'color' => $character, 'last_seen_at' => now()]);
            $room->update(['last_activity_at' => now()]);
            $this->notify($room, 'player.character.updated', ['player_id' => $playerId, 'character_id' => $character]);
            $this->changed($room);

            return ['success' => true, 'player' => $player];
        });
    }

    public function ensureCharacterAvailable(Room $room, string $character, ?string $exceptPlayer = null): void
    {
        if (($room->rules_snapshot['duplicate_characters_allowed'] ?? true) === false
            && $room->players()->where('color', $character)->when($exceptPlayer, fn ($query) => $query->where('player_id', '!=', $exceptPlayer))->exists()) {
            throw ValidationException::withMessages(['character_id' => 'Este personaje ya está seleccionado en esta sala.']);
        }
    }

    public function heartbeat(string $code, string $playerId): array
    {
        $room = Room::where('code', strtoupper($code))->first();
        if (! $room) {
            return $this->failure('Sala no encontrada.');
        }
        $player = $room->players()->where('player_id', $playerId)->first();
        if (! $player) {
            return $this->failure('No perteneces a esta sala.');
        }
        $player->update(['last_seen_at' => now()]);
        $room->update(['last_activity_at' => now()]);
        if (($room->rematch_request['status'] ?? null) !== 'pending') {
            return ['success' => true, 'rematch' => $room->rematch_request];
        }

        return $this->locked($code, function (Room $room) use ($playerId): array {
            $player = $room->players()->where('player_id', $playerId)->first();
            if (! $player) {
                return $this->failure('No perteneces a esta sala.');
            }
            $this->expireVote($room);

            return ['success' => true, 'rematch' => $room->rematch_request];
        });
    }

    public function leave(string $code, string $playerId): array
    {
        return $this->locked($code, function (Room $room) use ($playerId): array {
            $player = $room->players()->where('player_id', $playerId)->first();
            if (! $player) {
                return $this->failure('No perteneces a esta sala.');
            }
            if ($room->status === Room::STATE_PLAYING) {
                return $this->failure('Espera a que termine la partida para abandonar la sala.');
            }
            $player->delete();
            $this->cancelVote($room, 'Un participante abandonó la sala.');
            $this->afterDeparture($room);
            $this->notify($room, 'room.player_left', ['player_id' => $playerId]);
            $this->changed($room, $room->status === Room::STATE_CANCELLED ? 'room.closed' : 'room.player_left');

            return ['success' => true];
        });
    }

    public function requestRematch(string $code, string $playerId, ?string $roundId): array
    {
        return $this->locked($code, function (Room $room) use ($playerId, $roundId): array {
            $state = ArenaStateStore::get($room->code) ?? $room->game_state;
            if ($room->status !== Room::STATE_FINISHED || empty($state['is_game_over'])) {
                return $this->failure('La partida debe finalizar antes de pedir revancha.');
            }
            if (! $roundId || $roundId !== ($state['round_id'] ?? null)) {
                return $this->failure('La ronda cambió; sincroniza la partida.');
            }
            $players = $room->players()->get();
            if (! isset($state['players'][$playerId]) || ! $players->contains('player_id', $playerId)) {
                return $this->failure('No participaste en esta partida.');
            }
            $rules = $room->rules_snapshot ?? [];
            if (! ($rules['allow_rematch'] ?? true)) {
                return $this->failure('La revancha está deshabilitada.');
            }
            $winner = $state['winner_id'] ?? null;
            if ($winner === $playerId && ($rules['rematch_losers_only'] ?? true)) {
                return $this->failure('Solo los jugadores derrotados pueden solicitar revancha.');
            }
            if ($winner === null && ! ($rules['rematch_on_draw'] ?? true)) {
                return $this->failure('La nueva partida en empate está deshabilitada.');
            }
            if (($rules['max_consecutive_rematches'] ?? 0) > 0 && $room->rematch_count >= $rules['max_consecutive_rematches']) {
                return $this->failure('Se alcanzó el límite de revanchas de esta sala.');
            }
            $this->expireVote($room);
            if (($room->rematch_request['status'] ?? null) === 'pending') {
                return $this->failure('Ya existe una solicitud pendiente.');
            }
            $required = $players->pluck('player_id')->all();
            if (count($required) < ($rules['min_players'] ?? 2) || $players->contains(fn (RoomPlayer $player): bool => ! $player->last_seen_at || $player->last_seen_at->lt(now()->subSeconds(30)))) {
                return $this->failure('Todos los participantes deben estar conectados y cumplir el mínimo de jugadores.');
            }
            $vote = [
                'id' => (string) Str::uuid(),
                'round_id' => $roundId,
                'status' => 'pending',
                'requested_by' => $playerId,
                'required' => $required,
                'accepted' => [$playerId],
                'expires_at' => now()->addSeconds($rules['rematch_response_seconds'] ?? 30)->timestamp,
            ];
            $room->update(['rematch_request' => $vote, 'last_activity_at' => now()]);
            $this->notify($room, 'rematch.requested', ['rematch' => $vote]);

            return ['success' => true, 'rematch' => $vote];
        });
    }

    public function respond(string $code, string $playerId, string $requestId, string $roundId, bool $accept): array
    {
        return $this->locked($code, function (Room $room) use ($playerId, $requestId, $roundId, $accept): array {
            $this->expireVote($room);
            $vote = $room->rematch_request;
            if ($room->status !== Room::STATE_FINISHED || ! $vote || $vote['status'] !== 'pending' || $vote['id'] !== $requestId || $vote['round_id'] !== $roundId) {
                return $this->failure('La solicitud ya no está pendiente.');
            }
            if (! in_array($playerId, $vote['required'], true) || ! $room->players()->where('player_id', $playerId)->exists()) {
                return $this->failure('No perteneces a esta votación.');
            }
            $present = $room->players()->whereIn('player_id', $vote['required'])->where('last_seen_at', '>=', now()->subSeconds(30))->count();
            if ($present !== count($vote['required'])) {
                $this->cancelVote($room, 'Un participante se desconectó.');

                return $this->failure('Un participante se desconectó.');
            }
            if (! $accept) {
                $vote['status'] = 'rejected';
                $vote['rejected_by'] = $playerId;
                $room->update(['rematch_request' => $vote]);
                $this->notify($room, 'rematch.rejected', ['rematch' => $vote]);

                return ['success' => true, 'rematch' => $vote];
            }
            if (in_array($playerId, $vote['accepted'], true)) {
                return ['success' => true, 'rematch' => $vote, 'duplicate' => true];
            }
            $vote['accepted'] = array_values(array_unique([...$vote['accepted'], $playerId]));
            $approved = count($vote['accepted']) === count($vote['required']);
            $vote['status'] = $approved ? 'approved' : 'pending';
            $room->update(['rematch_request' => $vote, 'last_activity_at' => now()]);
            $this->notify($room, 'rematch.accepted', ['rematch' => $vote]);
            if ($approved) {
                $result = $this->rooms->startConfirmedRematch($room->code, $vote['requested_by'], $roundId);
                if (! $result['success']) {
                    $vote['status'] = 'cancelled';
                    $room->update(['rematch_request' => $vote]);
                    $this->notify($room, 'rematch.cancelled', ['rematch' => $vote]);
                }

                return $result;
            }

            return ['success' => true, 'rematch' => $vote];
        });
    }

    public function expireVote(Room $room): void
    {
        if (($room->rematch_request['status'] ?? null) !== 'pending') {
            return;
        }
        $vote = $room->rematch_request;
        $connected = $room->players()->whereIn('player_id', $vote['required'])->where('last_seen_at', '>=', now()->subSeconds(30))->count();
        if ($vote['expires_at'] <= now()->timestamp || $connected !== count($vote['required'])) {
            $this->cancelVote($room, 'Venció la solicitud o un participante se desconectó.');
        }
    }

    private function cancelVote(Room $room, string $reason): void
    {
        if (($room->rematch_request['status'] ?? null) === 'pending') {
            $vote = [...$room->rematch_request, 'status' => 'cancelled', 'reason' => $reason];
            $room->update(['rematch_request' => $vote]);
            $this->notify($room, 'rematch.cancelled', ['rematch' => $vote]);
        }
    }

    public function sweep(): void
    {
        foreach (Room::whereIn('status', [Room::STATE_WAITING, Room::STATE_READY, Room::STATE_FINISHED])->get() as $candidate) {
            $this->locked($candidate->code, function (Room $room): array {
                $this->expireVote($room);
                if (in_array($room->status, [Room::STATE_WAITING, Room::STATE_READY], true)
                    && $room->players()->where('last_seen_at', '>=', now()->subSeconds(30))->exists()) {
                    $inactive = $room->players()->where('last_seen_at', '<', now()->subSeconds(30))->pluck('player_id')->all();
                    if ($inactive !== []) {
                        $room->players()->whereIn('player_id', $inactive)->delete();
                        $this->afterDeparture($room);
                        $this->notify($room, 'room.player_left', ['player_ids' => $inactive, 'reason' => 'presence_timeout']);
                        $this->changed($room, 'room.player_left');
                    }
                }
                $expiry = $room->rules_snapshot['room_idle_seconds'] ?? 300;
                if ($room->last_activity_at?->lt(now()->subSeconds($expiry))) {
                    $room->players()->delete();
                    $this->cancelVote($room, 'Sala inactiva.');
                    $this->afterDeparture($room);
                    $this->notify($room, 'room.closed');
                    $this->changed($room, 'room.closed');
                }

                return ['success' => true];
            });
        }
    }

    private function afterDeparture(Room $room): void
    {
        $players = $room->players()->orderBy('slot_index')->get();
        if ($players->isEmpty()) {
            $room->update(['status' => Room::STATE_CANCELLED, 'last_activity_at' => now()]);
        } elseif (! $players->contains('player_id', $room->host_player_id)) {
            $host = $players->first();
            $host->update(['is_host' => true, 'is_ready' => true]);
            $room->update(['host_player_id' => $host->player_id, 'last_activity_at' => now()]);
        } else {
            $room->update(['last_activity_at' => now()]);
        }
    }

    private function locked(string $code, callable $operation): array
    {
        try {
            return ArenaStateStore::cache()->lock('arena_lock_'.strtoupper($code), 10)->block(3, function () use ($code, $operation): array {
                $room = Room::where('code', strtoupper($code))->first();

                return $room ? $operation($room) : $this->failure('Sala no encontrada.');
            });
        } catch (LockTimeoutException) {
            return $this->failure('Sala ocupada; intenta nuevamente.');
        }
    }

    private function failure(string $message): array
    {
        return ['success' => false, 'error' => $message];
    }
}
