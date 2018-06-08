<?php

declare(strict_types = 1);

namespace DataSource\DataMapper;

use PDOException;
use ArrayIterator;
use BasePatterns\RecordSet\Row;
use MetadataMapping\Metadata\DataMap;
use BasePatterns\RecordSet\RecordSet;
use Infrastructure\Database\Connection;
use BasePatterns\LayerSupertype\DomainObject;

abstract class AbstractMapper
{
    private $db;

    private $dataMap;

    protected $loadedMap = [];

    abstract protected function findStatement(): string;

    abstract protected function loadDataMap(): DataMap;

    public function __construct(Connection $connection)
    {
        $this->db = $connection;
    }

    public function getDataMap()
    {
        return $this->dataMap = $this->dataMap ?: $this->loadDataMap();
    }

    public function findObjectsWhere(string $whereClause, array $bindValues): ArrayIterator
    {
        $sql = sprintf(
            "SELECT %s FROM %s WHERE %s",
            $this->getDataMap()->columnList(),
            $this->getDataMap()->getTableName(),
            $whereClause
        );

        try {
            $stmt = $this->db->prepare($sql);
            $rs = $stmt->executeQuery($bindValues);
            return $this->loadAll($rs);
        } catch (PDOException $e) {
            throw new SQLException(
                $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    protected function abstractFind(int $id): DomainObject
    {
        if ($result = $this->loadedMap[$id] ?? null) {
            return $result;
        }

        try {
            $findStatement = $this->db->prepare($this->findStatement());
            $findStatement->bindValue(1, $id);
            $rs = $findStatement->executeQuery();

            return $this->load($rs->current());
        } catch (PDOException $e) {
            throw new SQLException(
                $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    protected function findMany(StatementSource $source): ArrayIterator
    {
        try {
            $stmt = $this->db->prepare($source->getSql());
            foreach ($source->getParameters() as $i => $value) {
                $stmt->bindValue($i, $value);
            }
            $rs = $stmt->executeQuery();

            return $this->loadAll($rs);
        } catch (PDOException $e) {
            throw new SQLException(
                $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    protected function load(array $row): DomainObject
    {
        $id = $row['id'];
        if ($result = $this->loadedMap[$id] ?? null) {
            return $result;
        }

        $result = $this->getDataMap()->getDomainClass()->newInstanceWithoutConstructor();
        $result->setId($id);
        $this->loadFields($row, $result);
        $this->loadedMap[$id] = $result;

        return $result;
    }

    protected function loadAll(ArrayIterator $rs): ArrayIterator
    {
        $result = array_map(function (array $row) {
            return $this->load($row);
        }, $rs->getArrayCopy());

        return new ArrayIterator($result);
    }

    private function loadFields(array $row, DomainObject $result): void
    {
        foreach ($this->dataMap->getColumnMaps() as $columnMap) {
            $columnMap->setField($result, $row[$columnMap->getColumnName()]);
        }
    }
}
