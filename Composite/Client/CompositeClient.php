<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/13/18
 * Time: 11:34 AM
 */

namespace AE\ConnectBundle\Composite\Client;

use AE\ConnectBundle\AuthProvider\AuthProviderInterface;
use AE\ConnectBundle\Composite\Model\SObject;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;

class CompositeClient
{
    public const VERSION = 'v43.0';

    public const BASE_PATH = '/services/data/'.self::VERSION.'/composite/sobjects';
    /**
     * @var Client
     */
    private $client;

    /**
     * @var AuthProviderInterface
     */
    private $authProvider;

    public function __construct(string $url, AuthProviderInterface $authProvider)
    {
        $this->authProvider = $authProvider;
        $this->client       = $this->createHttpClient($url);
    }

    protected function createHttpClient(string $url): Client
    {
        $stack = new HandlerStack();
        $stack->setHandler(\GuzzleHttp\choose_handler());
        $stack->push(Middleware::mapRequest([$this, 'authorize']));

        $client = new Client(
            [
                'base_uri' => $url.self::BASE_PATH,
                'handler'  => $stack,
            ]
        );

        return $client;
    }

    protected function authorize(RequestInterface $request): RequestInterface
    {
        return $request->withAddedHeader('Authorization', $this->authProvider->authorize());
    }

    /**
     * @param SObject[]|array $objects
     */
    public function create(array $objects)
    {

    }

    public function read()
    {

    }

    public function update(array $objects)
    {}



    public function delete()
    {

    }
}
