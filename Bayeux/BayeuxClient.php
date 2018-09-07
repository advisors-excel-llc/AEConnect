<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/6/18
 * Time: 9:58 AM
 */

namespace AE\ConnectBundle\Bayeux;

use AE\ConnectBundle\Bayeux\AuthProvider\AuthProviderInterface;
use AE\ConnectBundle\Bayeux\Transport\AbstractClientTransport;
use AE\ConnectBundle\Bayeux\Transport\HttpClientTransport;
use Doctrine\Common\Collections\ArrayCollection;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Promise;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

class BayeuxClient
{
    /**
     * State assumed after the handshake when the connection is broken
     */
    public const UNCONNECTED = "UNCONNECTED";
    /**
     * State assumed when the handshake is being sent
     */
    public const HANDSHAKING = "HANDSHAKING";
    /**
     * State assumed when a first handshake failed and the handshake is retried,
     * or when the Bayeux server requests a re-handshake
     */
    public const REHANDSHAKING = "REHANDSHAKING";
    /**
     * State assumed when the handshake is received, but before connecting
     */
    public const HANDSHAKEN = "HANDSHAKEN";
    /**
     * State assumed when the connect is being sent for the first time
     */
    public const CONNECTING = "CONNECTING";
    /**
     * State assumed when this {@link BayeuxClient} is connected to the Bayeux server
     */
    public const CONNECTED = "CONNECTED";
    /**
     * State assumed when the disconnect is being sent
     */
    public const DISCONNECTING = "DISCONNECTING";
    /**
     * State assumed when the disconnect is received but terminal actions must be performed
     */
    public const TERMINATING = "TERMINATING";
    /**
     * State assumed before the handshake and when the disconnect is completed
     */
    public const DISCONNECTED = "DISCONNECTED";

    public const VERSION            = '3.1.0';
    public const MINIMUM_VERSION    = '3.1.0';
    public const SALESFORCE_VERSION = '43.0';

    /**
     * @var Client
     */
    private $httpClient;

    /**
     * @var AbstractClientTransport
     */
    private $transport;

    /**
     * @var AuthProviderInterface
     */
    private $authProvider;

    /**
     * @var string
     */
    private $clientId;

    /**
     * @var string
     */
    private $state = self::DISCONNECTED;

    /**
     * @var ArrayCollection|ChannelInterface[]
     */
    private $channels;

    /**
     * @var string
     */
    private $url;

    /**
     * @var LoggerInterface|null
     */
    private $logger;

    /**
     * BayeuxClient constructor.
     *
     * @param string $url
     * @param AbstractClientTransport $transport
     * @param AuthProviderInterface $authProvider
     * @param LoggerInterface|null $logger
     *
     * @throws \Exception
     */
    public function __construct(
        string $url,
        AbstractClientTransport $transport,
        AuthProviderInterface $authProvider,
        LoggerInterface $logger = null
    ) {
        $this->transport    = $transport;
        $this->authProvider = $authProvider;
        $this->httpClient   = new Client(
            [
                'base_uri' => $url.'/cometd/'.static::SALESFORCE_VERSION.'/',
                'cookies'  => true,
            ]
        );
        $this->clientId     = Uuid::uuid4()->toString();
        $this->channels     = new ArrayCollection();
        $this->logger       = $logger;

        if ($this->transport instanceof HttpClientTransport) {
            $this->transport->setHttpClient($this->httpClient);
        }
    }

    /**
     * @param string $channelId
     *
     * @return ChannelInterface
     */
    public function getChannel(string $channelId): ChannelInterface
    {
        if ($this->channels->containsKey($channelId)) {
            return $this->channels->get($channelId);
        }

        $channel = new Channel($this, $channelId);

        $this->channels->set($channelId, $channel);

        return $channel;
    }

    /**
     * Start the Bayeux Client
     *
     * @code <?php
     *      $client = new BayeuxClient(...);
     *      $process = $client->start();
     *      $channel = $client->getChannel('/topic/mytopic');
     *      $channel->subscribe(function(ChannelInterface $c, StreamingData $data) {
     *          ///...
     *      });
     *      $process->wait();
     * @return Promise
     */
    public function start(): Promise
    {
        if (!$this->isDisconnected()) {
            throw new \RuntimeException("The client must be disconnected before starting.");
        }

        if (!$this->handshake()) {
            throw new \RuntimeException("Handshake authentication failed with the server.");
        }

        pcntl_signal(SIGTERM, [$this, 'terminate']);
        pcntl_signal(SIGINT, [$this, 'disconnect']);
        pcntl_signal(SIGHUP, [$this, 'connect']);

        $promise = new Promise(
            function () use (&$promise) {
                try {
                    while ($this->connect()) {
                        pcntl_signal_dispatch();
                    }
                    /** @var Promise $promise */
                    $promise->resolve(true);
                } catch (\Throwable $e) {
                    if (null !== $this->logger) {
                        $this->logger->critical(
                            'An error occurred while consuming the streaming api: {error}',
                            [
                                'error' => $e->getMessage(),
                            ]
                        );
                    }

                    $promise->reject($e);
                }
            }
        );

        return $promise;
    }

    public function isDisconnected()
    {
        return in_array($this->state, [static::DISCONNECTED, static::DISCONNECTING, static::UNCONNECTED]);
    }

