<?php

namespace Keboola\InputMapping\Helper;

use Keboola\InputMapping\Configuration\Table\Manifest\Adapter as TableAdapter;
use Keboola\InputMapping\Exception\InputOperationException;
use Keboola\InputMapping\Exception\InvalidInputException;
use Keboola\StorageApi\Client;

class ManifestCreator
{
    /** @var Client */
    protected $storageClient;

    public function __construct(Client $storageClient)
    {
        $this->storageClient = $storageClient;
    }

    /**
     * @param array $tableInfo
     * @param string $destination
     * @param array $columns
     * @param string $format
     */
    public function writeTableManifest($tableInfo, $destination, $columns, $format = 'json')
    {
        $manifest = [
            "id" => $tableInfo["id"],
            "uri" => $tableInfo["uri"],
            "name" => $tableInfo["name"],
            "primary_key" => $tableInfo["primaryKey"],
            "distribution_key" => $tableInfo['distributionKey'],
            "created" => $tableInfo["created"],
            "last_change_date" => $tableInfo["lastChangeDate"],
            "last_import_date" => $tableInfo["lastImportDate"],
        ];
        if (isset($tableInfo["s3"])) {
            $manifest["s3"] = $tableInfo["s3"];
        }
        if (isset($tableInfo["abs"])) {
            $manifest["abs"] = $tableInfo["abs"];
        }
        if (!$columns) {
            $columns = $tableInfo["columns"];
        }
        $manifest["columns"] = $columns;

        $manifest['metadata'] = $tableInfo['metadata'];
        foreach ($columns as $column) {
            $columnMetadata = isset($tableInfo['columnMetadata'][$column]) ? $tableInfo['columnMetadata'][$column] : [];
            $manifest['column_metadata'][$column] = $columnMetadata;
        }

        $adapter = new TableAdapter($format);
        try {
            $adapter->setConfig($manifest);
            $adapter->writeToFile($destination);
        } catch (InvalidInputException $e) {
            throw new InputOperationException(
                "Failed to write manifest for table {$tableInfo['id']} - {$tableInfo['name']}.",
                $e
            );
        }
    }

    /**
     * @param $fileInfo
     * @param $destination
     * @return array manifest
     */
    public function createFileManifest($fileInfo)
    {
        $manifest = [
            "id" => $fileInfo["id"],
            "name" => $fileInfo["name"],
            "created" => $fileInfo["created"],
            "is_public" => $fileInfo["isPublic"],
            "is_encrypted" => $fileInfo["isEncrypted"],
            "is_sliced" => $fileInfo["isSliced"],
            "tags" => $fileInfo["tags"],
            "max_age_days" => $fileInfo["maxAgeDays"],
            "size_bytes" => intval($fileInfo["sizeBytes"])
        ];
        return $manifest;
    }
}
