<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class CharacterCatalog
{
    public const CHARACTERS = [
        'neon-blue' => ['name' => 'Cyber Blue', 'color' => '#38BDF8', 'label' => 'Azul / cian'],
        'neon-pink' => ['name' => 'Neon Red', 'color' => '#FB7185', 'label' => 'Rojo / coral'],
        'neon-green' => ['name' => 'Toxic Green', 'color' => '#34D399', 'label' => 'Verde'],
        'neon-yellow' => ['name' => 'Plasma Yellow', 'color' => '#FBBF24', 'label' => 'Amarillo'],
    ];

    public static function ids(): array
    {
        return array_keys(self::CHARACTERS);
    }

    public static function validate(?string $character): string
    {
        if ($character !== null && ! array_key_exists($character, self::CHARACTERS)) {
            throw ValidationException::withMessages(['character_id' => 'Selecciona un personaje válido.']);
        }

        return $character ?? 'neon-blue';
    }

    public static function decorate(array $state, iterable $players): array
    {
        foreach ($players as $player) {
            $id = $player->player_id;
            if (isset($state['players'][$id])) {
                $character = $player->character_id ?? $player->color;
                $state['players'][$id]['character_id'] = $character;
                $state['players'][$id]['color'] = $character;
            }
        }

        return $state;
    }
}