    /**
     * @return bool
     */
    public function handshake(): bool
    {
        if (in_array(
            $this->state,
            [
                static::CONNECTING,
                static::CONNECTED,
                static::HANDSHAKING,
                static::HANDSHAKEN,
                static::TERMINATING,
            ]
        )) {
            return false;
        }

        if ($this->state !== static::REHANDSHAKING) {
            $this->state = static::HANDSHAKING;
        }

        $message = new Message();
        $message->setChannel(ChannelInterface::META_HANDSHAKE);
        $message->setSupportedConnectedTypes([$this->transport->getName()]);
        $message->setVersion(static::VERSION);
        $message->setMinimumVersion(static::MINIMUM_VERSION);

        $messages = $this->sendMessages([$message]);

        if (count($messages) === 0) {
            $this->state = static::UNCONNECTED;

            return false;
        }

        $reply = $messages[0];

        if ($reply->isSuccessful()) {
            $this->channels->clear();
            $this->state = static::HANDSHAKEN;

            return true;
        }

        $advice = $reply->getAdvice();

        if ($advice->getReconnect() == 'retry') {
            $this->state = static::REHANDSHAKING;
            sleep($advice->getInterval() ?: 0);

            return $this->handshake();
        }

        if (null !== $this->logger) {
            $this->logger->critical(
                'Failed to handshake with Salesforce: {error}',
                [
                    'error' => $reply->getError(),
                ]
            );
        }

        $this->state = static::UNCONNECTED;

        return false;
    }

    /**
     * @return bool
     */
    public function connect()
    {
        if ($this->state !== static::HANDSHAKEN || $this->state == static::CONNECTING) {
            return false;
        }

        $this->state = static::CONNECTING;

        $message = new Message();
        $message->setChannel(ChannelInterface::META_CONNECT);
        $message->setConnectionType($this->transport->getName());

        $messages = $this->sendMessages([$message]);

        if (count($messages) === 0) {
            return false;
        }

        $success  = true;
        $retry    = false;
        $interval = 0;

        foreach ($messages as $message) {
            if ($message->isSuccessful()) {
                $channel = $this->getChannel($message->getChannel());
                if (null !== $channel) {
                    $channel->notifyMessageListeners($message);
                }
            } else {
                $retry = $message->getAdvice()->getReconnect() === 'retry';

                if ($retry) {
                    $interval = $message->getAdvice()->getInterval() ?: 0;
                }

                if (null !== $this->logger) {
                    $this->logger->critical(
                        'Failed to connect with Salesforce: {error}',
                        [
                            'error' => $message->getError(),
                        ]
                    );
                }

                $success = false;
            }
        }

        if ($retry) {
            sleep($interval);

            return $this->connect();
        }

        return $success;
    }

    public function disconnect(): bool
    {
        if ($this->state !== static::CONNECTED || $this->state !== static::TERMINATING) {
            return false;
        }

        if ($this->state !== static::TERMINATING) {
            $this->state = static::DISCONNECTING;
        }

        $unsubscribes = [];

        foreach ($this->channels as $channel) {
            $message = new Message();
            $message->setChannel(ChannelInterface::META_UNSUBSCRIBE);
            $message->setClientId($this->clientId);
            $message->setSubscription($channel->getChannelId());
            $unsubscribes[] = $message;
        }

        // Unsubscribe channels
        if (count($unsubscribes) > 0) {
            $this->sendMessages($unsubscribes);
        }

        $message = new Message();
        $message->setChannel(ChannelInterface::META_DISCONNECT);

        $this->sendMessages([$message]);
        $this->authProvider->revoke();

        $this->state = static::DISCONNECTED;

        return true;
    }

    public function terminate(): bool
    {
        $this->state = static::TERMINATING;
        $this->transport->terminate();

        return $this->disconnect();
    }

    /**
     * @param Message[]|array $messages
     *
     * @return array|Message[]
     */
    public function sendMessages($messages): array
    {
        foreach ($messages as $message) {
            $message->setClientId($this->clientId);
        }

        $promise = $this->transport->send(
            $messages,
            function (RequestInterface $request) {
                $request->withAddedHeader('Authorization', $this->authProvider->authorize());
            }
        );
        $return  = [];

        $promise->then(
            function ($newMessages) use (&$return) {
                foreach ($newMessages as $message) {
                    $return[] = $message;
                }
            }
        );

        $promise->wait();

        return $return;
    }

    /**
     * @return AbstractClientTransport
     */
    public function getTransport(): AbstractClientTransport
    {
        return $this->transport;
    }

    /**
     * @param AbstractClientTransport $transport
     *
     * @return BayeuxClient
     */
    public function setTransport(AbstractClientTransport $transport): BayeuxClient
    {
        $this->transport = $transport;

        return $this;
    }

    /**
     * @return AuthProviderInterface
     */
    public function getAuthProvider(): AuthProviderInterface
    {
        return $this->authProvider;
    }

    /**
     * @param AuthProviderInterface $authProvider
     *
     * @return BayeuxClient
     */
    public function setAuthProvider(AuthProviderInterface $authProvider): BayeuxClient
    {
        $this->authProvider = $authProvider;

        return $this;
    }

    /**
     * @return Client
     */
    public function getHttpClient(): Client
    {
        return $this->httpClient;
    }

    /**
     * @return string
     */
    public function getClientId(): string
    {
        return $this->clientId;
    }

    /**
     * @return string
     */
    public function getState(): string
    {
        return $this->state;
    }

    /**
     * @return ArrayCollection
     */
    public function getChannels(): ArrayCollection
    {
        return $this->channels;
    }

    /**
     * @return string
     */
    public function getUrl(): string
    {
        return $this->url;
    }
}
