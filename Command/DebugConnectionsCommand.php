<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 1/14/19
 * Time: 4:11 PM
 */

namespace AE\ConnectBundle\Command;

use AE\ConnectBundle\AuthProvider\OAuthProvider;
use AE\ConnectBundle\AuthProvider\SoapProvider;
use AE\ConnectBundle\Connection\ConnectionInterface;
use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DebugConnectionsCommand extends Command
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
        $this->setName('debug:ae_connect:connections')
             ->setDescription('Debug information about configured connections')
             ->addArgument('connection', InputArgument::OPTIONAL)
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
        $connectionName = $input->getArgument('connection');

        if (null === $connectionName) {
            $this->renderAllConnections($output);
        } else {
            $connection = $this->connectionManager->getConnection($connectionName);

            if (null === $connection) {
                throw new \RuntimeException(sprintf('Connection "%s" not found', $connectionName));
            }

            $this->renderConnection($output, $connection);
        }
    }

    /**
     * @param OutputInterface $output
     */
    private function renderAllConnections(OutputInterface $output)
    {
        $table = new Table($output);

        $table->setHeaders(
            [
                'Name',
                'Default',
                'Authorized',
                'Username',
                'Instance URL',
            ]
        );

        $rows = [];

        /** @var ConnectionInterface $connection */
        foreach ($this->connectionManager->getConnections() as $connection) {
            $username     = '';
            $authProvider = $connection->getRestClient()->getAuthProvider();
            if (($identity = $authProvider->getIdentity())
                && array_key_exists('username', $identity)
            ) {
                $username = $identity['username'];
            }

            $rows[] = [
                $connection->getName(),
                $connection->isDefault() ? 'x' : '',
                $authProvider->isAuthorized() ? 'Yes' : 'No',
                $username,
                $authProvider->getInstanceUrl(),
            ];
        }

        $table->setRows($rows)->render();
    }

    /**
     * @param OutputInterface $output
     * @param ConnectionInterface $connection
     */
    private function renderConnection(OutputInterface $output, ConnectionInterface $connection)
    {
        $authProvider = $connection->getRestClient()->getAuthProvider();
        $providerType = $authProvider instanceof SoapProvider ? 'SOAP' : 'OAuth';
        $username     = '';

        if (($identity = $authProvider->getIdentity()) && array_key_exists('username', $identity)) {
            $username = $identity['username'];
        }

        $table = new Table($output);

        $rows = [
            [new TableCell($connection->getName(), ['colspan' => 2])],
            new TableSeparator(),
            ['Is Default', $connection->isDefault() ? 'Yes' : 'No'],
            ['Is Authorized', $authProvider->isAuthorized() ? 'Yes' : 'No'],
            ['Is Active', $connection->isActive() ? 'Yes' : 'No'],
            ['Username', $username],
            ['Instance Url', $authProvider->getInstanceUrl()],
            ['Authorization', $providerType],
            ['Token', $authProvider->getToken()],
        ];

        if ($authProvider instanceof OAuthProvider) {
            $rows[] = ['Refresh Token', $authProvider->getRefreshToken()];
            $rows[] = ['Client Id', $authProvider->getClientId()];
            $rows[] = ['Client Secret', $authProvider->getClientSecret()];
            $rows[] = ['Grant Type', $authProvider->getGrantType()];
        }


        $table->setRows($rows)->render();
    }
}
