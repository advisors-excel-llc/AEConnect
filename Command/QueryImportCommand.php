<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 3/12/19
 * Time: 3:54 PM
 */

namespace AE\ConnectBundle\Command;

use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Salesforce\Bulk\Events\Events;
use AE\ConnectBundle\Salesforce\Bulk\Events\ProgressEvent;
use AE\ConnectBundle\Salesforce\Bulk\Events\UpdateProgressEvent;
use AE\ConnectBundle\Salesforce\Bulk\InboundQueryProcessor;
use function foo\func;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class QueryImportCommand extends Command
{
    /**
     * @var ConnectionManagerInterface
     */
    private $connectionManager;

    /**
     * @var InboundQueryProcessor
     */
    private $processor;

    /**
     * @var EventDispatcherInterface
     */
    private $dispatcher;

    public function __construct(
        ConnectionManagerInterface $connectionManager,
        InboundQueryProcessor $processor,
        EventDispatcherInterface $dispatcher
    ) {
        parent::__construct('ae_connect:bulk:import:query');
        $this->connectionManager = $connectionManager;
        $this->processor         = $processor;
        $this->dispatcher        = $dispatcher;
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this->addArgument('query', InputArgument::REQUIRED, 'The SOQL query to run')
             ->addOption(
                 'connection',
                 'c',
                 InputOption::VALUE_OPTIONAL,
                 'Specify which connections to run the query on',
                 'default'
             )
             ->addOption(
                 'insert-new',
                 'i',
                 InputOption::VALUE_NONE,
                 'Insert new records from Salesforce that don\'t exist locally'
             )
        ;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return int|null|void
     * @throws \AE\SalesforceRestSdk\AuthProvider\SessionExpiredOrInvalidException
     * @throws \Doctrine\Common\Persistence\Mapping\MappingException
     * @throws \Doctrine\ORM\Mapping\MappingException
     * @throws \Doctrine\ORM\ORMException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $connectionName = $input->getOption('connection');
        $connection     = $this->connectionManager->getConnection($connectionName);
        $query          = $input->getArgument('query');

        if (null === $connection) {
            throw new \RuntimeException("No Connection '$connectionName' found.");
        }

        if (!$connection->isActive()) {
            throw new \RuntimeException("Connection '$connectionName' is not active. Check its login credentials.");
        }

        $progress = new ProgressBar($output);

        $startListener = function (ProgressEvent $event) use ($progress) {
            $progress->start($event->getOverallTotal());
        };

        $updateListener = function (UpdateProgressEvent $event) use ($progress) {
            $type      = $event->getKey();
            $total     = $event->getTotal($type);
            $processed = $event->getProgressFor($type);

            $progress->setMessage("Importing $type records ($processed / $total)");
            $progress->setProgress($event->getOverallProgress());
        };

        $completeListener = function (ProgressEvent $event) use ($progress) {
            $progress->finish();
        };

        $this->dispatcher->addListener(Events::SET_TOTALS, $startListener);
        $this->dispatcher->addListener(Events::UPDATE_PROGRESS, $updateListener);
        $this->dispatcher->addListener(Events::COMPLETE, $completeListener);

        $output->writeln('<info>Running Query</info>');
        try {
            $this->processor->process($connection, $query, $input->getOption('insert-new'));
        } catch (\RuntimeException $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');
        }
        $output->writeln('<info>Query Complete</info>');

        $this->dispatcher->removeListener(Events::SET_TOTALS, $startListener);
        $this->dispatcher->removeListener(Events::UPDATE_PROGRESS, $updateListener);
        $this->dispatcher->removeListener(Events::COMPLETE, $completeListener);
    }
}
