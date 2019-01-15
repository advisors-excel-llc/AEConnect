<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 1/14/19
 * Time: 5:03 PM
 */

namespace AE\ConnectBundle\Command;

use AE\ConnectBundle\Connection\ConnectionInterface;
use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Metadata\Metadata;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DebugMetadataCommand extends Command
{
    /**
     * @var ConnectionManagerInterface
     */
    private $connectionManager;

    public function __construct(ConnectionManagerInterface $connectionManager)
    {
        parent::__construct(null);

        $this->connectionManager = $connectionManager;
    }

    protected function configure()
    {
        $this->setName('debug:ae_connect:metadata')
             ->setDescription('Debug metadata configuration mapping for entities')
             ->addOption(
                 'connection',
                 'c',
                 InputOption::VALUE_OPTIONAL,
                 'Show only metadata mapping for the provided connection name'
             )
             ->addArgument('objectOrEntity', InputArgument::OPTIONAL, 'The SObject type or full class name')
        ;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return int|null|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var ConnectionInterface[] $connections */
        $connections = $input->getOption('connection') ? [
            $this->connectionManager->getConnection(
                $input->getOption(
                    'connection'
                )
            ),
        ] : $this->connectionManager->getConnections();

        $objectOrEntity = $input->getArgument('objectOrEntity');

        /** @var ConnectionInterface $connection */
        foreach ($connections as $connection) {
            $output->writeln('');
            $output->writeln('Connection: '.$connection->getName());
            $output->writeln('');
            if (null === $objectOrEntity) {
                $this->renderAllEntitiesForConnection($output, $connection);
            } else {
                $this->renderEntityForConnection($output, $connection, $objectOrEntity);
            }
            $output->writeln('');
            $output->writeln('--');
        }
    }

    /**
     * @param OutputInterface $output
     * @param ConnectionInterface $connection
     */
    private function renderAllEntitiesForConnection(OutputInterface $output, ConnectionInterface $connection)
    {
        $table = new Table($output);

        $table->setHeaders(
            [
                'Entity Class',
                'SObject Type',
            ]
        );

        $map              = [];
        $metadataRegistry = $connection->getMetadataRegistry();

        foreach ($metadataRegistry->getMetadata() as $metadata) {
            $className = $metadata->getClassName();

            if (!array_key_exists($className, $map)) {
                $map[$className] = [];
            }

            $map[$className][] = $metadata->getSObjectType();
        }

        foreach ($map as $class => $types) {
            $table->addRow(
                [
                    new TableCell($class, ['rowspan' => count($types)]),
                    array_shift($types),
                ]
            );

            foreach ($types as $type) {
                $table->addRow([$type]);
            }
        }

        $table->render();
    }

    /**
     * @param OutputInterface $output
     * @param ConnectionInterface $connection
     * @param string $objectOrEntity
     */
    private function renderEntityForConnection(
        OutputInterface $output,
        ConnectionInterface $connection,
        string $objectOrEntity
    ) {
        $metadataRegistry = $connection->getMetadataRegistry();

        if (strpos($objectOrEntity, '\\') !== false) {
            $metadata = $metadataRegistry->findMetadataByClass($objectOrEntity);
            $this->renderMetadataInfo($output, $metadata);
            $output->writeln('');
            $this->renderFieldMetadataTable($output, $metadata);
        } else {
            $meta = $metadataRegistry->findMetadataBySObjectType($objectOrEntity);

            foreach ($meta as $metadata) {
                $this->renderMetadataInfo($output, $metadata);
                $output->writeln('');
                $this->renderFieldMetadataTable($output, $metadata);
            }
        }
    }

    /**
     * @param OutputInterface $output
     * @param Metadata $metadata
     */
    private function renderMetadataInfo(OutputInterface $output, Metadata $metadata)
    {
        $table = new Table($output);
        $table->setStyle('borderless');

        $table->addRows(
            [
                ['Class', $metadata->getClassName()],
                ['SObject', $metadata->getSObjectType()],
                [
                    'Record Type',
                    null !== $metadata->getRecordType()
                        ? $metadata->getRecordType()->getName() ?: 'DYNAMIC'
                        : '',
                ],
                ['Connection', $metadata->getConnectionName()],
            ]
        );

        $table->render();
    }

    /**
     * @param OutputInterface $output
     * @param Metadata $metadata
     */
    private function renderFieldMetadataTable(OutputInterface $output, Metadata $metadata)
    {
        $table = new Table($output);

        $table->setHeaders(['Class Property', 'SObject Field']);

        foreach ($metadata->getPropertyMap() as $property => $field) {
            $table->addRow([$property, $field]);
        }

        $table->render();
    }
}
