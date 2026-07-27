<?php

declare(strict_types=1);

namespace Keboola\InputMapping\Tests\File\Strategy;

use Keboola\InputMapping\Exception\InputOperationException;
use Keboola\InputMapping\Exception\InvalidInputException;
use Keboola\InputMapping\File\Strategy\Local;
use Keboola\InputMapping\State\InputFileStateList;
use Keboola\StagingProvider\Staging\File\FileFormat;
use Keboola\StagingProvider\Staging\File\FileStagingInterface;
use Keboola\StorageApi\BranchAwareClient;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApiBranch\Branch;
use Keboola\StorageApiBranch\ClientWrapper;
use Keboola\StorageApiBranch\Factory\ClientOptions;
use Keboola\Temp\Temp;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

class FileDownloadErrorTest extends TestCase
{
    private function createStrategy(BranchAwareClient $storageClient): Local
    {
        $basicClient = $this->createMock(Client::class);
        $basicClient->method('getRunId')->willReturn('run-123');

        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('isDevelopmentBranch')->willReturn(false);
        $clientWrapper->method('getClientOptionsReadOnly')->willReturn(new ClientOptions());
        $clientWrapper->method('getTableAndFileStorageClient')->willReturn($basicClient);
        $clientWrapper->method('getClientForBranch')->willReturn($storageClient);
        $clientWrapper->method('getDefaultBranch')->willReturn(new Branch('123', 'main', true, null));

        $temp = new Temp('input-mapping');
        $staging = $this->createMock(FileStagingInterface::class);
        $staging->method('getPath')->willReturn($temp->getTmpFolder());

        return new Local(
            $clientWrapper,
            new NullLogger(),
            $staging,
            $staging,
            new InputFileStateList([]),
            FileFormat::Json,
        );
    }

    private function createStorageClient(callable $downloadFileCallback): BranchAwareClient
    {
        $storageClient = $this->createMock(BranchAwareClient::class);
        $storageClient->method('listFiles')->willReturn([['id' => 123, 'name' => 'my-file.csv']]);
        $storageClient->method('getFile')->willReturn([
            'id' => 123,
            'name' => 'my-file.csv',
            'isSliced' => false,
        ]);
        $storageClient->method('downloadFile')->willReturnCallback($downloadFileCallback);

        return $storageClient;
    }

    public function testStorageClientExceptionIsReportedAsUserError(): void
    {
        $storageClient = $this->createStorageClient(function (): void {
            throw new ClientException(
                'Cannot download file "my-file.csv" (ID 123) from Storage, ' .
                'please verify the contents of the file and that the file has not expired.',
                404,
            );
        });
        $strategy = $this->createStrategy($storageClient);

        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage(
            'Failed to download file my-file.csv (123): Cannot download file "my-file.csv" (ID 123) from Storage, ' .
            'please verify the contents of the file and that the file has not expired.',
        );

        $strategy->downloadFiles([['tags' => ['my-tag'], 'overwrite' => true]], 'download');
    }

    public function testUnexpectedErrorIsReportedAsApplicationError(): void
    {
        $storageClient = $this->createStorageClient(function (): void {
            throw new RuntimeException('Something went wrong internally.');
        });
        $strategy = $this->createStrategy($storageClient);

        $this->expectException(InputOperationException::class);
        $this->expectExceptionMessage(
            'Failed to download file my-file.csv (123): Something went wrong internally.',
        );

        $strategy->downloadFiles([['tags' => ['my-tag'], 'overwrite' => true]], 'download');
    }
}
