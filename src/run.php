<?php

namespace Erikas\DiscordServiceMonitor;

include __DIR__ . '/../vendor/autoload.php';

use Carbon\Carbon;
use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Parts\Channel\Message;
use Discord\Parts\Guild\Guild;
use Dotenv\Dotenv;
use Discord\Parts\User\Activity;
use Medoo\Medoo;
use Erikas\DiscordServiceMonitor\Assets\Server;

/**
 * Load .env
 */
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

/**
 * Start bot instance
 */
$botInstance = new Discord([
    'token' => $_ENV['BOT_TOKEN'],
]);

$botInstance->on('ready', function (Discord $discord) {
    /**
     * Startup sequence.
     */
    echo "Bot starting...", PHP_EOL;
    $timeSinceLastPingInSeconds = 0;

    $db = new Medoo([
        'type'      => 'mysql',
        'host'      => $_ENV['DB_HOST'],
        'database'  => $_ENV['DB_NAME'],
        'username'  => $_ENV['DB_USER'],
        'password'  => $_ENV['DB_PASS'],
    ]);

    $discord->updatePresence(new Activity($discord, [
        'name' =>  "{$discord->guilds->count()} server/-s!",
        'type' => Activity::TYPE_WATCHING
    ]));

    $discord->on('heartbeat-ack', function ($time, Discord $discord) use (&$timeSinceLastPingInSeconds, $db) {
        $timeSinceLastPingInSeconds += $time;
        $discord->getLogger()->info("Time since last ping: " . floor($timeSinceLastPingInSeconds) . "s., left until ping: " . $_ENV['PING_EVERY_SECONDS'] - floor($timeSinceLastPingInSeconds) . "s.");

        if ($timeSinceLastPingInSeconds >= $_ENV['PING_EVERY_SECONDS']) {
            $discord->getLogger()->info("Pinging servers...");

            /**@var \Discord\Parts\Guild\Guild $guild*/
            foreach ($discord->guilds as $guild) {
                $discord->getLogger()->info("{$_ENV['BOT_ID']}_UPDATE_GUILD_SERVERS: {$guild->name}");
                $discord->emit("{$_ENV['BOT_ID']}_UPDATE_GUILD_SERVERS", [$guild, $db, $discord]);
            }

            /**
             * Reset timer on ping function
             */
            $timeSinceLastPingInSeconds = 0;
        }
    });

    $discord->on("{$_ENV['BOT_ID']}_UPDATE_GUILD_SERVERS", function (Guild $guild, Medoo $database, Discord $discord) {
        /**
         * If guild ID is in skip array - continue array.
         */
        if (in_array($guild->id, explode(" ", $_ENV['SKIP_GUILD_IDS']))) {
            return;
        }

        /**
         * Check if guild ID is already in local DB, update or create it
         */
        if (!$database->has("guilds", ["guild_id" => $guild->id])) {
            $database->insert("guilds", [
                "name"          => $guild->name,
                "guild_id"      => $guild->id,
                "created_at"    => Carbon::now($_ENV['BOT_TIMEZONE'])->format("Y-m-d H:i:s"),
                "updated_at"    => Carbon::now($_ENV['BOT_TIMEZONE'])->format("Y-m-d H:i:s"),
                "deleted_at"    => null
            ]);
        } else {
            $database->update("guilds", [
                "name"          => $guild->name,
                "updated_at"    => Carbon::now($_ENV['BOT_TIMEZONE'])->format("Y-m-d H:i:s"),
            ]);
        }

        $dbGuildId = $database->get("guilds", "id", ["guild_id" => $guild->id, "deleted_at" => null]);
        $dbServers = $database->select("servers", ["id", "name", "address", "protocol"], ["guild_id" => $dbGuildId, "deleted_at" => null]);

        if (empty($dbServers)) {
            return;
        }

        /**
         * We use a codeblocked message for monospaced characters, for equal padding
         */
        $messageText = "```📅 " . Carbon::now($_ENV['BOT_TIMEZONE'])->format("Y-m-d H:i") . "\n\n";

        /**@var array $server*/
        foreach ($dbServers as $server) {
            $serverClass = new Server($server["name"], $server["address"], false);
            $addressParts = explode(":", $server["address"]);

            if ($server["protocol"] == "udp") {
                $addressParts[0] = "udp://{$addressParts[0]}";
            }

            if (fsockopen($addressParts[0], $addressParts[1], $errorCode, $errorString, 3)) {
                $serverClass->set("online", true);
            } else {
                $serverClass->set("online", false);
            }

            $messageText .= $serverClass->__toString() . "\n\n";
        }

        /**
         * Finish message code block, trim off newlines
         */
        $messageText .= "```";
        $messageText = rtrim($messageText, "\n\n");

        /**
         * Get channel we post in, by DB guild ID 
         */
        $channelId = $database->get("configurations", "value", ["guild_id" => $dbGuildId, "keyword" => "message_channel"]);
        $channel = $discord->getChannel($channelId);

        if (!$database->has("configurations", ["keyword" => "message_id", "guild_id" => $dbGuildId])) {
            $sendMessagePromise = $channel->sendMessage($messageText);
            $sendMessagePromise->done(function (Message $message) use ($discord, $database) {
                $discord->emit("{$_ENV['BOT_ID']}_CREATED_NEW_GUILD_SERVERS_MESSAGE", [$message, $database, $discord]);
                $discord->getLogger()->info("{$_ENV['BOT_ID']}_CREATED_NEW_GUILD_SERVERS_MESSAGE: {$message->id}");
            });
        } else {
            $messageId = $database->get("configurations", "value", ["keyword" => "message_id", "guild_id" => $dbGuildId]);

            $channel->messages->fetch($messageId)->done(function (Message $message) use ($messageText) {
                $message->edit(MessageBuilder::new()
                    ->setContent($messageText));
            });
        }
    });

    $discord->on("{$_ENV['BOT_ID']}_CREATED_NEW_GUILD_SERVERS_MESSAGE", function (Message $message, Medoo $db, Discord $discord) {
        if ($message->author->id != $_ENV["BOT_ID"]) {
            return;
        }

        if (!$db->has('guilds', ["guild_id" => $message->guild_id])) {
            $db->insert('guilds', [
                "name" => $message->guild->name,
                "guild_id" => $message->guild_id,
                "created_at"    => Carbon::now($_ENV['BOT_TIMEZONE'])->format("Y-m-d H:i:s"),
                "updated_at"    => Carbon::now($_ENV['BOT_TIMEZONE'])->format("Y-m-d H:i:s"),
                "deleted_at"    => null
            ]);
        }

        $dbGuildId = $db->get('guilds', 'id', ['guild_id' => $message->guild_id]);

        if (!$db->has("configurations", ["keyword" => "message_id", "guild_id" => $dbGuildId])) {
            $db->insert(
                "configurations",
                [
                    "guild_id" => $dbGuildId,
                    "keyword" => "message_id",
                    "type" => "string",
                    "value" => $message->id
                ]
            );
        }
    });
});

$botInstance->run();
