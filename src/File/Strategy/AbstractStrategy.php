<?php

declare(strict_types=1);

namespace Keboola\InputMapping\File\Strategy;

use Keboola\InputMapping\Exception\FileNotFoundException;
use Keboola\InputMapping\Exception\InputOperationException;
use Keboola\InputMapping\File\StrategyInterface;
use Keboola\InputMapping\Helper\ManifestCreator;
use Keboola\InputMapping\Reader;
use Keboola\InputMapping\State\InputFileStateList;
use Keboola\StagingProvider\Staging\File\FileFormat;
use Keboola\StagingProvider\Staging\File\FileStagingInterface;
use Keboola\StorageApi\Options\GetFileOptions;
use Keboola\StorageApiBranch\ClientWrapper;
use Psr\Log\LoggerInterface;
use Throwable;

abstract class AbstractStrategy implements StrategyInterface
{
    protected string $destination;
    protected ManifestCreator $manifestCreator;

    public function __construct(
        protected readonly ClientWrapper $clientWrapper,
        protected readonly LoggerInterface $logger,
        protected readonly FileStagingInterface $dataStorage,
        protected readonly FileStagingInterface $metadataStorage,
        protected readonly InputFileStateList $fileStateList,
        protected readonly FileFormat $format,
    ) {
        $this->manifestCreator = new ManifestCreator();
    }

    protected function ensurePathDelimiter(string $path): string
    {
        return $this->ensureNoPathDelimiter($path) . '/';
    }

    protected function ensureNoPathDelimiter(string $path): string
    {
        return rtrim($path, '\\/');
    }

    abstract protected function getFileDestinationPath(
        string $destinationPath,
        int $fileId,
        string $fileName,
    ): string;

    public function downloadFiles(array $fileConfigurations, string $destination): InputFileStateList
    {
        $fileOptions = new GetFileOptions();
        $fileOptions->setFederationToken(true);
        $outputStateList = [];
        foreach ($fileConfigurations as $fileConfiguration) {
            $fileOptionsRewritten = Reader::getFiles($fileConfiguration, $this->clientWrapper, $this->logger);
            $options = $fileOptionsRewritten->getStorageApiFileListOptions($this->fileStateList);
            $storageClient = $this->clientWrapper->getClientForBranch(
                (string) $fileOptionsRewritten->getSourceBranchId(),
            );
            $files = $storageClient->listFiles($options);

            $biggestFileId = 0;
            try {
                $currentState = $this->fileStateList->getFile(
                    $this->fileStateList->getFileConfigurationIdentifier($fileConfiguration),
                );
                $outputStateConfiguration = [
                    'tags' => $currentState->getTags(),
                    'lastImportId' => $currentState->getLastImportId(),
                ];
            } catch (FileNotFoundException) {
                $outputStateConfiguration = [];
            }
            foreach ($files as $file) {
                $fileInfo = $storageClient->getFile($file['id'], $fileOptions);
                $fileDestinationPath = $this->getFileDestinationPath($destination, $fileInfo['id'], $fileInfo['name']);
                $overwrite = $fileConfiguration['overwrite'];

                if ($fileInfo['id'] > $biggestFileId) {
                    $outputStateConfiguration = [
                        'tags' => $this->fileStateList->getFileConfigurationIdentifier($fileConfiguration),
                        'lastImportId' => $fileInfo['id'],
                    ];
                    $biggestFileId = (int) $fileInfo['id'];
                }
                try {
                    $this->downloadFile(
                        $fileInfo,
                        (string) $fileOptionsRewritten->getSourceBranchId(),
                        $fileDestinationPath,
                        $overwrite,
                    );
                } catch (Throwable $e) {
                    throw new InputOperationException(
                        sprintf(
                            'Failed to download file %s (%s): %s',
                            $fileInfo['name'],
                            $file['id'],
                            $e->getMessage(),
                        ),
                        0,
                        $e,
                    );
                }
                $this->logger->info(sprintf('Fetched file "%s".', basename($fileDestinationPath)));
            }
            if (!empty($outputStateConfiguration)) {
                $outputStateList[] = $outputStateConfiguration;
            }
        }
        $this->logger->info('All files were fetched.');
        return new InputFileStateList($outputStateList);
    }
}
