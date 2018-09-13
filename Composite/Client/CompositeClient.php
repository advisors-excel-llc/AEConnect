<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/13/18
 * Time: 11:34 AM
 */

namespace AE\ConnectBundle\Composite\Client;

use AE\ConnectBundle\AuthProvider\AuthProviderInterface;
use AE\ConnectBundle\Composite\Model\CompositeResponse;
use AE\ConnectBundle\Composite\Model\SObject;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use JMS\Serializer\SerializerInterface;
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

    /**
     * @var SerializerInterface
     */
    private $serializer;

    public function __construct(string $url, AuthProviderInterface $authProvider, SerializerInterface $serializer)
    {
        $this->authProvider = $authProvider;
        $this->client       = $this->createHttpClient($url);
        $this->serializer   = $serializer;
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
                'headers'  => [
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ],
            ]
        );

        return $client;
    }

    protected function authorize(RequestInterface $request): RequestInterface
    {
        return $request->withAddedHeader('Authorization', $this->authProvider->authorize());
    }

    /**
     * @param CompositeRequestInterface $request
     *
     * @return CompositeResponse
     */
    public function create(CompositeRequestInterface $request): CompositeResponse
    {
        $response = $this->client->post(
            ''.[
                'body' => $this->serializer->serialize($request, 'json'),
            ]
        );

        /** @var CompositeResponse $return */
        $return = $this->serializer->deserialize(
            $response->getBody(),
            CompositeResponse::class,
            'json'
        );

        return $return;
    }

    /**
     * @param string $sObjectType
     * @param array $ids
     * @param array $fields
     *
     * @return array
     */
    public function read(string $sObjectType, array $ids, array $fields = ['id']): array
    {
        $response = $this->client->get(
            self::BASE_PATH.'/'.$sObjectType,
            [
                'query' => [
                    'ids'    => implode($ids),
                    'fields' => implode(",", $fields),
                ],
            ]
        );

        return $this->serializer->deserialize(
            $response->getBody(),
            'array<'.SObject::class.'>',
            'json'
        );
    }

    /**
     * @param CompositeRequestInterface $request
     *
     * @return CompositeResponse
     */
    public function update(CompositeRequestInterface $request): CompositeResponse
    {
        $response = $this->client->patch(
            ''.[
                'body' => $this->serializer->serialize($request, 'json'),
            ]
        );

        /** @var CompositeResponse $return */
        $return = $this->serializer->deserialize(
            $response->getBody(),
            CompositeResponse::class,
            'json'
        );

        return $return;
    }

    /**
     * @param CompositeRequestInterface $request
     *
     * @return CompositeResponse
     */
    public function delete(CompositeRequestInterface $request): CompositeResponse
    {
        $ids = [];

        foreach ($request->getRecords() as $record) {
            if (null !== $record->id) {
                $ids[] = $record->id;
            }
        }

        $response = $this->client->delete(
            '',
            [
                'query' => [
                    'allOrNone' => $request->isAllOrNone() ? "true" : "false",
                    'ids'       => implode(",", $ids),
                ],
            ]
        );

        /** @var CompositeResponse $return */
        $return = $this->serializer->deserialize(
            $response->getBody(),
            CompositeResponse::class,
            'json'
        );

        return $return;
    }
}
