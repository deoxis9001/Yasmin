<?php
/**
 * Yasmin
 * Copyright 2017-2019 Charlotte Dunois, All Rights Reserved.
 *
 * Website: https://charuru.moe
 * License: https://github.com/CharlotteDunois/Yasmin/blob/master/LICENSE
 */

namespace CharlotteDunois\Yasmin\Models;

use CharlotteDunois\Yasmin\Client;
use CharlotteDunois\Yasmin\Utils\DataHelpers;
use CharlotteDunois\Yasmin\Utils\FileHelpers;
use CharlotteDunois\Yasmin\WebSocket\WSManager;
use InvalidArgumentException;
use function React\Promise\all;
use React\Promise\ExtendedPromiseInterface;
use React\Promise\Promise;
use function React\Promise\reject;
use RuntimeException;

/**
 * Represents the Client User.
 */
class ClientUser extends User
{
    /**
     * The client's presence.
     *
     * @var array
     * @internal
     */
    protected $clientPresence;

    /**
     * @param  Client  $client
     * @param  array  $user
     *
     * @internal
     */
    public function __construct(Client $client, $user)
    {
        parent::__construct($client, $user);

        $presence = $this->client->getOption('ws.presence', []);
        $this->clientPresence = [
            'afk'    => (isset($presence['afk']) ? ((bool) $presence['afk']) : false),
            'since'  => (isset($presence['since']) ? $presence['since'] : null),
            'status' => (! empty($presence['status']) ? $presence['status'] : 'online'),
            'game'   => (! empty($presence['game']) ? $presence['game'] : null),
        ];
    }

    /**
     * {@inheritdoc}
     * @return mixed
     * @throws RuntimeException
     * @internal
     */
    public function __get($name)
    {
        if (property_exists($this, $name)) {
            return $this->$name;
        }

        return parent::__get($name);
    }

    /**
     * @return mixed
     * @internal
     */
    public function __debugInfo()
    {
        $vars = parent::__debugInfo();
        unset($vars['clientPresence'], $vars['firstPresence'], $vars['firstPresencePromise'], $vars['firstPresenceCount'], $vars['firstPresenceTime']);

        return $vars;
    }

    /**
     * Set your avatar. Resolves with $this.
     *
     * @param  string|null  $avatar  An URL or the filepath or the data. Null resets your avatar.
     *
     * @return ExtendedPromiseInterface
     * @example ../../examples/docs-examples.php 15 4
     */
    public function setAvatar(?string $avatar)
    {
        if ($avatar === null) {
            return $this->client->apimanager()->endpoints->user->modifyCurrentUser(['avatar' => null])->then(
                function () {
                    return $this;
                }
            );
        }

        return new Promise(
            function (callable $resolve, callable $reject) use ($avatar) {
                FileHelpers::resolveFileResolvable($avatar)->done(
                    function ($data) use ($resolve, $reject) {
                        $image = DataHelpers::makeBase64URI($data);

                        $this->client->apimanager()->endpoints->user->modifyCurrentUser(['avatar' => $image])->done(
                            function () use ($resolve) {
                                $resolve($this);
                            },
                            $reject
                        );
                    },
                    $reject
                );
            }
        );
    }

    /**
     * Set your status. Resolves with $this.
     *
     * @param  string  $status  Valid values are: `online`, `idle`, `dnd` and `invisible`.
     *
     * @return ExtendedPromiseInterface
     * @example ../../examples/docs-examples.php 25 2
     */
    public function setStatus(string $status)
    {
        $presence = [
            'status' => $status,
        ];

        return $this->setPresence($presence);
    }

    /**
     * Set your activity. Resolves with $this.
     *
     * @param  Activity|string|null  $name  The activity name.
     * @param  int  $type  Optional if first argument is an Activity. The type of your activity. Should be listening (2) or watching (3). For playing/streaming use ClientUser::setGame.
     * @param  int|null  $shardID  Unless explicitely given, all presences will be fanned out to all shards.
     *
     * @return ExtendedPromiseInterface
     */
    public function setActivity($name, int $type = 0, ?int $shardID = null)
    {
        if ($name === null) {
            return $this->setPresence(
                [
                    'game' => null,
                ],
                $shardID
            );
        } elseif ($name instanceof Activity) {
            return $this->setPresence(
                [
                    'game' => $name->jsonSerialize(),
                ],
                $shardID
            );
        }

        $presence = [
            'game' => [
                'name' => $name,
                'type' => $type,
                'url'  => null,
            ],
        ];

        return $this->setPresence($presence, $shardID);
    }

    /**
     * Set your playing game. Resolves with $this.
     *
     * @param  string|null  $name  The game name.
     * @param  string  $url  If you're streaming, this is the url to the stream.
     * @param  int|null  $shardID  Unless explicitely given, all presences will be fanned out to all shards.
     *
     * @return ExtendedPromiseInterface
     * @example ../../examples/docs-examples.php 21 2
     */
    public function setGame(?string $name, string $url = '', ?int $shardID = null)
    {
        if ($name === null) {
            return $this->setPresence(
                [
                    'game' => null,
                ],
                $shardID
            );
        }

        $presence = [
            'game' => [
                'name' => $name,
                'type' => 0,
                'url'  => null,
            ],
        ];

        if (! empty($url)) {
            $presence['game']['type'] = 1;
            $presence['game']['url'] = $url;
        }

        return $this->setPresence($presence, $shardID);
    }

