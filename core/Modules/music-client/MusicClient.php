<?php

namespace esc\Modules;

use esc\Classes\ChatCommand;
use esc\Classes\Hook;
use esc\Classes\Log;
use esc\Classes\Module;
use esc\Classes\RestClient;
use esc\Classes\Server;
use esc\Classes\Template;
use esc\Interfaces\ModuleInterface;
use esc\Models\Player;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Collection;
use stdClass;

class MusicClient extends Module implements ModuleInterface
{
    /**
     * @var Collection
     */
    private static $music;

    /**
     * @var stdClass
     */
    private static stdClass $song;

    /**
     * Called when the module is loaded
     *
     * @param  string  $mode
     * @param  bool  $isBoot
     */
    public static function start(string $mode, bool $isBoot = false)
    {
        $url = config('music.url');

        if (!$url) {
            self::enableMusicDisabledNotice();

            return;
        }

        try {
            $timeout = 10;
            Log::write('Loading music library ('.$timeout.'s timeout).');

            $response = RestClient::get($url, [
                'connect_timeout' => $timeout
            ]);
        } catch (GuzzleException $e) {
            Log::error('Failed to fetch music list from '.$url);
            self::enableMusicDisabledNotice();

            return;
        }

        if ($response->getStatusCode() != 200) {
            Log::write('Failed to fetch music list from '.$url);
            self::enableMusicDisabledNotice();

            return;
        }

        $musicJson = $response->getBody()->getContents();
        self::$music = collect(json_decode($musicJson));

        Hook::add('PlayerConnect', [self::class, 'playerConnect']);
        Hook::add('BeginMap', [self::class, 'setNextSong']);

        ChatCommand::add('/music', [self::class, 'searchMusic'], 'Open and search the music list.');

        InputSetup::add('reload_music_client', 'Reload music.', [self::class, 'reload'], 'F2', 'ms');
    }

    private static function enableMusicDisabledNotice()
    {
        Hook::add('PlayerConnect', function (Player $player) {
            warningMessage('Music server not reachable, custom music is disabled.')->send($player);
        });
    }

    public static function searchMusic(Player $player, string $search = '')
    {
        Template::show($player, 'music-client.search-command', compact('search'), false, 20);
    }

    public static function setNextSong()
    {
        self::$song = self::$music->random(1)->first();
        Server::setForcedMusic(true, config('music.url').'?song='.urlencode(self::$song->file));

        if (self::$song) {
            Template::showAll('music-client.start-song', ['song' => json_encode(self::$song)], 60);
        }
    }

    public static function sendMusicLib(Player $player)
    {
        $server = config('music.url');
        $chunks = self::$music->chunk(200);

        Template::show($player, 'music-client.send-music', [
            'server' => $server,
            'music' => $chunks,
        ], false, 2);
    }

    public static function showMusicList(Player $player)
    {
        Template::show($player, 'music-client.list');
    }

    /**
     * Hook: PlayerConnect
     *
     * @param  Player  $player
     */
    public static function playerConnect(Player $player)
    {
        self::sendMusicLib($player);
        self::showMusicList($player);
        Template::show($player, 'music-client.music-client');

        $url = Server::getForcedMusic()->url;

        if ($url) {
            $file = urldecode(preg_replace('/.+\?song=/', '', Server::getForcedMusic()->url));
            $song = json_encode(self::$music->where('file', $file)->first());
        } else {
            $song = json_encode(self::$song);
        }

        if ($song != 'null') {
            Template::show($player, 'music-client.start-song', compact('song'), false, 180);
        }
    }
}