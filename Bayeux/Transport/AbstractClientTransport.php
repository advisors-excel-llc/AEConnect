<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/6/18
 * Time: 11:45 AM
 */

namespace AE\ConnectBundle\Bayeux\Transport;

use AE\ConnectBundle\Bayeux\Message;
use JMS\Serializer\SerializerInterface;

abstract class AbstractClientTransport
{
    /**
     * @var string
     */
    protected $name;

    /**
     * @var string
     */
    protected $url;

    /**
     * @var SerializerInterface
     */
    protected $serializer;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    abstract public function abort();

    public function terminate()
    {
    }

    /**
     * @param Message[]|array $messages
     *
     * @return mixed
     */
    abstract public function send($messages);

    protected function parseMessages(string $context): array
    {
        return $this->serializer->deserialize($context, 'Array<AE\\ConnectBundle\\Bayeux\\Message>', 'json');
    }

    protected function generateJSON($messages): string
    {
        return $this->serializer->serialize($messages, 'json');
    }

    /**
     * @return string
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * @param string $url
     *
     * @return AbstractClientTransport
     */
    public function setUrl(string $url): AbstractClientTransport
    {
        $this->url = $url;

        return $this;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return SerializerInterface
     */
    public function getSerializer(): SerializerInterface
    {
        return $this->serializer;
    }

    /**
     * @param SerializerInterface $serializer
     *
     * @return AbstractClientTransport
     */
    public function setSerializer(SerializerInterface $serializer): AbstractClientTransport
    {
        $this->serializer = $serializer;

        return $this;
    }
}
