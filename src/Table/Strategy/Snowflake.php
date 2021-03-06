<?php

namespace Keboola\InputMapping\Table\Strategy;

use Keboola\InputMapping\Helper\LoadTypeDecider;
use Keboola\InputMapping\Table\Options\InputTableOptions;

class Snowflake extends AbstractStrategy
{
    public function downloadTable(InputTableOptions $table)
    {
        $tableInfo = $this->clientWrapper->getBasicClient()->getTable($table->getSource());
        $loadOptions = $table->getStorageApiLoadOptions($this->tablesState);
        if (LoadTypeDecider::canClone($tableInfo, 'snowflake', $loadOptions)) {
            $this->logger->info(sprintf('Table "%s" will be cloned.', $table->getSource()));
            return [
                'table' => $table,
                'type' => 'clone'
            ];
        }
        $this->logger->info(sprintf('Table "%s" will be copied.', $table->getSource()));
        return [
            'table' => [$table, $loadOptions],
            'type' => 'copy',
        ];
    }

    public function handleExports($exports)
    {
        $cloneInputs = [];
        $copyInputs = [];

        foreach ($exports as $export) {
            if ($export['type'] === 'clone') {
                /** @var InputTableOptions $table */
                $table = $export['table'];
                $cloneInputs[] = [
                    'source' => $table->getSource(),
                    'destination' => $table->getDestination(),
                    'overwrite' => $table->getOverwrite(),
                ];
                $workspaceTables[] = $table;
            }
            if ($export['type'] === 'copy') {
                list ($table, $exportOptions) = $export['table'];
                $copyInput = array_merge(
                    [
                        'source' => $table->getSource(),
                        'destination' => $table->getDestination()
                    ],
                    $exportOptions
                );

                if ($table->isUseView()) {
                    $copyInput['useView'] = true;
                }

                $workspaceTables[] = $table;
                $copyInputs[] = $copyInput;
            }
        }

        $workspaceJobs = [];
        if ($cloneInputs) {
            $this->logger->info(
                sprintf('Cloning %s tables to workspace.', count($cloneInputs))
            );
            $job = $this->clientWrapper->getBranchClientIfAvailable()->apiPost(
                'workspaces/' . $this->dataStorage->getWorkspaceId() . '/load-clone',
                [
                    'input' => $cloneInputs,
                    'preserve' => 1,
                ],
                false
            );
            $workspaceJobs[] = $job['id'];
        }

        if ($copyInputs) {
            $this->logger->info(
                sprintf('Copying %s tables to workspace.', count($copyInputs))
            );
            $job = $this->clientWrapper->getBranchClientIfAvailable()->apiPost(
                'workspaces/' . $this->dataStorage->getWorkspaceId() . '/load',
                [
                    'input' => $copyInputs,
                    'preserve' => 1,
                ],
                false
            );
            $workspaceJobs[] = $job['id'];
        }

        if ($workspaceJobs) {
            $this->logger->info('Processing ' . count($workspaceJobs) . ' workspace exports.');
            $this->clientWrapper->getBasicClient()->handleAsyncTasks($workspaceJobs);
            foreach ($workspaceTables as $table) {
                $manifestPath = $this->ensurePathDelimiter($this->metadataStorage->getPath()) .
                    $this->getDestinationFilePath($this->destination, $table) . ".manifest";
                $tableInfo = $this->clientWrapper->getBasicClient()->getTable($table->getSource());
                $this->manifestCreator->writeTableManifest(
                    $tableInfo,
                    $manifestPath,
                    $table->getColumnNames(),
                    $this->format
                );
            }
        }
    }
}
