<?php

namespace Appwrite\Migration;

use Swoole\Runtime;
use Utopia\Database\Document;
use Utopia\Database\Database;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Exception;
use Utopia\App;
use Utopia\Database\Validator\Authorization;

abstract class Migration
{
    /**
     * @var int
     */
    protected int $limit = 100;

    /**
     * @var Document
     */
    protected Document $project;

    /**
     * @var Database
     */
    protected Database $projectDB;

    /**
     * @var Database
     */
    protected Database $consoleDB;

    /**
     * @var array
     */
    public static array $versions = [
        '0.13.0' => 'V12',
        '0.13.1' => 'V12',
        '0.13.2' => 'V12',
        '0.13.3' => 'V12',
        '0.13.4' => 'V12',
        '0.14.0' => 'V13',
        '0.14.1' => 'V13',
        '0.14.2' => 'V13',
        '0.15.0' => 'V14',
        '0.15.1' => 'V14',
        '0.15.2' => 'V14'
    ];

    /**
     * @var array
     */
    protected array $collections;

    public function __construct()
    {
        Authorization::disable();
        Authorization::setDefaultStatus(false);
        $this->collections = array_merge([
            '_metadata' => [
                '$id' => '_metadata',
                '$collection' => Database::METADATA
            ],
            'audit' => [
                '$id' => 'audit',
                '$collection' => Database::METADATA
            ],
            'abuse' => [
                '$id' => 'abuse',
                '$collection' => Database::METADATA
            ]
        ], Config::getParam('collections', []));
    }

    /**
     * Set project for migration.
     *
     * @param Document $project
     * @param Database $projectDB
     * @param Database $oldConsoleDB
     *
     * @return self
     */
    public function setProject(Document $project, Database $projectDB, Database $consoleDB): self
    {
        $this->project = $project;
        $this->projectDB = $projectDB;
        $this->projectDB->setNamespace('_' . $this->project->getId());

        $this->consoleDB = $consoleDB;

        return $this;
    }

    /**
     * Iterates through every document.
     *
     * @param callable $callback
     */
    public function forEachDocument(callable $callback): void
    {
        Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

        foreach ($this->collections as $collection) {
            if ($collection['$collection'] !== Database::METADATA) {
                continue;
            }

            $sum = 0;
            $nextDocument = null;
            $collectionCount = $this->projectDB->count($collection['$id']);
            Console::log('Migrating Collection ' . $collection['$id'] . ':');

            do {
                $documents = $this->projectDB->find($collection['$id'], limit: $this->limit, cursor: $nextDocument);
                $count = count($documents);
                $sum += $count;

                Console::log($sum . ' / ' . $collectionCount);

                \Co\run(function (array $documents, callable $callback) {
                    foreach ($documents as $document) {
                        go(function (Document $document, callable $callback) {
                            if (empty($document->getId()) || empty($document->getCollection())) {
                                return;
                            }

                            $old = $document->getArrayCopy();
                            $new = call_user_func($callback, $document);

                            if (is_null($new) || !self::hasDifference($new->getArrayCopy(), $old)) {
                                return;
                            }

                            try {
                                $new = $this->projectDB->updateDocument($document->getCollection(), $document->getId(), $document);
                            } catch (\Throwable $th) {
                                Console::error('Failed to update document: ' . $th->getMessage());
                                return;

                                if ($document && $new->getId() !== $document->getId()) {
                                    throw new Exception('Duplication Error');
                                }
                            }
                        }, $document, $callback);
                    }
                }, $documents, $callback);

                if ($count !== $this->limit) {
                    $nextDocument = null;
                } else {
                    $nextDocument = end($documents);
                }
            } while (!is_null($nextDocument));
        }
    }

