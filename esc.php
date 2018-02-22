<?php

use esc\classes\Config;
use esc\classes\Log;
use esc\classes\RestClient;
use esc\classes\Timer;
use esc\controllers\ChatController;
use esc\controllers\CountdownController;
use esc\controllers\GroupController;
use esc\controllers\HookController;
use esc\controllers\MapController;
use esc\controllers\ModuleController;
use esc\controllers\PlayerController;
use esc\controllers\ServerController;
use esc\controllers\TemplateController;

include 'core/autoload.php';
include 'vendor/autoload.php';

$connectionFailed = 0;

while (true) {
    try {
        Log::info("Starting...");

        function formatScore(int $score): string
        {
            return Timer::formatScore($score);
        }

        function stripColors(string $colored): string
        {
            return preg_replace('/\$[0-9a-f]{3}/', '', $colored);
        }

        function stripStyle(string $styled): string
        {
            return preg_replace('/(?:\$[0-9a-f]{3}|\$l\[.+\)?)/', '', $styled);
        }

        function config(string $id, $default = null)
        {
            return Config::get($id) ?: $default;
        }

        Log::info("Loading config files.");
        Config::loadConfigFiles();

        HookController::initialize();

        TemplateController::init();
        \esc\classes\ManiaLinkEvent::init();

        \esc\classes\Database::initialize();

        try {
            Log::info("Connecting to server...");

            ServerController::initialize(Config::get('server.ip'), Config::get('server.port'), 5, Config::get('server.rpc.login'), Config::get('server.rpc.password'));

            Log::info("Connection established.");
        } catch (\Exception $e) {
            Log::error("Connection to server failed.");
            $connectionFailed++;
            throw new Exception("Connection to server failed.");
        }

        ChatController::initialize();
        GroupController::init();
        MapController::initialize();
        RestClient::initialize();
        RestClient::$serverName = 'Brakers dev server LOGIN brakertest2';

        ModuleController::loadModules('core/Modules');
        ModuleController::loadModules('modules');

        PlayerController::initialize();

        $map = \esc\models\Map::where('FileName', ServerController::getRpc()->getCurrentMapInfo()->fileName)->first();
        if ($map) {
            MapController::beginMap($map);
        }

        foreach (ServerController::getRpc()->getPlayerList() as $player) {
            $ply = \esc\models\Player::firstOrCreate(['Login' => $player->login]);
            $ply->update($player->toArray());

            if ($ply) {
                PlayerController::playerConnect($ply);
            }
        }

        LocalRecords::displayLocalRecords();
//        Dedimania::beginMap(\esc\controllers\MapController::getCurrentMap());
        MusicServer::displayCurrentSong();
        CountdownController::init();

        while (true) {
            Timer::startCycle();

            HookController::handleCallbacks(ServerController::executeCallbacks());

            usleep(Timer::getNextCyclePause());
        }
    }catch (PDOException $pdoe) {
        Log::error("Connection to database failed. Please make sure your MySQL server is running.");
        exit(1);
    } catch (\Exception $e) {
        echo "FATAL ERROR RESTARTING: $e\n";
        continue;
    }
}