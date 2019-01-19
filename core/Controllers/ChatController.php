<?php

namespace esc\Controllers;


use esc\Classes\ChatCommand;
use esc\Classes\Hook;
use esc\Classes\Log;
use esc\Classes\Module;
use esc\Classes\Server;
use esc\Interfaces\ControllerInterface;
use esc\Models\AccessRight;
use esc\Models\Dedi;
use esc\Models\Group;
use esc\Models\LocalRecord;
use esc\Models\Map;
use esc\Models\Player;
use esc\Models\Song;
use Illuminate\Support\Collection;
use Maniaplanet\DedicatedServer\Xmlrpc\FaultException;

class ChatController implements ControllerInterface
{
    /* maniaplanet chat styling
$i: italic
$s: shadowed
$w: wide
$n: narrow
$m: normal
$g: default color
$o: bold
$z: reset all
$t: Changes the text to capitals
$$: Writes a dollarsign
     */

    private static $chatCommands;
    private static $chatCommandsCompiled;
    private static $mutedPlayers;

    public static function init()
    {
        self::$chatCommands = collect();
        self::$mutedPlayers = collect();

        try {
            Server::call('ChatEnableManualRouting', [true, false]);
        } catch (FaultException $e) {
            $msg = $e->getMessage();
            Log::getOutput()->writeln("<error>$msg There might already be a running instance.</error>");
            exit(2);
        }

        Hook::add('PlayerChat', [ChatController::class, 'playerChat']);

        AccessRight::createIfNonExistent('player_mute', 'Mute/unmute player.');

        ChatCommand::add('mute', [ChatController::class, 'mute'], 'Mutes a player by given nickname', '//', 'player_mute');
        ChatCommand::add('unmute', [ChatController::class, 'unmute'], 'Unmute a player by given nickname', '//', 'player_mute');
    }

    public static function getChatCommands(): Collection
    {
        return self::$chatCommands;
    }

    public static function mute(Player $player, $cmd, $nick)
    {
        $target = PlayerController::findPlayerByName($player, $nick);

        if (!$target) {
            //No target found
            return;
        }

        $ply     = collect();
        $ply->id = $target->id;

        self::$mutedPlayers = self::$mutedPlayers->push($ply)->unique();
    }

    public static function unmute(Player $player, $cmd, $nick)
    {
        $target = PlayerController::findPlayerByName($player, $nick);

        if (!$target) {
            //No target found
            return;
        }

        self::$mutedPlayers = self::$mutedPlayers->filter(function ($player) use ($target) {
            return $player->id != $target->id;
        });
    }

    public static function playerChat(Player $player, $text)
    {
        if (self::$chatCommandsCompiled->contains(explode(' ', $text)[0])) {
            //chat command detected
            if (self::executeChatCommand($player, $text)) {
                return;
            }
        }

        if (preg_match('/^(\/|\/\/|##)/', $text)) {
            //Catch invalid chat commands
            ChatController::message($player, warning('Invalid chat command entered'));

            return;
        }

        if (self::$mutedPlayers->where('id', $player->id)->isNotEmpty()) {
            //Player is muted
            return;
        }

        Log::logAddLine($player, $text);
        $nick = $player->NickName;

        $parts = explode(" ", $text);

        foreach ($parts as $part) {
            if (preg_match('/https?:\/\/(?:www\.)?youtube\.com\/.+/', $part, $matches)) {
                $url  = $matches[0];
                $info = '$l[' . $url . ']$f44 $ddd' . substr($url, -10) . '$z$s';
                $text = str_replace($url, $info, $text);
            }
        }

        if (preg_match('/([$]+)$/', $text, $matches)) {
            //Escape dollar signs
            $text .= $matches[0];
        }

        if ($player->isSpectator()) {
            $nick = '$eee📷 ' . $nick;
        }

        $prefix = $player->group->chat_prefix;

        if($prefix){
            $chatText = sprintf('%s $z$s%s$z$s: $%s$z$s%s', $prefix, $nick, config('colors.chat'), $text);
        }else{
            $chatText = sprintf('$z$s%s$z$s: $%s$z$s%s', $nick, config('colors.chat'), $text);
        }

        Server::call('ChatSendServerMessage', [$chatText]);
    }

    private static function executeChatCommand(Player $player, string $text): bool
    {
        $isValidCommand = false;

        //treat "this is a string" as single argument
        if (preg_match_all('/\"(.+?)\"/', $text, $matches)) {
            foreach ($matches[1] as $match) {
                //Replace all spaces in quotes to ;:;
                $new  = str_replace(' ', ';:;', $match);
                $text = str_replace("\"$match\"", $new, $text);
            }
        }

        //Split input string in arguments
        $arguments = explode(' ', $text);

        foreach ($arguments as $key => $argument) {
            //Change ;:; back to spaces
            $arguments[$key] = str_replace(';:;', ' ', $argument);
        }

        //Find command
        $command = self::$chatCommands
            ->filter(function (ChatCommand $command) use ($arguments) {
                return strtolower($command->compile()) == strtolower($arguments[0]);
            })
            ->first();

        //Add calling player to beginning of arguments list
        array_unshift($arguments, $player);

        if ($command) {
            //Command exists
            try {
                //Run command callback
//                call_user_func($command->callback, ...$arguments);
                $command->run($arguments);
            } catch (\Exception $e) {
                Log::logAddLine('ChatController', 'Failed to execute chat command: ' . $e->getMessage(), true);
                Log::logAddLine('ChatController', $e->getTraceAsString(), false);
            }
            $isValidCommand = true;
        }

        return $isValidCommand;
    }

