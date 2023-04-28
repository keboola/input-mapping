<?php

declare(strict_types=1);

namespace Keboola\InputMapping\Tests\Functional;

use Keboola\Csv\CsvFile;
use Keboola\InputMapping\Configuration\Table\Manifest\Adapter;
use Keboola\InputMapping\Reader;
use Keboola\InputMapping\Staging\AbstractStrategyFactory;
use Keboola\InputMapping\State\InputTableStateList;
use Keboola\InputMapping\Table\Options\InputTableOptionsList;
use Keboola\InputMapping\Table\Options\ReaderOptions;
use Keboola\InputMapping\Tests\AbstractTestCase;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Options\FileUploadOptions;
use Keboola\StorageApiBranch\ClientWrapper;
use Keboola\StorageApiBranch\Factory\ClientOptions;

class DownloadTablesSynapseTest extends AbstractTestCase
{
    private bool $runSynapseTests = false;

    public function setUp(): void
    {
        parent::setUp();
        $this->runSynapseTests = (bool) getenv('RUN_SYNAPSE_TESTS');
        if (!$this->runSynapseTests) {
            return;
        }
        try {
            $this->clientWrapper->getBasicClient()->dropBucket(
                'in.c-docker-test-synapse',
                [
                    'force' => true,
                    'async' => true,
                ]
            );
        } catch (ClientException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }
        $this->clientWrapper->getBasicClient()->createBucket(
            'docker-test-synapse',
            Client::STAGE_IN,
            'Docker Testsuite',
            'synapse'
        );

        // Create table
        $csv = new CsvFile($this->temp->getTmpFolder() . DIRECTORY_SEPARATOR . 'upload.csv');
        $csv->writeRow(['Id', 'Name']);
        $csv->writeRow(['test', 'test']);
        $this->clientWrapper->getBasicClient()->createTableAsync('in.c-docker-test-synapse', 'test', $csv);
    }

    protected function initClient(): void
    {
        $this->clientWrapper = new ClientWrapper(
            new ClientOptions(
                (string) getenv('SYNAPSE_STORAGE_API_URL'),
                (string) getenv('SYNAPSE_STORAGE_API_TOKEN')
            ),
        );

        $tokenInfo = $this->clientWrapper->getBasicClient()->verifyToken();
        print(sprintf(
            'Authorized as "%s (%s)" to project "%s (%s)" at "%s" stack.',
            $tokenInfo['description'],
            $tokenInfo['id'],
            $tokenInfo['owner']['name'],
            $tokenInfo['owner']['id'],
            $this->clientWrapper->getBasicClient()->getApiUrl()
        ));
    }

    public function testReadTablesSynapse(): void
    {
        if (!$this->runSynapseTests) {
            self::markTestSkipped('Synapse tests disabled');
        }
        $reader = new Reader($this->getLocalStagingFactory());
        $configuration = new InputTableOptionsList([
            [
                'source' => 'in.c-docker-test-synapse.test',
                'destination' => 'test-synapse.csv',
            ],
        ]);

        $reader->downloadTables(
            $configuration,
            new InputTableStateList([]),
            'download',
            AbstractStrategyFactory::LOCAL,
            new ReaderOptions(true)
        );

        self::assertEquals(
            "\"Id\",\"Name\"\n\"test\",\"test\"\n",
            file_get_contents($this->temp->getTmpFolder(). '/download/test-synapse.csv')
        );

        $adapter = new Adapter();

        $manifest = $adapter->readFromFile($this->temp->getTmpFolder() . '/download/test-synapse.csv.manifest');
        self::assertEquals('in.c-docker-test-synapse.test', $manifest['id']);
    }

    public function testReadTablesABSSynapse(): void
    {
        if (!$this->runSynapseTests) {
            self::markTestSkipped('Synapse tests disabled');
        }
        $reader = new Reader($this->getLocalStagingFactory());
        $configuration = new InputTableOptionsList([
            [
                'source' => 'in.c-docker-test-synapse.test',
                'destination' => 'test-synapse.csv',
            ],
        ]);

        $reader->downloadTables(
            $configuration,
            new InputTableStateList([]),
            'download',
            AbstractStrategyFactory::ABS,
            new ReaderOptions(true)
        );
        $adapter = new Adapter();

        $manifest = $adapter->readFromFile($this->temp->getTmpFolder() . '/download/test-synapse.csv.manifest');
        self::assertEquals('in.c-docker-test-synapse.test', $manifest['id']);
        $this->assertABSinfo($manifest);
    }

    public function testReadTablesEmptySlices(): void
    {
        if (!$this->runSynapseTests) {
            self::markTestSkipped('Synapse tests disabled');
        }
        $fileUploadOptions = new FileUploadOptions();
        $fileUploadOptions
            ->setIsSliced(true)
            ->setFileName('emptyfile');
        $uploadFileId = $this->clientWrapper->getBasicClient()->uploadSlicedFile([], $fileUploadOptions);
        $columns = ['Id', 'Name'];
        $headerCsvFile = new CsvFile($this->temp->getTmpFolder() . 'header.csv');
        $headerCsvFile->writeRow($columns);
        $this->clientWrapper->getBasicClient()->createTableAsync(
            'in.c-docker-test-synapse',
            'empty',
            $headerCsvFile,
            []
        );

        $options['columns'] = $columns;
        $options['dataFileId'] = $uploadFileId;
        $this->clientWrapper->getBasicClient()->writeTableAsyncDirect('in.c-docker-test-synapse.empty', $options);

        $reader = new Reader($this->getLocalStagingFactory());
        $configuration = new InputTableOptionsList([
            [
                'source' => 'in.c-docker-test-synapse.empty',
                'destination' => 'empty.csv',
            ],
        ]);

        $reader->downloadTables(
            $configuration,
            new InputTableStateList([]),
            'download',
            AbstractStrategyFactory::LOCAL,
            new ReaderOptions(true)
        );
        $file = file_get_contents($this->temp->getTmpFolder() . '/download/empty.csv');
        self::assertEquals("\"Id\",\"Name\"\n", $file);

        $adapter = new Adapter();
        $manifest = $adapter->readFromFile($this->temp->getTmpFolder() . '/download/empty.csv.manifest');
        self::assertEquals('in.c-docker-test-synapse.empty', $manifest['id']);
    }
}
