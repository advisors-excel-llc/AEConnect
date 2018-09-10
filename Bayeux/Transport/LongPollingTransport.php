<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/6/18
 * Time: 12:19 PM
 */

namespace AE\ConnectBundle\Bayeux\Transport;

use AE\ConnectBundle\Bayeux\ChannelInterface;
use AE\ConnectBundle\Bayeux\Message;
use Doctrine\Common\Collections\ArrayCollection;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Request;
use JMS\Serializer\SerializerInterface;
use GuzzleHttp\Promise\PromiseInterface;

class LongPollingTransport extends HttpClientTransport
{
    /**
     * @var bool
     */
    private $aborted = false;

    /**
     * @var Request[]|ArrayCollection
     */
    private $requests;

    public function __construct(SerializerInterface $serializer)
    {
        parent::__construct('long-polling');
        $this->setSerializer($serializer);
        $this->requests = new ArrayCollection();
    }

    public function abort()
    {
        $this->aborted = true;

        $this->requests->forAll(
            function ($request) {
                /** @var PromiseInterface $request */
                $request->cancel();
            }
        );

        $this->requests->clear();
    }

    /**
     * @return bool
     */
    public function isAborted(): bool
    {
        return $this->aborted;
    }

    /**
     * @param Message[]|array $messages
     * @param callable|null $customize
     *
     * @return PromiseInterface
     */
    public function send($messages, ?callable $customize = null): PromiseInterface
    {
        $this->aborted = false;
        $client        = $this->getHttpClient();
        $url           = $this->getUrl() ?: '';

        if (count($messages) == 1 && $messages[0]->isMeta()) {
            $type = substr($messages[0]->getChannel(), strlen(ChannelInterface::META));
            if (substr($url, -1, 1) == '/') {
                $url .= substr($url, 0, strlen($url) - 1);
            }

            $url .= 'meta'.$type;
        }

        $request = new Request(
            "POST",
            $url,
            [
                'User-Agent'   => 'ae-connect-bayeux-client/1.0',
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json;charset=UTF-8',
            ],
            $this->generateJSON($messages)
        );

        if (null !== $customize) {
            $request = call_user_func($customize, $request);
        }

        $promise = $client->sendAsync($request);
        $return  = new Promise(
            function () use ($promise, &$return) {
                try {
                    $response = $promise->wait();
                } catch (\Exception $e) {
                    $this->requests->removeElement($promise);
                    throw $e;
                }
                $body = (string)$response->getBody();
                if (strlen($body) > 0) {
                    $return->resolve($this->parseMessages($body));
                    $this->requests->removeElement($promise);
                }
            },
            function () use ($promise, &$return) {
                try {
                    $response = $promise->wait();
                } catch (\Exception $e) {
                    $this->requests->removeElement($promise);
                    throw $e;
                }
                $body = (string)$response->getBody();
                if (strlen($body) == 0) {
                    $return->reject(204);
                    $this->requests->removeElement($promise);
                }
            }
        );

        $return->otherwise(
            function () use ($promise) {
                $this->requests->removeElement($promise);
            }
        );

        $this->requests->add($promise);

        return $return;
    }

    public function terminate()
    {
        $this->abort();
    }
}
