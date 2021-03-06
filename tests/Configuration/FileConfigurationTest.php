<?php

namespace Keboola\InputMapping\Tests\Configuration;

use Keboola\InputMapping\Configuration\File;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

class FileConfigurationTest extends \PHPUnit_Framework_TestCase
{
    public function testConfiguration()
    {
        $config = [
            'tags' => ['tag1', 'tag2'],
            'query' => 'esquery',
            'processed_tags' => ['tag3'],
            'filter_by_run_id' => true,
            'limit' => 1000,
            'overwrite' => false,
        ];
        $expectedResponse = $config;
        $processedConfiguration = (new File())->parse(['config' => $config]);
        self::assertEquals($expectedResponse, $processedConfiguration);
    }

    public function testEmptyTagsRemoved()
    {
        $config = [
            'tags' => [],
            'query' => 'esquery',
            'processed_tags' => ['tag3'],
            'filter_by_run_id' => true,
            'limit' => 1000,
            'overwrite' => true,
        ];
        $expectedResponse = $config;
        unset($expectedResponse['tags']);
        $processedConfiguration = (new File())->parse([
            'config' => $config,
        ]);
        self::assertEquals($expectedResponse, $processedConfiguration);
    }

    public function testEmptyProcessedTagsRemoved()
    {
        $config = [
            'tags' => ['tag3'],
            'query' => 'esquery',
            'processed_tags' => [],
            'filter_by_run_id' => true,
            'limit' => 1000,
            'overwrite' => true,
        ];
        $expectedResponse = $config;
        unset($expectedResponse['processed_tags']);
        $processedConfiguration = (new File())->parse([
            'config' => $config,
        ]);
        self::assertEquals($expectedResponse, $processedConfiguration);
    }

    public function testEmptyQueryRemoved()
    {
        $config = [
            'tags' => ['tag1'],
            'query' => '',
            'processed_tags' => ['tag3'],
            'filter_by_run_id' => true,
            'limit' => 1000,
            'overwrite' => true,
        ];
        $expectedResponse = $config;
        unset($expectedResponse['query']);
        $processedConfiguration = (new File())->parse([
            'config' => $config,
        ]);
        self::assertEquals($expectedResponse, $processedConfiguration);
    }

    public function testConfigurationWithSourceTags()
    {
        $config = [
            'query' => 'esquery',
            'processed_tags' => ['tag3'],
            'filter_by_run_id' => true,
            'limit' => 1000,
            'source' => [
                'tags' => [
                    [
                        'name' => 'tag1',
                        'match' => 'include',
                    ],
                    [
                        'name' => 'tag2',
                        'match' => 'include',
                    ],
                ],
            ],
            'overwrite' => true,
        ];
        $expectedResponse = $config;
        $processedConfiguration = (new File())->parse(["config" => $config]);
        self::assertEquals($expectedResponse, $processedConfiguration);
    }

    /**
     * @expectedException \Symfony\Component\Config\Definition\Exception\InvalidConfigurationException
     * @expectedExceptionMessage Invalid configuration for path "file": At least one of "tags", "source.tags" or "query" parameters must be defined.
     */
    public function testEmptyConfiguration()
    {
        (new File())->parse(["config" => []]);
    }

    /**
     * @expectedException \Symfony\Component\Config\Definition\Exception\InvalidConfigurationException
     * @expectedExceptionMessage Invalid configuration for path "file": Both "tags" and "source.tags" cannot be defined.
     */
    public function testConfigurationWithTagsAndSourceTags()
    {
        (new File())->parse(["config" => [
            "tags" => ["tag1"],
            "source" => [
                "tags" => [
                    [
                        "name" => "tag1"
                    ],
                    [
                        "name" => "tag2"
                    ]
                ]
            ],
        ]]);
    }

    public function testValidAdaptiveInputConfigurationWithTags()
    {
        $config = [
            'tags' => ['tag'],
            'changed_since' => 'adaptive',
            'overwrite' => true,
        ];
        $expectedResponse = $config;
        $processedConfiguration = (new File())->parse(["config" => $config]);
        self::assertEquals($expectedResponse, $processedConfiguration);
    }

    public function testOverwriteDefault()
    {
        $config = [
            'tags' => ['tag'],
            'changed_since' => 'adaptive',
        ];
        $expectedResponse = $config;
        $expectedResponse['overwrite'] = true;
        $processedConfiguration = (new File())->parse(["config" => $config]);
        self::assertEquals($expectedResponse, $processedConfiguration);
    }

    public function testValidAdaptiveInputConfigurationWithSourceTags()
    {
        $config = [
            'source' => [
                'tags' => [
                    [
                        'name' => 'tag',
                        'match' => 'include',
                    ],
                ],
            ],
            'changed_since' => 'adaptive',
            'overwrite' => true,
        ];
        $expectedResponse = $config;
        $processedConfiguration = (new File())->parse(["config" => $config]);
        self::assertEquals($expectedResponse, $processedConfiguration);
    }

    public function testConfigurationWithQueryAndChangedSince()
    {
        self::expectException(InvalidConfigurationException::class);
        self::expectExceptionMessage('The changed_since parameter is not supported for query configurations');
        (new File())->parse(['config' => [
            'query' => 'some query',
            'changed_since' => 'adaptive',
        ]]);
    }

    public function testConfigurationWithInvalidChangedSince()
    {
        self::expectException(InvalidConfigurationException::class);
        self::expectExceptionMessage('The value provided for changed_since could not be converted to a timestamp');
        (new File())->parse(['config' => [
            'tags' => ['tag123'],
            'changed_since' => '-1 light year',
        ]]);
    }
}
