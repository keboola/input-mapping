<?php

namespace Keboola\InputMapping\Tests\Reader;

use Keboola\StorageApi\Client;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\Temp\Temp;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

class DownloadFilesTestAbstract extends \PHPUnit_Framework_TestCase
{
    /** @var Client */
    protected $client;

    /** @var string */
    protected $tmpDir;

    /** @var Temp */
    protected $temp;

    public function setUp()
    {
        // Create folders
        $temp = new Temp('docker');
        $temp->initRunFolder();
        $this->temp = $temp;
        $this->tmpDir = $temp->getTmpFolder();
        $fs = new Filesystem();
        $fs->mkdir($this->tmpDir . "/download");
        $this->client = new Client(["token" => STORAGE_API_TOKEN, "url" => STORAGE_API_URL]);
        $tokenInfo = $this->client->verifyToken();
        print(sprintf(
            'Authorized as "%s (%s)" to project "%s (%s)" at "%s" stack.',
            $tokenInfo['description'],
            $tokenInfo['id'],
            $tokenInfo['owner']['name'],
            $tokenInfo['owner']['id'],
            $this->client->getApiUrl()
        ));

        // Delete file uploads
        sleep(5);
        $options = new ListFilesOptions();
        $options->setTags(["download-files-test"]);
        $options->setLimit(1000);
        $files = $this->client->listFiles($options);
        foreach ($files as $file) {
            $this->client->deleteFile($file["id"]);
        }
    }
}