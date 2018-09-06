<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/6/18
 * Time: 9:58 AM
 */

namespace AE\ConnectBundle\Bayeux;

use Doctrine\Common\Collections\ArrayCollection;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;

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

    /**
     * @var Client
     */
    private $httpClient;

    /**
     * @var CookieJar
     */
    private $cookieJar;

    private $transport;

    private $authProvider;

    private $clientId;

    private $state = self::DISCONNECTED;

    /**
     * @var ArrayCollection
     */
    private $channels;

    private $url;
}
