<?php

declare(strict_types=1);

namespace BumbleDocGen\Core\Parser\Entity\CollectionLogOperation;

final class OperationsCollection implements \IteratorAggregate
{
    /**
     * @var OperationInterface[]
     */
    protected array $operations = [];

    public function getIterator(): \Traversable
    {
        return new \ArrayObject($this->operations);
    }

    public function add(OperationInterface $operation): void
    {
        $this->operations[] = $operation;
    }

    public function removeSearchDuplicates(): void
    {
        /** @var IterateEntitiesOperation[] $singleEntitySearchOperations */
        $iterateOperations = array_filter($this->operations, fn(OperationInterface $operation) => $operation instanceof IterateEntitiesOperation);
        /** @var SingleEntitySearchOperation[] $singleEntitySearchOperations */
        $singleEntitySearchOperations = array_filter($this->operations, fn(OperationInterface $operation) => $operation instanceof SingleEntitySearchOperation);

        if ($iterateOperations) {
            foreach ($singleEntitySearchOperations as $k => $singleEntitySearchOperation) {
                if (!$singleEntitySearchOperation->getEntityName()) {
                    continue;
                }
                foreach ($iterateOperations as $iterateOperation) {
                    if ($iterateOperation->hasEntity($singleEntitySearchOperation->getEntityName())) {
                        unset($this->operations[$k]);
                        unset($singleEntitySearchOperations[$k]);
                    }
                }
            }
        }

        if ($singleEntitySearchOperations) {
            $findEntitySearchOperations = array_filter($singleEntitySearchOperations, fn(OperationInterface $operation) => $operation->getFunctionName() === 'findEntity');
            $getLoadedOrCreateNewOperations = array_filter($singleEntitySearchOperations, fn(OperationInterface $operation) => $operation->getFunctionName() === 'getLoadedOrCreateNew');
            $getOperations = array_filter($singleEntitySearchOperations, fn(OperationInterface $operation) => $operation->getFunctionName() === 'get');

            foreach ($findEntitySearchOperations as $findEntitySearchOperation) {
                foreach ($getLoadedOrCreateNewOperations as $k => $getLoadedOrCreateNewOperation) {
                    if (is_null($getLoadedOrCreateNewOperation->getEntityName())) {
                        continue;
                    }
                    if ($findEntitySearchOperation->getEntityName() === $getLoadedOrCreateNewOperation->getEntityName()) {
                        unset($getLoadedOrCreateNewOperations[$k]);
                        unset($this->operations[$k]);
                    }
                }
                foreach ($getOperations as $k => $getOperation) {
                    if (is_null($getOperation->getEntityName())) {
                        continue;
                    }
                    if ($findEntitySearchOperation->getEntityName() === $getOperation->getEntityName()) {
                        unset($getOperations[$k]);
                        unset($this->operations[$k]);
                    }
                }
            }
            foreach ($getLoadedOrCreateNewOperations as $getLoadedOrCreateNewOperation) {
                foreach ($getOperations as $k => $getOperation) {
                    if (is_null($getOperation->getEntityName())) {
                        continue;
                    }
                    if ($getLoadedOrCreateNewOperation->getEntityName() === $getOperation->getEntityName()) {
                        unset($getOperations[$k]);
                        unset($this->operations[$k]);
                    }
                }
            }
        }
        array_walk($this->operations, function (OperationInterface $operation) {
            if ($operation instanceof CloneOperation) {
                $operation->getOperationsCollection()->removeSearchDuplicates();
            }
        });
    }
}
