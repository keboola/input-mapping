<?php

namespace Keboola\InputMapping\Tests\Functional;

use Keboola\InputMapping\Configuration\File\Manifest\Adapter;
use Keboola\InputMapping\Exception\InvalidInputException;
use Keboola\InputMapping\Reader;
use Keboola\InputMapping\Staging\ProviderInterface;
use Keboola\InputMapping\Staging\Scope;
use Keboola\InputMapping\Staging\NullProvider;
use Keboola\InputMapping\Staging\StrategyFactory;
use Keboola\InputMapping\State\InputFileStateList;
use Keboola\InputMapping\Table\Options\InputTableOptions;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Exception;
use Keboola\StorageApi\Options\FileUploadOptions;
use Keboola\StorageApi\Workspaces;
use Keboola\StorageApiBranch\ClientWrapper;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use MicrosoftAzure\Storage\Common\Exceptions\ServiceException;
use Psr\Log\NullLogger;

class DownloadFilesAbsWorkspaceTest extends DownloadFilesTestAbstract
{
    private $runSynapseTests;

    /** @var string */
    protected $workspaceId;

    /** @var array [connectionString, container] */
    protected $workspaceCredentials;

    /** @var BlobRestProxy */
    protected $blobClient;

    public function setUp()
    {
        $this->runSynapseTests = getenv('RUN_SYNAPSE_TESTS');
        if (!$this->runSynapseTests) {
            self::markTestSkipped('Synapse tests disabled');
        }
        if (getenv('SYNAPSE_STORAGE_API_TOKEN') === false) {
            throw new Exception('SYNAPSE_STORAGE_API_TOKEN must be set for synapse tests');
        }
        if (getenv('SYNAPSE_STORAGE_API_URL') === false) {
            throw new Exception('SYNAPSE_STORAGE_API_URL must be set for synapse tests');
        }
        parent::setUp();
        $this->getStagingFactory()->getStrategyMap()[StrategyFactory::WORKSPACE_ABS]
            ->getFileDataProvider()->getWorkspaceId(); //initialize the mock

        $this->blobClient = BlobRestProxy::createBlobService($this->workspaceCredentials['connectionString']);
    }

    public function tearDown()
    {
        if ($this->workspaceId) {
            $workspaces = new Workspaces($this->clientWrapper->getBranchClientIfAvailable());
            $workspaces->deleteWorkspace($this->workspaceId);
            $this->workspaceId = null;
        }
        parent::tearDown();
    }

