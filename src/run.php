<?php

namespace Erikas\DiscordServiceMonitor;

include __DIR__ . '/../vendor/autoload.php';

use Carbon\Carbon;
use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Parts\Channel\Message;
use Discord\Parts\Guild\Guild;
use Discord\Parts\Interactions\Command\Choice;
use Discord\Parts\Interactions\Command\Command;
use Discord\Parts\Interactions\Command\Option;
use Discord\Parts\Interactions\Interaction;
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

$db = new Medoo([
    'type'      => 'mysql',
    'host'      => $_ENV['DB_HOST'],
    'database'  => $_ENV['DB_NAME'],
    'username'  => $_ENV['DB_USER'],
    'password'  => $_ENV['DB_PASS'],
]);

$botInstance->on('ready', function (Discord $discord) use ($db) {
    $discord->getLogger()->info("Bot starting...");

    /**
     * Variables definitions
     */
    $timeSinceLastPingInSeconds = 0;

    $discord->on("{$_ENV['BOT_ID']}_START_OPERATIONS", function (Medoo $database, Discord $discord) {
        $discord->getLogger()->info("STARTING OPERATIONS INIT");

        /**@var Guild $guild*/
        foreach ($discord->guilds as $guild) {

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
                /**
                 * If guild has been deleted we don't interact with it, ever
                 */
                if ($database->has("guilds", ["guild_id" => $guild->id, "deleted_at[!]" => null])) {
                    return;
                }

                $database->update(
                    "guilds",
                    [
                        "name"          => $guild->name,
                        "updated_at"    => Carbon::now($_ENV['BOT_TIMEZONE'])->format("Y-m-d H:i:s"),
                    ],
                    [
                        "guild_id" => $guild->id
                    ]
                );
            }

            $dbGuild = $database->get('guilds', ['id', 'guild_id'], ['guild_id' => $guild->id]);
            if (!$database->has('configurations', ["guild_id" => $dbGuild['id'], "keyword" => "commands_registered"])) {
                $discord->getLogger()->info("REGISTERING COMMANDS FOR: {$guild->name}");

                /**
                 * Register commands
                 */
                $command = new Command($discord, [
                    "name" => "monitor",
                    "description" => "Run a service monitor command",
                    "options" => [
                        (new Option($discord))
                            ->setType(1)
                            ->setDescription('Refresh monitored servers')
                            ->setName('refresh'),

                        (new Option($discord))
                            ->setType(1)
                            ->setDescription('Add server to monitor')
                            ->setName('add')
                            ->addOption((new Option($discord))->setName("name")->setDescription("What should the server name be?")->setType(3)->setRequired(true)->setMinLength(1))
                            ->addOption((new Option($discord))->setName("address")->setDescription("Server's IP:Port")->setType(3)->setRequired(true)->setMinLength(9))
                            ->addOption((new Option($discord))->setName("protocol")->setDescription("Server's tansport protocol (UDP/TCP)")->setType(3)->setRequired(true)
                                ->addChoice((new Choice($discord))->setName("TCP")->setValue("tcp"))
                                ->addChoice((new Choice($discord))->setName("UDP")->setValue("udp"))),

                        (new Option($discord))
                            ->setType(1)
                            ->setDescription('Remove monitored server')
                            ->setName('remove')
                            ->addOption((new Option($discord))->setName("name")->setDescription("What's the name of the server getting 💀'd?")->setType(3)->setRequired(true)->setMinLength(1)),

                        (new Option($discord))
                            ->setType(1)
                            ->setDescription('Wipe all server monitor data')
                            ->setName('wipe')
                            ->addOption((new Option($discord))->setName("confirmation")->setDescription("Enter this Discord server's name to confirm.")->setType(3)->setRequired(true)->setMinLength(1)),

                        (new Option($discord))
                            ->setType(1)
                            ->setDescription('About Service Monitor')
                            ->setName('about')
                    ]
                ]);

                $guild->commands->save($command);

                $database->insert('configurations', [
                    'guild_id' => $dbGuild['id'],
                    'keyword' => "commands_registered",
                    "type" => "string",
                    "value" => "true"
                ]);
            }
        }
    });

    $discord->on("{$_ENV['BOT_ID']}_UPDATE_ACTIVITY", function (int $activity, int $guildCount, Discord $discord) {
        $messageText = "";

        if ($guildCount > 1) {
            $messageText .= "$guildCount Discord servers!";
        } else {
            $messageText .= "$guildCount Discord server!";
        }

        $statusPresence = new Activity($discord, [
            "type" => $activity,
            "name" => $messageText
        ]);

        $discord->updatePresence($statusPresence);
    });

    $discord->on("{$_ENV['BOT_ID']}_UPDATE_GUILD_SERVERS", function (Guild $guild, Medoo $database, Discord $discord) {
        /**
         * If guild ID is in skip array or is marked as deleted - return.
         */
        if (in_array($guild->id, explode(" ", $_ENV['SKIP_GUILD_IDS'])) || $database->has("guilds", ["guild_id" => $guild->id, "deleted_at[!]" => null])) {
            return;
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

            $discord->getLogger()->info("TRYING TO PING: {$addressParts[0]}:{$addressParts[1]}");

            if (@fsockopen($addressParts[0], $addressParts[1], $errorCode, $errorString, 3)) {
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

    $discord->listenCommand("monitor", function (Interaction $interaction) use ($discord, $db) {

        $discord->getLogger()->debug("GOT MONITOR EVENT FROM: {$interaction->guild->name}");
        $commandName = $interaction->data->options->first()->name;

        switch ($commandName) {
            case "add":
                $optionContainer = $interaction->data->options->first()->options;
                $severName = $optionContainer["name"]->value;
                $severAddress = $optionContainer["address"]->value;
                $severProtocol = $optionContainer["protocol"]->value;

                if (preg_match('/[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}:[0-9]{1,4}/', $severAddress, $matches) != 1) {
                    $interaction->respondWithMessage(MessageBuilder::new()->setContent("Invalid address format, IP:Port is required, ex.: 1.1.1.1:1000."), true);
                    return;
                }

                $serverDBGuild = $db->get("guilds", ["id", "guild_id"], ["guild_id" => $interaction->guild_id]);

                if ($db->has("servers", ["guild_id" => $serverDBGuild["id"], "name" => $severName, "deleted_at" => null])) {
                    $interaction->respondWithMessage(MessageBuilder::new()->setContent("Server with that name already exists, try again with a different name!"), true);
                } else {
                    $db->insert("servers", [
                        "guild_id"      => $serverDBGuild["id"],
                        "name"          => $severName,
                        "address"       => $severAddress,
                        "protocol"      => $severProtocol,
                        "created_at"    => Carbon::now($_ENV["BOT_TIMEZONE"])->format("Y-m-d H:i:s"),
                        "updated_at"    => Carbon::now($_ENV["BOT_TIMEZONE"])->format("Y-m-d H:i:s"),
                    ]);

                    $interaction->respondWithMessage(MessageBuilder::new()->setContent("Server added! Run the refresh command or wait for the periodic re-ping to see changes."), true);
                }
                break;
            case "remove":
                $severName = $interaction->data->options->first()->options["name"]->value;

                $serverDBGuild = $db->get("guilds", ["id", "guild_id"], ["guild_id" => $interaction->guild_id]);

                if (!$db->has("servers", ["guild_id" => $serverDBGuild["id"], "name" => $severName, "deleted_at" => null])) {
                    $interaction->respondWithMessage(MessageBuilder::new()->setContent("Server with that name doesn't exist, please check for incorrect input and try again!"), true);
                } else {
                    $db->update(
                        "servers",
                        [
                            "updated_at"    => Carbon::now($_ENV["BOT_TIMEZONE"])->format("Y-m-d H:i:s"),
                            "deleted_at"    => Carbon::now($_ENV["BOT_TIMEZONE"])->format("Y-m-d H:i:s"),
                        ],
                        [
                            "guild_id" => $serverDBGuild["id"],
                            "name" => $severName,
                            "deleted_at" => null
                        ]
                    );

                    $interaction->respondWithMessage(MessageBuilder::new()->setContent("Server removed! Run the refresh command or wait for the periodic re-ping to see changes."), true);
                }
                break;
            case "refresh":
                $interaction->respondWithMessage(MessageBuilder::new()->setContent("Monitored server refresh requested!"), true)->then(function ($resolve) use ($discord, $db, $interaction) {
                    $discord->getLogger()->debug("{$_ENV["BOT_ID"]}_UPDATE_GUILD_SERVERS FOR {$interaction->guild->name}");
                    $discord->emit("{$_ENV["BOT_ID"]}_UPDATE_GUILD_SERVERS", [$interaction->guild, $db, $discord]);
                });
                break;
            case "about":
                $interaction->respondWithMessage(MessageBuilder::new()->setContent("Server Monitor Bot, version: **{$_ENV["BOT_VERSION"]}**. Author: [byErikas](https://github.com/byErikas/Discord-ServiceMonitor)"), true);
                break;
            case "wipe":
                $guildName = $interaction->data->options->first()->options["confirmation"]->value;

                if ($interaction->guild->name == $guildName) {
                    $db->update(
                        "servers",
                        [
                            "updated_at" => Carbon::now($_ENV["BOT_TIMEZONE"])->format("Y-m-d H:i:s"),
                            "deleted_at" => Carbon::now($_ENV["BOT_TIMEZONE"])->format("Y-m-d H:i:s")
                        ],
                        [
                            "guild_id" => $interaction->guild_id
                        ]
                    );

                    $dbGuildId = $db->get("guilds", "id", ["guild_id" => $interaction->guild_id]);

                    /**
                     * Delete channel message
                     */
                    $dbMessageId = $db->get("configurations", "value", ["guild_id" => $interaction->guild_id, "keyword" => "message_id"]);
                    $interaction->channel->messages->delete($interaction->channel->messages->fetch($dbMessageId));

                    /**
                     * Wipe configs
                     */
                    $db->delete("configurations", ["guild_id" => $dbGuildId]);

                    $interaction->respondWithMessage(MessageBuilder::new()->setContent("Server Monitor configuration wiped."), true);
                }
                break;
            default:
                $interaction->respondWithMessage(MessageBuilder::new()->setContent("Unknown command, sorry!"), true);
                break;
        }
    });

    $discord->getLogger()->info("Event hooks defined!");
    $discord->emit("{$_ENV['BOT_ID']}_START_OPERATIONS", [$db, $discord]);
    $discord->emit("{$_ENV['BOT_ID']}_UPDATE_ACTIVITY", [Activity::TYPE_WATCHING, $discord->guilds->count(), $discord]);
});

$botInstance->run();
