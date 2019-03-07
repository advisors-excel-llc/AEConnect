<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 3/7/19
 * Time: 10:24 AM
 */

namespace AE\ConnectBundle\Salesforce\Inbound;

use AE\SalesforceRestSdk\Bayeux\ChannelInterface;
use AE\SalesforceRestSdk\Bayeux\Message;
use JMS\Serializer\SerializerInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DebugConsumer implements SalesforceConsumerInterface
{
    /**
     * @var OutputInterface
     */
    private $output;

    /**
     * @var SerializerInterface
     */
    private $serializer;

    public function __construct(OutputInterface $output, SerializerInterface $serializer)
    {
        $this->output     = $output;
        $this->serializer = $serializer;
    }

    public function consume(ChannelInterface $channel, Message $message)
    {
        $this->output->writeln('--');
        $this->output->writeln(sprintf('Received Message on Channel: %s', $channel->getChannelId()));
        if ($message->isSuccessful()) {
            $this->output->write($this->serializer->serialize($message->getData(), 'json'), true);
        } else {
            $this->output->writeln(sprintf('<error>%s</error>', $message->getError()));
        }
        $this->output->writeln('--');
    }

    public function getPriority(): ?int
    {
        return 0;
    }

    /**
     * @inheritDoc
     */
    public function channels(): array
    {
        return [
            'topics'          => '*',
            'objects'         => '*',
            'platform_events' => '*',
            'generic_events'  => '*',
        ];
    }

}