    /**
     * Set your presence. Ratelimit is 5/60s, the gateway drops all further presence updates. Resolves with $this.
     *
     * ```
     * array(
     *     'afk' => bool,
     *     'since' => int|null,
     *     'status' => string,
     *     'game' => array(
     *         'name' => string,
     *         'type' => int,
     *         'url' => string|null
     *     )|null
     * )
     * ```
     *
     *  Any field in the first dimension is optional and will be automatically filled with the last known value.
     *
     * @param  array  $presence
     * @param  int|null  $shardID  Unless explicitely given, all presences will be fanned out to all shards.
     *
     * @return ExtendedPromiseInterface
     * @example ../../examples/docs-examples.php 29 10
     */
    public function setPresence(array $presence, ?int $shardID = null)
    {
        if (empty($presence)) {
            return reject(new InvalidArgumentException('Presence argument can not be empty'));
        }

        $packet = [
            'op' => WSManager::OPCODES['STATUS_UPDATE'],
            'd'  => [
                'afk'    => (array_key_exists(
                    'afk',
                    $presence
                ) ? ((bool) $presence['afk']) : $this->clientPresence['afk']),
                'since'  => (array_key_exists(
                    'since',
                    $presence
                ) ? $presence['since'] : $this->clientPresence['since']),
                'status' => (array_key_exists(
                    'status',
                    $presence
                ) ? $presence['status'] : $this->clientPresence['status']),
                'game'   => (array_key_exists('game', $presence) ? $presence['game'] : $this->clientPresence['game']),
            ],
        ];

        $this->clientPresence = $packet['d'];

        $presence = $this->getPresence();
        if ($presence) {
            $presence->_patch($this->clientPresence);
        }

        if ($shardID === null) {
            $prms = [];
            foreach ($this->client->shards as $shard) {
                $prms[] = $shard->ws->send($packet);
            }

            return all($prms)->then(
                function () {
                    return $this;
                }
            );
        }

        return $this->client->shards->get($shardID)->ws->send($packet)->then(
            function () {
                return $this;
            }
        );
    }

    /**
     * Set your username. Resolves with $this.
     *
     * @param  string  $username
     *
     * @return ExtendedPromiseInterface
     * @example ../../examples/docs-examples.php 41 2
     */
    public function setUsername(string $username)
    {
        return new Promise(
            function (callable $resolve, callable $reject) use ($username) {
                $this->client->apimanager()->endpoints->user->modifyCurrentUser(['username' => $username])->done(
                    function () use ($resolve) {
                        $resolve($this);
                    },
                    $reject
                );
            }
        );
    }

    /**
     * Creates a new Group DM with the owner of the access tokens. Resolves with an instance of GroupDMChannel. The structure of the array is as following:.
     *
     * ```
     * array(
     *    accessToken => \CharlotteDunois\Yasmin\Models\User|string (user ID)
     * )
     * ```
     *
     * The nicks array is an associative array of userID => nick. The nick defaults to the username.
     *
     * @param  array  $userWithAccessTokens
     * @param  array  $nicks
     *
     * @return ExtendedPromiseInterface
     * @see \CharlotteDunois\Yasmin\Models\GroupDMChannel
     */
    public function createGroupDM(array $userWithAccessTokens, array $nicks = [])
    {
        return new Promise(
            function (callable $resolve, callable $reject) use ($nicks, $userWithAccessTokens) {
                $tokens = [];
                $users = [];

                foreach ($userWithAccessTokens as $token => $user) {
                    $user = $this->client->users->resolve($user);

                    $tokens[] = $token;
                    $users[$user->id] = (! empty($nicks[$user->id]) ? $nicks[$user->id] : $user->username);
                }

                $this->client->apimanager()->endpoints->user->createGroupDM($tokens, $users)->done(
                    function ($data) use ($resolve) {
                        $channel = $this->client->channels->factory($data);
                        $resolve($channel);
                    },
                    $reject
                );
            }
        );
    }

    /**
     * Making these methods throw if someone tries to use them. They also get hidden due to the Sami Renderer removing them.
     */

    /**
     * @return void
     * @throws RuntimeException
     * @internal
     */
    public function createDM()
    {
        throw new RuntimeException('Can not use this method in ClientUser');
    }

    /**
     * @return void
     * @throws RuntimeException
     * @internal
     */
    public function deleteDM()
    {
        throw new RuntimeException('Can not use this method in ClientUser');
    }

    /**
     * @return void
     * @throws RuntimeException
     * @internal
     */
    public function fetchUserConnections(string $accessToken)
    {
        throw new RuntimeException('Can not use this method in ClientUser');
    }
}
