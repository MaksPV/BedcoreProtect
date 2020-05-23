<?php

/*
 *     ___         __                 ___           __          __
 *    / _ )___ ___/ /______  _______ / _ \_______  / /____ ____/ /_
 *   / _  / -_) _  / __/ _ \/ __/ -_) ___/ __/ _ \/ __/ -_) __/ __/
 *  /____/\__/\_,_/\__/\___/_/  \__/_/  /_/  \___/\__/\__/\__/\__/
 *
 * Copyright (C) 2019
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author matcracker
 * @link https://www.github.com/matcracker/BedcoreProtect
 *
*/

declare(strict_types=1);

namespace matcracker\BedcoreProtect\storage\queries;

use Closure;
use Generator;
use matcracker\BedcoreProtect\enums\Action;
use matcracker\BedcoreProtect\math\Area;
use matcracker\BedcoreProtect\serializable\SerializableItem;
use matcracker\BedcoreProtect\serializable\SerializablePosition;
use matcracker\BedcoreProtect\storage\QueryManager;
use matcracker\BedcoreProtect\tasks\async\InventoriesQueryGeneratorTask;
use matcracker\BedcoreProtect\tasks\async\LogsQueryGeneratorTask;
use matcracker\BedcoreProtect\utils\EntityUtils;
use matcracker\BedcoreProtect\utils\Utils;
use pocketmine\inventory\ContainerInventory;
use pocketmine\inventory\Inventory;
use pocketmine\inventory\InventoryHolder;
use pocketmine\inventory\transaction\action\SlotChangeAction;
use pocketmine\item\Item;
use pocketmine\item\ItemFactory;
use pocketmine\level\Position;
use pocketmine\math\Vector3;
use pocketmine\Player;
use pocketmine\Server;
use pocketmine\tile\Chest;
use SOFe\AwaitGenerator\Await;
use function array_fill;
use function array_map;
use function count;
use function microtime;

/**
 * It contains all the queries methods related to inventories.
 *
 * Class InventoriesQueries
 * @package matcracker\BedcoreProtect\storage\queries
 */
class InventoriesQueries extends Query
{
    public function addInventorySlotLogByPlayer(Player $player, SlotChangeAction $slotAction): void
    {
        $inventory = $slotAction->getInventory();

        if (!$inventory instanceof ContainerInventory) {
            return;
        }

        /**
         * Note for double chest holder
         * It always logs the position of the left chest.
         * @see DoubleChestInventory::getHolder()
         */
        $holder = $inventory->getHolder();
        if (!($holder instanceof Vector3)) {
            return;
        }

        $playerUuid = EntityUtils::getUniqueId($player);
        $position = SerializablePosition::serialize(Position::fromObject($holder, $player->getLevel()));

        Await::f2c(
            function () use ($playerUuid, $slotAction, $position): Generator {
                $slot = $slotAction->getSlot();
                $sourceItem = $slotAction->getSourceItem();
                $targetItem = $slotAction->getTargetItem();

                if ($sourceItem->equals($targetItem)) {
                    $sourceCount = $sourceItem->getCount();
                    $targetCount = $targetItem->getCount(); //Final count
                    if ($targetCount > $sourceCount) {
                        $action = Action::ADD();
                        $targetItem->setCount($targetCount - $sourceCount); //Effective number of blocks added
                    } else {
                        $action = Action::REMOVE();
                        $sourceItem->setCount($sourceCount - $targetCount); //Effective number of blocks removed
                    }
                    $lastId = yield $this->addRawLog($playerUuid, $position, $action);
                } elseif (!$sourceItem->isNull() && !$targetItem->isNull()) {
                    $lastId = yield $this->addRawLog($playerUuid, $position, Action::REMOVE());
                    yield $this->addInventorySlotLog($lastId, $slot, $sourceItem, $targetItem);
                    $lastId = yield $this->addRawLog($playerUuid, $position, Action::ADD());
                } elseif (!$sourceItem->isNull()) {
                    $lastId = yield $this->addRawLog($playerUuid, $position, Action::REMOVE());
                } else {
                    $lastId = yield $this->addRawLog($playerUuid, $position, Action::ADD());
                }

                yield $this->addInventorySlotLog($lastId, $slot, $sourceItem, $targetItem);
            }
        );
    }

