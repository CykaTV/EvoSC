<?php

namespace esc\controllers;


use esc\classes\Hook;
use esc\classes\Log;
use esc\models\Map;
use esc\models\Player;
use Illuminate\Database\Eloquent\Collection;

class HookController
{
    private static $hooks;

    private static $eventMap = [
        'ManiaPlanet.PlayerConnect' => 'PlayerConnect',
        'ManiaPlanet.PlayerDisconnect' => 'PlayerDisconnect',
        'ManiaPlanet.PlayerInfoChanged' => 'ManiaPlanet.PlayerInfoChanged',
        'ManiaPlanet.PlayerChat' => 'PlayerChat',
        'ManiaPlanet.BeginMap' => 'BeginMap',
        'ManiaPlanet.EndMap' => 'EndMap',
        'TrackMania.PlayerCheckpoint' => 'PlayerCheckpoint',
        'TrackMania.PlayerFinish' => 'PlayerFinish',
        'TrackMania.PlayerIncoherence' => 'PlayerIncoherence',
    ];

    public static function initialize()
    {
        self::$hooks = new Collection();
    }

    private static function getHooks(): ?Collection
    {
        return self::$hooks;
    }

    public static function add(string $event, string $staticFunction)
    {
        $hooks = self::getHooks();
        $hook = new Hook($event, $staticFunction);

        if ($hooks) {
            self::getHooks()->add($hook);
            Log::hook("Added $event -> $staticFunction");
        }
    }

    private static function fireHookBatch($hooks, ...$arguments)
    {
        foreach ($hooks as $hook) {
            $hook->execute(...$arguments);
        }
    }

    private static function fire(string $hook, $arguments = null)
    {
        if($hook == 'ManiaPlanet.PlayerInfoChanged'){
            PlayerController::playerInfoChanged($arguments);
            $hook = 'PlayerInfoChanged';
        }

//        echo "Hook called: $hook\n";

        $hooks = self::getHooks()->filter(function ($value, $key) use ($hook) {
            return $value->getEvent() == $hook;
        });

        switch ($hook) {
            case 'BeginMap':
                //SMapInfo Map
                echo "New map: " . $arguments[0]['UId'] . "\n";
                var_dump($arguments);
//                $map = Map::findOrFail($arguments['Uid']);
//                self::fireHookBatch($hooks, $map);
                break;

            case 'EndMap':
                //SMapInfo Map
                echo "Map ended: " . $arguments[0]['UId'] . "\n";
                $map = Map::where('FileName', $arguments[0]['FileName'])->first();
                self::fireHookBatch($hooks, $map);
                break;

            case 'PlayerInfoChanged':
                //SPlayerInfo PlayerInfo
                $players = new Collection();
                foreach($arguments as $playerInfo){
                    $players->add(PlayerController::getPlayerByLogin($playerInfo['Login']));
                }
                self::fireHookBatch($hooks, $players);
                break;

            case 'PlayerConnect':
                //string Login, bool IsSpectator
                $player = Player::find($arguments[0]);
                if($player){
                    $player->spectator = $arguments[1];
                    self::fireHookBatch($hooks, $player);
                }
                break;

            case 'PlayerDisconnect':
                //string Login, string DisconnectionReason
                $player = PlayerController::getPlayerByLogin($arguments[0]);
                self::fireHookBatch($hooks, $player, $arguments[1]);
                break;

            case 'PlayerChat':
                //int PlayerUid, string Login, string Text, bool IsRegistredCmd
                $player = PlayerController::getPlayerByLogin($arguments[1]);
                self::fireHookBatch($hooks, $player, $arguments[2], $arguments[3]);
                break;

            case 'PlayerCheckpoint':
                //int PlayerUid, string Login, int TimeOrScore, int CurLap, int CheckpointIndex
                $player = PlayerController::getPlayerByLogin($arguments[1]);
                self::fireHookBatch($hooks, $player, $arguments[2], $arguments[3], $arguments[4]);
                break;

            case 'PlayerFinish':
                //int PlayerUid, string Login, int TimeOrScore
                $player = PlayerController::getPlayerByLogin($arguments[1]);
                if($player == null){
                    $player = Player::find($arguments[1]);
                }
                self::fireHookBatch($hooks, $player, $arguments[2]);
                break;

            case 'PlayerIncoherence':
                //int PlayerUid, string Login
                $player = PlayerController::getPlayerByLogin($arguments['Login']);
                self::fireHookBatch($hooks, $player);
                break;
        }
    }

    public static function call($event, $arguments = null)
    {
        echo "Event called: $event\n";

        if($event == 'ManiaPlanet.ModeScriptCallbackArray'){
//            var_dump($arguments);
            return;
        }

        if (array_key_exists($event, self::$eventMap)) {
            $hook = self::$eventMap[$event];
            self::fire($hook, $arguments);
        }
    }

    public static function handleCallbacks($callbacks)
    {
        foreach ($callbacks as $callback) {
            self::call($callback[0], $callback[1]);
        }
    }
}