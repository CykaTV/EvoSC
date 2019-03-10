<?php

namespace esc\Modules;


use esc\Classes\Hook;
use esc\Classes\Log;
use esc\Classes\ManiaLinkEvent;
use esc\Classes\Template;
use esc\Models\Player;

class KeyBinds
{
    /**
     * @var \Illuminate\Support\Collection
     */
    private static $binds;

    public function __construct()
    {
        Hook::add('PlayerConnect', [self::class, 'sendScript']);

        ManiaLinkEvent::add('bound_key_pressed', [self::class, 'keyPressed']);
    }

    /**
     * Add a new key-bind
     *
     * @param string      $id
     * @param string      $description
     * @param             $callback
     * @param string      $defaultKey
     * @param string|null $access
     */
    public static function add(string $id, string $description, $callback, string $defaultKey, string $access = null)
    {
        if (!self::$binds) {
            self::$binds = collect();
        }

        self::$binds->push([
            'id'          => $id,
            'description' => $description,
            'callback'    => $callback,
            'default'     => $defaultKey,
            'access'      => $access,
        ]);
    }

    /**
     * Handle bound key presses
     *
     * @param \esc\Models\Player $player
     * @param string             $id
     */
    public static function keyPressed(Player $player, string $id)
    {
        self::$binds->where('id', $id)->each(function ($bind) use ($player) {
            if (gettype($bind['callback']) == "object") {
                $func = $bind['callback'];
                $func($player);
            } else {
                if (is_callable($bind['callback'], false, $callableName)) {
                    call_user_func($bind['callback'], $player);
                    Log::logAddLine('Hook', "Execute: " . $bind['callback'][0] . " " . $bind['callback'][1], isVeryVerbose());
                } else {
                    throw new \Exception("KeyBind callback invalid, must use: [ClassName, ClassFunctionName] or Closure");
                }
            }
        });
    }

    /**
     * Send the key-bind script to the player
     *
     * @param \esc\Models\Player $player
     */
    public static function sendScript(Player $player)
    {
        $binds = self::$binds->map(function ($bind) use ($player) {
            if ($bind['access'] && !$player->hasAccess($bind['access'])) {
                return null;
            }

            return [
                'id'          => $bind['id'],
                'description' => $bind['description'],
                'default'     => strtolower($bind['default']),
            ];
        })->filter()->toJson();

        Template::show($player, 'key-binds.script', compact('binds'));
    }
}