    final protected function addInventorySlotLog(int $logId, int $slot, Item $oldItem, Item $newItem): Generator
    {
        $this->connector->executeInsert(QueriesConst::ADD_INVENTORY_LOG, [
            'log_id' => $logId,
            'slot' => $slot,
            'old_id' => $oldItem->getId(),
            'old_meta' => $oldItem->getDamage(),
            'old_nbt' => Utils::serializeNBT($oldItem->getNamedTag()),
            'old_amount' => $oldItem->getCount(),
            'new_id' => $newItem->getId(),
            'new_meta' => $newItem->getDamage(),
            'new_nbt' => Utils::serializeNBT($newItem->getNamedTag()),
            'new_amount' => $newItem->getCount()
        ], yield, yield Await::REJECT);

        return yield Await::ONCE;
    }

    /**
     * Item frames do not have an inventory but we treat them as if they did.
     *
     * @param Player $player
     * @param Item $item
     * @param Action $action
     * @param SerializablePosition $position
     */
    public function addItemFrameSlotLog(Player $player, Item $item, Action $action, SerializablePosition $position): void
    {
        $uuid = EntityUtils::getUniqueId($player);
        $item = clone $item;
        Await::f2c(
            function () use ($uuid, $item, $action, $position): Generator {
                $logId = yield $this->addRawLog($uuid, $position, $action);
                yield $this->addInventorySlotLog($logId, 0, $item, $item);
            }
        );
    }

    public function addInventoryLogByPlayer(Player $player, Inventory $inventory, Position $inventoryPosition): void
    {
        /** @var SerializableItem[] $contents */
        $contents = array_map(static function (Item $item): SerializableItem {
            return SerializableItem::serialize($item);
        }, $inventory->getContents());

        /** @var SerializablePosition[] $positions */
        $positions = array_fill(0, count($contents), SerializablePosition::serialize($inventoryPosition));

        $logsTask = new LogsQueryGeneratorTask(
            EntityUtils::getUniqueId($player),
            $positions,
            Action::REMOVE(),
            function (string $query) use ($contents): Generator {
                [$firstInsertedId, $affectedRows] = yield $this->executeInsertRaw($query, [], true);

                if ($this->configParser->isSQLite()) {
                    $firstInsertedId -= $affectedRows - 1;
                }

                $inventoriesTask = new InventoriesQueryGeneratorTask(
                    $firstInsertedId,
                    $contents,
                    function (string $query): Generator {
                        yield $this->executeInsertRaw($query);
                    }
                );

                Server::getInstance()->getAsyncPool()->submitTask($inventoriesTask);
            }
        );

        Server::getInstance()->getAsyncPool()->submitTask($logsTask);
    }

    protected function onRollback(bool $rollback, Area $area, array $logIds, float $startTime, Closure $onComplete): Generator
    {
        $prefix = $this->getRollbackPrefix($rollback);

        $inventoryRows = [];

        if ($this->configParser->getRollbackItems()) {
            if ($rollback) {
                $inventoryRows = yield $this->executeSelect(QueriesConst::GET_ROLLBACK_OLD_INVENTORIES, ['log_ids' => $logIds]);
            } else {
                $inventoryRows = yield $this->executeSelect(QueriesConst::GET_ROLLBACK_NEW_INVENTORIES, ['log_ids' => $logIds]);
            }

            foreach ($inventoryRows as $row) {
                $amount = (int)$row["{$prefix}_amount"];
                $nbt = Utils::deserializeNBT($row["{$prefix}_nbt"]);
                $item = ItemFactory::get((int)$row["{$prefix}_id"], (int)$row["{$prefix}_meta"], $amount, $nbt);
                $vector = new Vector3((int)$row['x'], (int)$row['y'], (int)$row['z']);
                $tile = $area->getWorld()->getTile($vector);

                if ($tile instanceof InventoryHolder) {
                    $inv = ($tile instanceof Chest) ? $tile->getRealInventory() : $tile->getInventory();
                    $inv->setItem((int)$row['slot'], $item);
                }
            }
        }

        if (($items = count($inventoryRows)) > 0) {
            QueryManager::addReportMessage(microtime(true) - $startTime, 'rollback.items', [$items]);
        }

        $onComplete();
    }
}
