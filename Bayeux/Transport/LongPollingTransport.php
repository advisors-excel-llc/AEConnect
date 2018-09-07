<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/6/18
 * Time: 12:19 PM
 */

namespace AE\ConnectBundle\Bayeux\Transport;

use AE\ConnectBundle\Bayeux\Message;
use Doctrine\Common\Collections\ArrayCollection;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Request;
use JMS\Serializer\SerializerInterface;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\ResponseInterface;

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

        $this->requests->forAll(function ($request) {
            /** @var PromiseInterface $request */
            $request->cancel();
        });

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
        $client = $this->getHttpClient();
        $url = $this->getUrl() ?: '/';

        if (count($messages) == 1 && $messages[0]->isMeta()) {
            $type = substr($$messages[0]->getChannel(), 4);
            if (substr($url, -1, 1) != '/') {
                $url .= '/';
            }

            $url .= 'meta'.$type;
        }

        $request = new Request("POST", $url, [
            'User-Agent' => 'ae-connect-bayeux-client/1.0',
            'Accept' => 'application/json',
            'Content-Type' => 'application/json;charset=UTF-8',
        ], $this->generateJSON($messages));

        if (null !== $customize) {
            call_user_func($customize, $request);
        }

        $return  = new Promise();
        $promise = $client->sendAsync($request);

        $this->requests->add($promise);

        $promise->then(function (ResponseInterface $response) use ($promise, $return) {
            $body = (string) $response->getBody();
            if (strlen($body) > 0) {
                $return->resolve($this->parseMessages($body));
            } else {
                $return->reject(204);
            }

            if ($this->requests->contains($promise)) {
                $this->requests->removeElement($promise);
            }
        }, function (ResponseInterface $response) use ($promise, $return) {
            $return->reject($response->getStatusCode());
            if ($this->requests->contains($promise)) {
                $this->requests->removeElement($promise);
            }
        });

        return $return;
    }

    public function terminate()
    {
        $this->abort();
    }
}
