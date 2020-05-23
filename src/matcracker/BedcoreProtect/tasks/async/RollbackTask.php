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

namespace matcracker\BedcoreProtect\tasks\async;

use Closure;
use matcracker\BedcoreProtect\math\Area;
use matcracker\BedcoreProtect\serializable\SerializableBlock;
use matcracker\BedcoreProtect\storage\QueryManager;
use matcracker\BedcoreProtect\utils\Utils;
use pocketmine\block\Block;
use pocketmine\event\block\BlockUpdateEvent;
use pocketmine\level\format\Chunk;
use pocketmine\level\Level;
use pocketmine\math\AxisAlignedBB;
use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;
use function count;
use function microtime;

final class RollbackTask extends AsyncTask
{
    /** @var bool */
    protected $rollback;
    /** @var Area */
    protected $area;
    /** @var SerializableBlock[] */
    protected $blocks;
    /** @var float */
    private $startTime;
    /** @var string[] */
    private $serializedChunks;

    /**
     * RollbackTask constructor.
     * @param bool $rollback
     * @param Area $area
     * @param SerializableBlock[] $blocks
     * @param float $startTime
     * @param Closure $onComplete
     */
    public function __construct(bool $rollback, Area $area, array $blocks, float $startTime, Closure $onComplete)
    {
        $this->rollback = $rollback;
        $this->area = $area;
        $this->blocks = $blocks;
        $this->startTime = $startTime;

        $this->serializedChunks = Utils::serializeChunks($area->getTouchedChunks($blocks));
        $this->storeLocal($onComplete);
    }

    public function onRun(): void
    {
        /** @var Chunk[] $chunks */
        $chunks = [];

        foreach ($this->serializedChunks as $hash => $chunkData) {
            $chunks[$hash] = Chunk::fastDeserialize($chunkData);
        }

        foreach ($this->blocks as $vector) {
            $index = Level::chunkHash($vector->getX() >> 4, $vector->getZ() >> 4);
            if (isset($chunks[$index])) {
                $chunks[$index]->setBlock((int)$vector->getX() & 0x0f, (int)$vector->getY(), (int)$vector->getZ() & 0x0f, $vector->getId(), $vector->getMeta());
            }
        }

        $this->setResult($chunks);
    }

    public function onCompletion(Server $server): void
    {
        $world = $this->area->getWorld();
        if ($world !== null) {
            /** @var Chunk[] $chunks */
            $chunks = $this->getResult();
            foreach ($chunks as $chunk) {
                $world->setChunk($chunk->getX(), $chunk->getZ(), $chunk, false);
            }

            foreach ($this->blocks as $block) {
                $this->updateBlock($world, $block->unserialize());
            }

            /**@var Closure|null $onComplete */
            $onComplete = $this->fetchLocal();

            QueryManager::addReportMessage(microtime(true) - $this->startTime, 'rollback.blocks', [count($this->blocks)]);
            //Set the execution time to 0 to avoid duplication of time in the same operation.
            QueryManager::addReportMessage(0, 'rollback.modified-chunks', [count($chunks)]);

            $onComplete();
        }
    }

    private function updateBlock(Level $world, Block $block): void
    {
        $world->updateAllLight($block);

        $ev = new BlockUpdateEvent($block);
        $ev->call();
        if (!$ev->isCancelled()) {
            foreach ($world->getNearbyEntities(new AxisAlignedBB($block->x - 1, $block->y - 1, $block->z - 1, $block->x + 2, $block->y + 2, $block->z + 2)) as $entity) {
                $entity->onNearbyBlockChange();
            }
            $ev->getBlock()->onNearbyBlockChange();
            $world->scheduleNeighbourBlockUpdates($block->asVector3());
        }
    }
}
