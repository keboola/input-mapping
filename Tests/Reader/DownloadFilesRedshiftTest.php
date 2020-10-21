<?php

namespace Keboola\InputMapping\Tests\Reader;

use Keboola\Csv\CsvFile;
use Keboola\InputMapping\Configuration\File\Manifest\Adapter;
use Keboola\InputMapping\Reader\NullWorkspaceProvider;
use Keboola\InputMapping\Reader\Reader;
use Keboola\StorageApi\Client;
use Psr\Log\NullLogger;
use Symfony\Component\Finder\Finder;

class DownloadFilesRedshiftTest extends DownloadFilesTestAbstract
{
    public function testReadSlicedFile()
    {
        // Create bucket
        if (!$this->client->bucketExists("in.c-docker-test-redshift")) {
            $this->client->createBucket("docker-test-redshift", Client::STAGE_IN, "Docker Testsuite", "redshift");
        }

        // Create redshift table and export it to produce a sliced file
        if (!$this->client->tableExists("in.c-docker-test-redshift.test_file")) {
            $csv = new CsvFile($this->tmpDir . "/upload.csv");
            $csv->writeRow(["Id", "Name"]);
            $csv->writeRow(["test", "test"]);
            $this->client->createTableAsync("in.c-docker-test-redshift", "test_file", $csv);
        }
        $table = $this->client->exportTableAsync('in.c-docker-test-redshift.test_file');
        $fileId = $table['file']['id'];

        $reader = new Reader($this->client, new NullLogger(), new NullWorkspaceProvider());
        $configuration = [['query' => 'id: ' . $fileId]];

        $dlDir = $this->tmpDir . "/download";
        $reader->downloadFiles($configuration, $dlDir);
        $fileName = $fileId . "_in.c-docker-test-redshift.test_file.csv";

        $resultFileContent = '';
        $finder = new Finder();

        /** @var \SplFileInfo $file */
        foreach ($finder->files()->in($dlDir . '/' . $fileName) as $file) {
            $resultFileContent .= file_get_contents($file->getPathname());
        }

        self::assertEquals('"test","test"' . "\n", $resultFileContent);

        $manifestFile = $dlDir . "/" . $fileName . ".manifest";
        self::assertFileExists($manifestFile);
        $adapter = new Adapter();
        $manifest = $adapter->readFromFile($manifestFile);
        self::assertArrayHasKey('is_sliced', $manifest);
        self::assertTrue($manifest['is_sliced']);
    }
}