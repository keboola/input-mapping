<?php

declare(strict_types=1);

namespace Keboola\InputMapping\Tests\Functional;

use Keboola\InputMapping\Configuration\File\Manifest\Adapter;
use Keboola\InputMapping\Reader;
use Keboola\InputMapping\State\InputFileStateList;
use Keboola\InputMapping\Tests\Needs\NeedsDevBranch;
use Keboola\StorageApi\Options\FileUploadOptions;

class DownloadFilesAdaptiveBranchTest extends AbstractDownloadFilesTest
{
    #[NeedsDevBranch]
    public function testReadFilesAdaptiveWithBranch(): void
    {
        $clientWrapper = $this->initClient($this->devBranchId);

        $root = $this->temp->getTmpFolder();
        file_put_contents($root . '/upload', 'test');

        $branchTag = sprintf('%s-%s', $this->devBranchId, $this->testFileTagForBranch);

        $file1Id = $clientWrapper->getTableAndFileStorageClient()->uploadFile(
            $root . '/upload',
            (new FileUploadOptions())->setTags([$branchTag]),
        );
        $file2Id = $clientWrapper->getTableAndFileStorageClient()->uploadFile(
            $root . '/upload',
            (new FileUploadOptions())->setTags([$this->testFileTagForBranch]),
        );
        sleep(2);

        $convertedTags = [
            [
                'name' => $this->testFileTagForBranch,
            ], [
                'name' => $this->testFileTagForBranch . '-adaptive',
            ],
        ];

        $reader = new Reader(
            $clientWrapper,
            $this->testLogger,
            $this->getLocalStagingFactory(
                clientWrapper: $clientWrapper,
                logger: $this->testLogger,
            ),
        );

        $configuration = [
            [
                'tags' => [$this->testFileTagForBranch, $this->testFileTagForBranch . '-adaptive'],
                'changed_since' => 'adaptive',
                'overwrite' => true,
            ],
        ];
        $outputStateFileList = $reader->downloadFiles(
            $configuration,
            'download',
            new InputFileStateList([]),
        );
        $lastFileState = $outputStateFileList->getFile($convertedTags);
        self::assertEquals($file1Id, $lastFileState->getLastImportId());
        self::assertEquals('test', file_get_contents($root . '/download/' . $file1Id . '_upload'));
        self::assertFileDoesNotExist($root . '/download/' . $file2Id . '_upload');

        $adapter = new Adapter();
        $manifest1 = $adapter->readFromFile($root . '/download/' . $file1Id . '_upload.manifest');

        self::assertArrayHasKey('id', $manifest1);
        self::assertArrayHasKey('tags', $manifest1);
        self::assertEquals($file1Id, $manifest1['id']);
        self::assertEquals([$branchTag], $manifest1['tags']);

        $expectedMessageTemplate = 'Using dev tags "{devBranchId}-{fileTag}, {devBranchId}-{fileTag}-adaptive" ' .
            'instead of "{fileTag}, {fileTag}-adaptive".';

        $replacements = [
            '{devBranchId}' => $this->devBranchId,
            '{fileTag}'     => $this->testFileTagForBranch,
        ];

        self::assertTrue($this->testHandler->hasInfoThatContains(strtr($expectedMessageTemplate, $replacements)));
        // add another valid file and assert that it gets downloaded and the previous doesn't
        $file3Id = $clientWrapper->getTableAndFileStorageClient()->uploadFile(
            $root . '/upload',
            (new FileUploadOptions())->setTags([$branchTag, sprintf('%s-adaptive', $this->devBranchId)]),
        );
        sleep(2);
        $newOutputStateFileList = $reader->downloadFiles(
            $configuration,
            'download-adaptive',
            $outputStateFileList,
        );
        $lastFileState = $newOutputStateFileList->getFile($convertedTags);
        self::assertEquals($file3Id, $lastFileState->getLastImportId());
        self::assertEquals('test', file_get_contents($root . '/download-adaptive/' . $file3Id . '_upload'));
        self::assertFileDoesNotExist($root . '/download-adaptive/' . $file1Id . '_upload');
    }
}
