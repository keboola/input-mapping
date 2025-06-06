<?php

declare(strict_types=1);

namespace Keboola\InputMapping\File;

use Keboola\InputMapping\State\InputFileStateList;
use Keboola\StagingProvider\Staging\File\FileFormat;
use Keboola\StagingProvider\Staging\File\FileStagingInterface;
use Keboola\StorageApiBranch\ClientWrapper;
use Psr\Log\LoggerInterface;

interface StrategyInterface
{
    public function __construct(
        ClientWrapper $clientWrapper,
        LoggerInterface $logger,
        FileStagingInterface $dataStorage,
        FileStagingInterface $metadataStorage,
        InputFileStateList $fileStateList,
        FileFormat $format,
    );

    public function downloadFile(
        array $fileInfo,
        string $sourceBranchId,
        string $destinationPath,
        bool $overwrite,
    ): void;

    public function downloadFiles(array $fileConfigurations, string $destination): InputFileStateList;
}
