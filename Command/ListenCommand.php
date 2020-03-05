<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/22/18
 * Time: 3:45 PM
 */

namespace AE\ConnectBundle\Command;

use AE\ConnectBundle\Connection\ConnectionInterface;
use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Salesforce\Inbound\SObjectConsumer;
use AE\ConnectBundle\Util\Exceptions\MemoryLimitException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ListenCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var ConnectionManagerInterface
     */
    private $connectionManager;
    private $consumer;

    public function __construct(ConnectionManagerInterface $connectionManager, SObjectConsumer $consumer, ?LoggerInterface $logger = null)
    {
        parent::__construct(null);
        $this->consumer = $consumer;
        $this->connectionManager = $connectionManager;
        $this->setLogger($logger ?: new NullLogger());
    }

    protected function configure()
    {
        $this->setName('ae_connect:listen')
             ->addArgument(
                 'connectionName',
                 InputArgument::OPTIONAL,
                 'The name of the connection you wish to listen to.',
                 'default'
             )
            ->addOption(
                'memoryLimit',
                    null,
                    InputOption::VALUE_OPTIONAL,
                    'Memory Limit in MiB.  If the memory limit is exceeded the next time an sObject is consumed, the process will close.',
                    null
                )
            ->addOption(
                'countLimit',
                null,
                InputOption::VALUE_OPTIONAL,
                'Recieve Limit.  If the number of messages we receive from salesforce exceeds this number, the process will close.',
                null
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $connectionName = $input->getArgument('connectionName');
        $connection     = $this->connectionManager->getConnection($connectionName);

        if ($input->getOption('memoryLimit')) {
            $this->consumer->setMemoryLimit($input->getOption('memoryLimit'));
        }

        if ($input->getOption('countLimit')) {
            $this->consumer->setCountLimit($input->getOption('countLimit'));
        }

        if (null === $connection) {
            throw new \InvalidArgumentException(sprintf("Could not find any connection named '%s'.", $connectionName));
        }

        if (!$connection->isActive()) {
            throw new \InvalidArgumentException(
                sprintf(
                    "Connection, '%s', is inactive, most likely due to bad credentials.",
                    $connectionName
                )
            );
        }

        $output->writeln('<info>Listening to connection: '.$connectionName.'</info>');

        while ($this->start($connection)) {
            $output->writeln('<info>Restarting connection: '.$connectionName.'</info>');
        }
    }

    private function start(ConnectionInterface $connection)
    {
        try {
            $connection->getStreamingClient()->start();
        }
        catch (MemoryLimitException $e) {
            $this->logger->critical($e->getMessage());
            $connection->getStreamingClient()->stop();
            return false;
        }
        catch (\Exception $e) {
            $this->logger->critical($e->getMessage());
            $connection->getStreamingClient()->stop();
        }

        return true;
    }
}
