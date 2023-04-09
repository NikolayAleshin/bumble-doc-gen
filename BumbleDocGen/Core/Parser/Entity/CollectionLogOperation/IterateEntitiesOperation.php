<?php

declare(strict_types=1);

namespace BumbleDocGen\Core\Parser\Entity\CollectionLogOperation;

final class IterateEntitiesOperation implements OperationInterface
{
    private array $entities;

    public function __construct(array $entities)
    {
        foreach ($entities as $position => $entity) {
            $this->entities[$entity->getName()] = $position;
        }
    }

    public function hasEntity(string $entityName): bool
    {
        return array_key_exists($entityName, $this->entities);
    }
}
