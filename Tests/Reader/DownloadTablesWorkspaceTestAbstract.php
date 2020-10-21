<?php

namespace Keboola\InputMapping\Tests\Reader;

use Keboola\Csv\CsvFile;
use Keboola\InputMapping\Reader\NullWorkspaceProvider;
use Keboola\InputMapping\Reader\WorkspaceProviderInterface;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Workspaces;

class DownloadTablesWorkspaceTestAbstract extends DownloadTablesTestAbstract
{
    protected $workspaceId;

    public function setUp()
    {
        parent::setUp();
        try {
            $this->client->dropBucket('in.c-input-mapping-test', ['force' => true]);
        } catch (ClientException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }
        try {
            $this->client->dropBucket('out.c-input-mapping-test', ['force' => true]);
        } catch (ClientException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }
        $this->client->createBucket('input-mapping-test', Client::STAGE_IN, 'Docker Testsuite');
        $this->client->createBucket('input-mapping-test', Client::STAGE_OUT, 'Docker Testsuite');

        // Create table
        $csv = new CsvFile($this->temp->getTmpFolder() . DIRECTORY_SEPARATOR . 'upload.csv');
        $csv->writeRow(['Id', 'Name', 'foo', 'bar']);
        $csv->writeRow(['id1', 'name1', 'foo1', 'bar1']);
        $csv->writeRow(['id2', 'name2', 'foo2', 'bar2']);
        $csv->writeRow(['id3', 'name3', 'foo3', 'bar3']);
        $this->client->createTableAsync('in.c-input-mapping-test', 'test1', $csv);
        $this->client->createTableAsync('in.c-input-mapping-test', 'test2', $csv);
        $this->client->createTableAsync('in.c-input-mapping-test', 'test3', $csv);
    }

    public function tearDown()
    {
        if ($this->workspaceId) {
            $workspaces = new Workspaces($this->client);
            $workspaces->deleteWorkspace($this->workspaceId);
            $this->workspaceId = null;
        }
        parent::tearDown();
    }

    protected function getWorkspaceProvider()
    {
        $mock = self::getMockBuilder(NullWorkspaceProvider::class)
            ->setMethods(['getWorkspaceId'])
            ->getMock();
        $mock->method('getWorkspaceId')->willReturnCallback(
            function ($type) {
                if (!$this->workspaceId) {
                    $workspaces = new Workspaces($this->client);
                    $workspace = $workspaces->createWorkspace(['backend' => $type]);
                    $this->workspaceId = $workspace['id'];
                }
                return $this->workspaceId;
            }
        );
        /** @var WorkspaceProviderInterface $mock */
        return $mock;
    }
}