    protected function initClient()
    {
        $token = (string) getenv('SYNAPSE_STORAGE_API_TOKEN');
        $url = (string) getenv('SYNAPSE_STORAGE_API_URL');
        $this->clientWrapper = new ClientWrapper(
            new Client(["token" => $token, "url" => $url]),
            null,
            null,
            ClientWrapper::BRANCH_MAIN
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

    protected function getStagingFactory($clientWrapper = null, $format = 'json', $logger = null)
    {
        $stagingFactory = new StrategyFactory(
            $clientWrapper ? $clientWrapper : $this->clientWrapper,
            $logger ? $logger : new NullLogger(),
            $format
        );
        $mockWorkspace = self::getMockBuilder(NullProvider::class)
            ->setMethods(['getWorkspaceId', 'getCredentials'])
            ->getMock();
        $mockWorkspace->method('getWorkspaceId')->willReturnCallback(
            function () {
                if (!$this->workspaceId) {
                    $workspaces = new Workspaces($this->clientWrapper->getBranchClientIfAvailable());
                    $workspace = $workspaces->createWorkspace(['backend' => 'abs']);
                    $this->workspaceId = $workspace['id'];
                    $this->workspaceCredentials = $workspace['connection'];
                }
                return $this->workspaceId;
            }
        );
        $mockWorkspace->method('getCredentials')->willReturn($this->workspaceCredentials);

        /** @var ProviderInterface $mockWorkspace */
        $stagingFactory->addProvider(
            $mockWorkspace,
            [
                StrategyFactory::WORKSPACE_ABS => new Scope([Scope::FILE_DATA, Scope::FILE_METADATA]),
            ]
        );
        return $stagingFactory;
    }

    private function assertBlobNotEmpty($blobPath)
    {
        self::assertNotEmpty(
            stream_get_contents(
                $this->blobClient->getBlob(
                    $this->workspaceCredentials['container'],
                    $blobPath
                )->getContentStream()
            )
        );
    }

    public function testAbsReadFiles()
    {
        $this->blobClient->createBlockBlob(
            $this->workspaceCredentials['container'],
            'data/in/tables/sometable.csv',
            'some data'
        );

        $root = $this->tmpDir;
        file_put_contents($root . '/upload', 'test');

        $id1 = $this->clientWrapper->getBasicClient()->uploadFile(
            $root . '/upload',
            (new FileUploadOptions())->setTags(['download-files-test'])
        );
        $id2 = $this->clientWrapper->getBasicClient()->uploadFile(
            $root . '/upload',
            (new FileUploadOptions())->setTags(['download-files-test'])
        );
        sleep(5);
        $reader = new Reader($this->getStagingFactory());
        $configuration = [['tags' => ['download-files-test'], 'overwrite' => true]];
        $reader->downloadFiles(
            $configuration,
            'data/in/files/',
            StrategyFactory::WORKSPACE_ABS,
            new InputFileStateList([])
        );

        $blobResult1 = $this->blobClient->getBlob(
            $this->workspaceCredentials['container'],
            'data/in/files/upload/' . $id1
        );
        $manifestResult1 = $this->blobClient->getBlob(
            $this->workspaceCredentials['container'],
            'data/in/files/upload/' . $id1 . '.manifest'
        );
        $blobResult2 = $this->blobClient->getBlob(
            $this->workspaceCredentials['container'],
            'data/in/files/upload/' . $id2
        );
        $manifestResult2 = $this->blobClient->getBlob(
            $this->workspaceCredentials['container'],
            'data/in/files/upload/' . $id2 . '.manifest'
        );

        self::assertEquals('test', stream_get_contents($blobResult1->getContentStream()));
        self::assertEquals('test', stream_get_contents($blobResult2->getContentStream()));

        $manifest1 = json_decode(stream_get_contents($manifestResult1->getContentStream()), true);
        $manifest2 = json_decode(stream_get_contents($manifestResult2->getContentStream()), true);

        self::assertArrayHasKey('id', $manifest1);
        self::assertArrayHasKey('name', $manifest1);
        self::assertArrayHasKey('created', $manifest1);
        self::assertArrayHasKey('is_public', $manifest1);
        self::assertArrayHasKey('is_encrypted', $manifest1);
        self::assertArrayHasKey('tags', $manifest1);
        self::assertArrayHasKey('max_age_days', $manifest1);
        self::assertArrayHasKey('size_bytes', $manifest1);
        self::assertArrayHasKey('is_sliced', $manifest1);
        self::assertFalse($manifest1['is_sliced']);
        self::assertEquals($id1, $manifest1["id"]);
        self::assertEquals($id2, $manifest2["id"]);

        // verify that the workspace contents were preserved
        $blobResult = $this->blobClient->getBlob(
            $this->workspaceCredentials['container'],
            'data/in/tables/sometable.csv'
        );
        self::assertEquals('some data', stream_get_contents($blobResult->getContentStream()));
    }

    public function testAbsReadFilesOverwrite()
    {
        $root = $this->tmpDir;
        file_put_contents($root . '/upload', 'test');

        $id1 = $this->clientWrapper->getBasicClient()->uploadFile(
            $root . '/upload',
            (new FileUploadOptions())->setTags(['download-files-test'])
        );
        sleep(3);
        $reader = new Reader($this->getStagingFactory());

        // upload file for the first time
        $configuration = [['tags' => ['download-files-test'], 'overwrite' => true]];
        $reader->downloadFiles(
            $configuration,
            'data/in/files/',
            StrategyFactory::WORKSPACE_ABS,
            new InputFileStateList([])
        );
        $blobResult1 = $this->blobClient->getBlob(
            $this->workspaceCredentials['container'],
            'data/in/files/upload/' . $id1
        );
        self::assertEquals('test', stream_get_contents($blobResult1->getContentStream()));

        // modify file contents
        $this->blobClient->createBlockBlob(
            $this->workspaceCredentials['container'],
            'data/in/files/upload/' . $id1,
            'some overwritten data'
        );

        // upload file for the second time
        $configuration = [['tags' => ['download-files-test'], 'overwrite' => true]];
        $reader->downloadFiles(
            $configuration,
            'data/in/files/',
            StrategyFactory::WORKSPACE_ABS,
            new InputFileStateList([])
        );
        $blobResult1 = $this->blobClient->getBlob(
            $this->workspaceCredentials['container'],
            'data/in/files/upload/' . $id1
        );
        // should be overwritten back to what it was
        self::assertEquals('test', stream_get_contents($blobResult1->getContentStream()));

        // upload file for the third time, should fail now
        $configuration = [['tags' => ['download-files-test'], 'overwrite' => false]];
        self::expectException(ClientException::class);
        self::expectExceptionMessage('already exists in workspace');
        $reader->downloadFiles(
            $configuration,
            'data/in/files/',
            StrategyFactory::WORKSPACE_ABS,
            new InputFileStateList([])
        );
    }

    public function testReadAbsFilesTagsFilterRunId()
    {
        $root = $this->tmpDir;
        file_put_contents($root . '/upload', 'test');
        $reader = new Reader($this->getStagingFactory());
        $fo = new FileUploadOptions();
        $fo->setTags(['download-files-test']);

        $this->clientWrapper->getBasicClient()->setRunId('xyz');
        $id1 = $this->clientWrapper->getBasicClient()->uploadFile($root . "/upload", $fo);
        $id2 = $this->clientWrapper->getBasicClient()->uploadFile($root . "/upload", $fo);
        $this->clientWrapper->getBasicClient()->setRunId('1234567');
        $id3 = $this->clientWrapper->getBasicClient()->uploadFile($root . "/upload", $fo);
        $id4 = $this->clientWrapper->getBasicClient()->uploadFile($root . "/upload", $fo);
        $this->clientWrapper->getBasicClient()->setRunId('1234567.8901234');
        $id5 = $this->clientWrapper->getBasicClient()->uploadFile($root . "/upload", $fo);
        $id6 = $this->clientWrapper->getBasicClient()->uploadFile($root . "/upload", $fo);
        sleep(5);
        $configuration = [
            [
                'tags' => ['download-files-test'],
                'filter_by_run_id' => true,
                'overwrite' => true,
            ]
        ];

        $reader->downloadFiles(
            $configuration,
            'download',
            StrategyFactory::WORKSPACE_ABS,
            new InputFileStateList([])
        );

        try {
            $this->blobClient->getBlob(
                $this->workspaceCredentials['container'],
                'download/upload/' . $id1
            );
            $this->fail('should have thrown 404');
        } catch (ServiceException $exception) {
            $this->assertEquals(404, $exception->getCode());
        }
        try {
            $this->blobClient->getBlob(
                $this->workspaceCredentials['container'],
                'download/upload/' . $id1 . '.manifest'
            );
            $this->fail('should have thrown 404');
        } catch (ServiceException $exception) {
            $this->assertEquals(404, $exception->getCode());
        }
        try {
            $this->blobClient->getBlob(
                $this->workspaceCredentials['container'],
                'download/upload/' . $id2
            );
            $this->fail('should have thrown 404');
        } catch (ServiceException $exception) {
            $this->assertEquals(404, $exception->getCode());
        }
        try {
            $this->blobClient->getBlob(
                $this->workspaceCredentials['container'],
                'download/upload/' . $id2 . '.manifest'
            );
            $this->fail('should have thrown 404');
        } catch (ServiceException $exception) {
            $this->assertEquals(404, $exception->getCode());
        }
        $this->assertBlobNotEmpty(
            'download/upload/' . $id3
        );
        $this->assertBlobNotEmpty(
            'download/upload/' . $id3 . '.manifest'
        );
        $this->assertBlobNotEmpty(
            'download/upload/' . $id4
        );
        $this->assertBlobNotEmpty(
            'download/upload/' . $id4 . '.manifest'
        );
        $this->assertBlobNotEmpty(
            'download/upload/' . $id5
        );
        $this->assertBlobNotEmpty(
            'download/upload/' . $id5 . '.manifest'
        );
        $this->assertBlobNotEmpty(
            'download/upload/' . $id6
        );
        $this->assertBlobNotEmpty(
            'download/upload/' . $id6 . '.manifest'
        );
    }

    public function testReadFilesEsQueryFilterRunId()
    {
        $root = $this->tmpDir;
        file_put_contents($root . "/upload", "test");
        $reader = new Reader($this->getStagingFactory());
        $fo = new FileUploadOptions();
        $fo->setTags(["download-files-test"]);

        $this->clientWrapper->getBasicClient()->setRunId('xyz');
        $id1 = $this->clientWrapper->getBasicClient()->uploadFile($root . "/upload", $fo);
        $id2 = $this->clientWrapper->getBasicClient()->uploadFile($root . "/upload", $fo);
        $this->clientWrapper->getBasicClient()->setRunId('1234567');
        $id3 = $this->clientWrapper->getBasicClient()->uploadFile($root . "/upload", $fo);
        $id4 = $this->clientWrapper->getBasicClient()->uploadFile($root . "/upload", $fo);
        $this->clientWrapper->getBasicClient()->setRunId('1234567.8901234');
        $id5 = $this->clientWrapper->getBasicClient()->uploadFile($root . "/upload", $fo);
        $id6 = $this->clientWrapper->getBasicClient()->uploadFile($root . "/upload", $fo);
        sleep(5);
        $configuration = [
            [
                'query' => 'tags: download-files-test',
                'filter_by_run_id' => true,
                'overwrite' => true,
            ]
        ];
        $reader->downloadFiles(
            $configuration,
            'download',
            StrategyFactory::WORKSPACE_ABS,
            new InputFileStateList([])
        );
        try {
            $this->blobClient->getBlob(
                $this->workspaceCredentials['container'],
                "download/upload/" . $id1
            );
            $this->fail('should have thrown 404');
        } catch (ServiceException $exception) {
            $this->assertEquals(404, $exception->getCode());
        }
        try {
            $this->blobClient->getBlob(
                $this->workspaceCredentials['container'],
                'download/upload/' . $id1 . '.manifest'
            );
            $this->fail('should have thrown 404');
        } catch (ServiceException $exception) {
            $this->assertEquals(404, $exception->getCode());
        }
        try {
            $this->blobClient->getBlob(
                $this->workspaceCredentials['container'],
                $root . 'download/upload/' . $id2
            );
            $this->fail('should have thrown 404');
        } catch (ServiceException $exception) {
            $this->assertEquals(404, $exception->getCode());
        }
        try {
            $this->blobClient->getBlob(
                $this->workspaceCredentials['container'],
                "download/uppload/" . $id2 . '.manifest'
            );
            $this->fail('should have thrown 404');
        } catch (ServiceException $exception) {
            $this->assertEquals(404, $exception->getCode());
        }
        $this->assertBlobNotEmpty(
            'download/upload/' . $id3
        );
        $this->assertBlobNotEmpty(
            'download/upload/' . $id3 . '.manifest'
        );
        $this->assertBlobNotEmpty(
            'download/upload/' . $id4
        );
        $this->assertBlobNotEmpty(
            'download/upload/' . $id4 . '.manifest'
        );
        $this->assertBlobNotEmpty(
            'download/upload/' . $id5
        );
        $this->assertBlobNotEmpty(
            'download/upload/' . $id5 . '.manifest'
        );
        $this->assertBlobNotEmpty(
            'download/upload/' . $id6
        );
        $this->assertBlobNotEmpty(
            'download/upload/' . $id6 . '.manifest'
        );
    }

    public function testAbsWorkspaceAdaptiveInput()
    {
        $root = $this->tmpDir;
        file_put_contents($root . "/upload", "test");
        $reader = new Reader($this->getStagingFactory());
        $fo = new FileUploadOptions();
        $fo->setTags(['download-files-test']);

        $id1 = $this->clientWrapper->getBasicClient()->uploadFile($root . "/upload", $fo);
        $id2 = $this->clientWrapper->getBasicClient()->uploadFile($root . "/upload", $fo);
        sleep(2);
        $configuration = [[
            'tags' => ['download-files-test'],
            'changed_since' => InputTableOptions::ADAPTIVE_INPUT_MAPPING_VALUE,
            'overwrite' => true,
        ]];
        $outputFileStateList = $reader->downloadFiles(
            $configuration,
            'download',
            StrategyFactory::WORKSPACE_ABS,
            new InputFileStateList([])
        );
        $tagList = [
            [
                'name' => 'download-files-test',
            ],
        ];
        $lastFileState = $outputFileStateList->getFile($tagList);
        self::assertEquals($id2, $lastFileState->getLastImportId());
        // make sure the files are there
        $this->assertBlobNotEmpty(
            'download/upload/' . $id1
        );
        $this->assertBlobNotEmpty(
            'download/upload/' . $id1 . '.manifest'
        );
        $this->assertBlobNotEmpty(
            'download/upload/' . $id2
        );
        $this->assertBlobNotEmpty(
            'download/upload/' . $id2 . '.manifest'
        );

        $id3 = $this->clientWrapper->getBasicClient()->uploadFile($root . "/upload", $fo);
        $id4 = $this->clientWrapper->getBasicClient()->uploadFile($root . "/upload", $fo);
        sleep(2);

        $newOutputFileStateList = $reader->downloadFiles(
            $configuration,
            'download-adaptive',
            StrategyFactory::WORKSPACE_ABS,
            $outputFileStateList
        );
        $lastFileState = $newOutputFileStateList->getFile($tagList);
        self::assertEquals($id4, $lastFileState->getLastImportId());
        try {
            $this->blobClient->getBlob(
                $this->workspaceCredentials['container'],
                "download-adaptive/upload/" . $id1
            );
            $this->fail('should have thrown 404');
        } catch (ServiceException $exception) {
            $this->assertEquals(404, $exception->getCode());
        }
        try {
            $this->blobClient->getBlob(
                $this->workspaceCredentials['container'],
                'download-adaptive/upload/' . $id1 . '.manifest'
            );
            $this->fail('should have thrown 404');
        } catch (ServiceException $exception) {
            $this->assertEquals(404, $exception->getCode());
        }
        try {
            $this->blobClient->getBlob(
                $this->workspaceCredentials['container'],
                $root . 'download-adaptive/upload/' . $id2
            );
            $this->fail('should have thrown 404');
        } catch (ServiceException $exception) {
            $this->assertEquals(404, $exception->getCode());
        }
        try {
            $this->blobClient->getBlob(
                $this->workspaceCredentials['container'],
                "download-adaptive/uppload/" . $id2 . '.manifest'
            );
            $this->fail('should have thrown 404');
        } catch (ServiceException $exception) {
            $this->assertEquals(404, $exception->getCode());
        }
        $this->assertBlobNotEmpty(
            'download-adaptive/upload/' . $id3
        );
        $this->assertBlobNotEmpty(
            'download-adaptive/upload/' . $id3 . '.manifest'
        );
        $this->assertBlobNotEmpty(
            'download-adaptive/upload/' . $id4
        );
        $this->assertBlobNotEmpty(
            'download-adaptive/upload/' . $id4 . '.manifest'
        );
    }
}