    public static function compileChatCommand(ChatCommand $command)
    {
        return $command->compile();
    }

    public static function addCommand(string $command, array $callback, string $description = '-', string $trigger = '/', string $access = null)
    {
        if (!self::$chatCommands) {
            self::$chatCommands         = collect();
            self::$chatCommandsCompiled = collect();
        }

        $chatCommand = new ChatCommand($trigger, $command, $callback, $description, $access);
        self::$chatCommands->push($chatCommand);
        self::$chatCommandsCompiled = self::$chatCommands->map([self::class, 'compileChatCommand']);

        Log::info("Chat command added: " . $chatCommand->compile(), false);
    }

    /**
     * @param \esc\Models\Player|\Illuminate\Support\Collection $recipient
     * @param mixed                                             ...$parts
     */
    public static function message($recipients, ...$parts)
    {
        if ($recipients instanceof Player) {
            self::sendMessage($recipients, ...$parts);
        } else {
            $recipients->each(function (Player $player) use ($parts) {
                ChatController::sendMessage($player, ...$parts);
            });
        }
    }

    /**
     * @param \esc\Models\Player|\Illuminate\Support\Collection $recipient
     * @param mixed                                             ...$parts
     */
    public static function sendMessage(Player $recipient, ...$parts)
    {
        if (!$recipient || !isset($recipient->Login) || $recipient->Login == null) {
            Log::warning('Do not send message to non existent player');

            return;
        }

        $icon       = "";
        $color      = config('colors.primary');
        $groupColor = config('colors.primary');

        if (preg_match('/^_(\w+)$/', $parts[0], $matches)) {
            //set primary color of message
            switch ($matches[1]) {
                case 'secondary':
                    $icon       = "";
                    $color      = config('colors.secondary');
                    $groupColor = config('colors.secondary');
                    array_shift($parts);
                    break;

                case 'info':
                    $icon       = "";
                    $color      = config('colors.info');
                    $groupColor = config('colors.info');
                    array_shift($parts);
                    break;

                case 'warning':
                    $icon       = "";
                    $icon       = "";
                    $color      = config('colors.warning');
                    $groupColor = config('colors.warning');
                    array_shift($parts);
                    break;

                case 'local':
                    $icon       = "";
                    $color      = config('colors.local');
                    $groupColor = config('colors.local');
                    array_shift($parts);
                    break;

                case 'dedi':
                    $icon       = "";
                    $color      = config('colors.dedi');
                    $groupColor = config('colors.dedi');
                    array_shift($parts);
                    break;

                default:
                    if (preg_match('/[0-9a-f]{3}/', $matches[1])) {
                        $color      = $matches[1];
                        $groupColor = $matches[1];
                        array_shift($parts);
                    }
            }
        }

        $message = '$s';

        foreach ($parts as $key => $part) {
            if ($part === null) {
                continue;
            }

            if ($part instanceof Player) {
                if ($key == 0) {
                    $message .= '$s$' . $groupColor . ($part->group->Name) . ' ';
                }
                $message .= secondary($part->NickName);
                continue;
            }

            if ($part instanceof Map) {
                $message .= secondary($part->gbx->Name);
                continue;
            }

            if ($part instanceof Group) {
                $part = ucfirst($part->Name);
            }

            if ($part instanceof Module) {
                $message .= secondary(stripAll($part->name));
                continue;
            }

            if ($part instanceof Song) {
                $message .= secondary($part->title);
                continue;
            }

            if ($part instanceof LocalRecord) {
                $message .= secondary($part->Rank) . '. $z$s$' . $color . 'local record $z$s' . secondary(formatScore($part->Score));
                continue;
            }

            if ($part instanceof Dedi) {
                $message .= secondary($part->Rank) . '. $z$s$' . $color . 'dedimania record $z$s' . secondary(formatScore($part->Score));
                continue;
            }

            if (is_float($part) || is_int($part) || preg_match('/\-?\d+\./', $part)) {
                $message .= secondary($part ?? "0");
                continue;
            }

            if (!is_string($part)) {
                echo "CLASS OF PART: ";
                var_dump(get_class($part));
                var_dump(LocalRecord::class);
            }

            $message .= '$z$s$' . $color . $part;

        }

        if (strlen($icon) > 0) {
            $message = '$fff' . $icon . ' ' . $message;
        }

        try {
            Server::chatSendServerMessage($message, $recipient->Login);

            Log::logAddLine("Chat", "$recipient <- " . $message);
        } catch (\Exception $e) {
            Log::logAddLine('ChatController', 'Failed to send message: ' . $e->getMessage());
            Log::logAddLine('', $e->getTraceAsString(), false);

            return;
        }
    }
}