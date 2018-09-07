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

        $this->requests->forAll(function ($request) {
            /** @var PromiseInterface $request */
            $request->reject('Aborted');
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
     *
     * @return PromiseInterface
     */
    public function send($messages): PromiseInterface
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
            'headers' => [
                'User-Agent' => 'ae-connect-bayeux-client/1.0',
                'Accept' => 'application/json',
                'Content-Type' => 'application/json;charset=UTF-8',
            ]
        ], $this->generateJSON($messages));

        $promise = $client->sendAsync($request);

        $this->requests->add($promise);

        $promise->then(function () use ($promise) {
            if ($this->requests->contains($promise)) {
                $this->requests->removeElement($promise);
            }
        }, function () use ($promise) {
            if ($this->requests->contains($promise)) {
                $this->requests->removeElement($promise);
            }
        });

        return $promise;
    }
}
