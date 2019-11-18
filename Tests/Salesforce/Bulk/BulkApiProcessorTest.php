<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 8/15/19
 * Time: 3:59 PM
 */

namespace AE\ConnectBundle\Tests\Salesforce\Bulk;

use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Salesforce\Bulk\BulkApiProcessor;
use AE\ConnectBundle\Tests\DatabaseTestCase;
use AE\SalesforceRestSdk\Psr7\CsvStream;
use Doctrine\DBAL\Connection;
use function GuzzleHttp\Psr7\stream_for;

class BulkApiProcessorTest extends DatabaseTestCase
{
    /**
     * @var BulkApiProcessor
     */
    private $processor;

    /**
     * @var ConnectionManagerInterface
     */
    private $connectionManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processor         = $this->get(BulkApiProcessor::class);
        $this->connectionManager = $this->get(ConnectionManagerInterface::class);

        /** @var Connection $conn */
        $conn = $this->doctrine->getConnection();
        $conn->exec('DELETE FROM account');
    }

    protected function tearDown(): void
    {
        /** @var Connection $conn */
        $conn = $this->doctrine->getConnection();
        $conn->exec('DELETE FROM account');
        parent::tearDown();
    }

    public function _testLargeDataSet()
    {
        /** @var Connection $conn */
        $conn       = $this->doctrine->getConnection();
        $connection = $this->connectionManager->getConnection();
        $projectDir = static::$container->getParameter('kernel.project_dir');
        $path       = "$projectDir/Tests/Resources/mocks/mock-bulk-data.csv";
        $resource   = fopen($path, "r");

        $this->assertNotFalse($resource);

        $result = new CsvStream(stream_for($resource));

        $this->processor->save($result, 'Account', $connection, true, true);

        $count = $conn->executeQuery("SELECT count(id) FROM account")->fetchColumn();

        $this->assertEquals(500000, $count);
    }
}