    /**
     * Checks 2 arrays for differences.
     *
     * @param array $array1
     * @param array $array2
     * @return bool
     */
    public static function hasDifference(array $array1, array $array2): bool
    {
        foreach ($array1 as $key => $value) {
            if (is_array($value)) {
                if (!isset($array2[$key]) || !is_array($array2[$key])) {
                    return true;
                } else {
                    if (self::hasDifference($value, $array2[$key])) {
                        return true;
                    }
                }
            } elseif (!array_key_exists($key, $array2) || $array2[$key] !== $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * Creates colletion from the config collection.
     *
     * @param string $id
     * @param string|null $name
     * @return void
     * @throws \Throwable
     */
    protected function createCollection(string $id, string $name = null): void
    {
        $name ??= $id;

        if (!$this->projectDB->exists(App::getEnv('_APP_DB_SCHEMA', 'appwrite'), $name)) {
            $attributes = [];
            $indexes = [];
            $collection = $this->collections[$id];

            foreach ($collection['attributes'] as $attribute) {
                $attributes[] = new Document([
                    '$id' => $attribute['$id'],
                    'type' => $attribute['type'],
                    'size' => $attribute['size'],
                    'required' => $attribute['required'],
                    'signed' => $attribute['signed'],
                    'array' => $attribute['array'],
                    'filters' => $attribute['filters'],
                ]);
            }

            foreach ($collection['indexes'] as $index) {
                $indexes[] = new Document([
                    '$id' => $index['$id'],
                    'type' => $index['type'],
                    'attributes' => $index['attributes'],
                    'lengths' => $index['lengths'],
                    'orders' => $index['orders'],
                ]);
            }

            try {
                $this->projectDB->createCollection($name, $attributes, $indexes);
            } catch (\Throwable $th) {
                throw $th;
            }
        }
    }

    /**
     * Creates attribute from collections.php
     *
     * @param \Utopia\Database\Database $database
     * @param string $collectionId
     * @param string $attributeId
     * @return void
     * @throws \Exception
     * @throws \Utopia\Database\Exception\Duplicate
     * @throws \Utopia\Database\Exception\Limit
     */
    public function createAttributeFromCollection(Database $database, string $collectionId, string $attributeId, string $from = null): void
    {
        $from ??= $collectionId;
        $collection = Config::getParam('collections', [])[$from] ?? null;
        if (is_null($collection)) {
            throw new Exception("Collection {$collectionId} not found");
        }
        $attributes = $collection['attributes'];

        $attributeKey = array_search($attributeId, array_column($attributes, '$id'));

        if ($attributeKey === false) {
            throw new Exception("Attribute {$attributeId} not found");
        }

        $attribute = $attributes[$attributeKey];

        $database->createAttribute(
            collection: $collectionId,
            id: $attributeId,
            type: $attribute['type'],
            size: $attribute['size'],
            required: $attribute['required'] ?? false,
            default: $attribute['default'] ?? null,
            signed: $attribute['signed'] ?? false,
            array: $attribute['array'] ?? false,
            format: $attribute['format'] ?? '',
            formatOptions: $attribute['formatOptions'] ?? [],
            filters: $attribute['filters'] ?? [],
        );
    }

    /**
     * Creates index from collections.php
     *
     * @param \Utopia\Database\Database $database
     * @param string $collectionId
     * @param string $indexId
     * @return void
     * @throws \Exception
     * @throws \Utopia\Database\Exception\Duplicate
     * @throws \Utopia\Database\Exception\Limit
     */
    public function createIndexFromCollection(Database $database, string $collectionId, string $indexId): void
    {
        $collection = Config::getParam('collections', [])[$collectionId] ?? null;

        if (is_null($collection)) {
            throw new Exception("Collection {$collectionId} not found");
        }
        $indexes = $collection['indexes'];

        $indexKey = array_search($indexId, array_column($indexes, '$id'));

        if ($indexKey === false) {
            throw new Exception("Attribute {$indexId} not found");
        }

        $index = $indexes[$indexKey];

        $database->createIndex(
            collection: $collectionId,
            id: $indexId,
            type: $index['type'],
            attributes: $index['attributes'],
            lengths: $index['lengths'] ?? [],
            orders: $index['orders'] ?? []
        );
    }

    /**
     * Executes migration for set project.
     */
    abstract public function execute(): void;
}
