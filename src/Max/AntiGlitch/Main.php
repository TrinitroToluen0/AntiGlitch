<?php

declare(strict_types=1);

namespace Max\AntiGlitch;

use pocketmine\block\Door;
use pocketmine\block\FenceGate;
use pocketmine\block\Trapdoor;
use pocketmine\entity\Entity;
use pocketmine\math\Vector3;
use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;

use pocketmine\player\Player;
use pocketmine\scheduler\Task;
use pocketmine\entity\projectile\EnderPearl;
use pocketmine\entity\Location;

use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\entity\{ProjectileHitEvent, EntityTeleportEvent};
use pocketmine\event\block\{BlockBreakEvent, BlockPlaceEvent};

use pocketmine\event\server\CommandEvent;

class Main extends PluginBase implements Listener {

    private $pearlLand;

    public function onEnable(): void {
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		$this->saveDefaultConfig();
    }


    public function onPearlLandBlock(ProjectileHitEvent $event) {
        $player = $event->getEntity()->getOwningEntity();
        if ($player instanceof Player && $event->getEntity() instanceof EnderPearl) $this->pearlLand[$player->getName()] = $this->getServer()->getTick();
    }

    public function onTP(EntityTeleportEvent $event) {
        $entity = $event->getEntity();
        if (!$entity instanceof Player) return;

        $level = $entity->getWorld();

        $to = $event->getTo();
        if (!isset($this->pearlLand[$entity->getName()])) return;
        if ($this->getServer()->getTick() != $this->pearlLand[$entity->getName()]) return; //Check if teleportation was caused by enderpearl (by checking is a projectile landed at the same time as teleportation) TODO Find a less hacky way of doing this?

        //Get coords and adjust for negative quadrants.
        $x = $to->getX();
        $y = $to->getY();
        $z = $to->getZ();
        if($x < 0) $x = $x - 1;
        if($z < 0) $z = $z - 1;

        //If pearl is in a block as soon as it lands (which could only mean it was shot into a block over a fence), put it back down in the fence. TODO Find a less hacky way of doing this?
        if($this->isInHitbox($level, $x, $y, $z)) $y = $y - 0.5;

        if ($this->isInHitbox($level, $entity->getLocation()->getX(), $entity->getLocation()->getY() + 1.5, $entity->getLocation()->getZ())) {
			if ($this->getConfig()->get("Prevent-Pearling-While-Suffocating")) {
				if ($this->getConfig()->get("CancelPearl-While-Suffocating-Message")) {
					$entity->sendMessage($this->getConfig()->get("CancelPearl-While-Suffocating-Message"));
				}
				$event->cancel();
				return;
			}
		}

        //Try to find a good place to teleport.
		$ys = $y;
        foreach (range(0, 1.9, 0.05) as $n) {
			$xb = $x;
			$yb = ($ys - $n);
			$zb = $z;

			if ($this->isInHitbox($level, ($x + 0.05), $yb, $z)) $xb = $xb - 0.3;
			if ($this->isInHitbox($level, ($x - 0.05), $yb, $z)) $xb = $xb + 0.3;
			if ($this->isInHitbox($level, $x, $yb, ($z - 0.05))) $zb = $zb + 0.3;
			if ($this->isInHitbox($level, $x, $yb, ($z + 0.05))) $zb = $zb - 0.3;


            if($this->isInHitbox($level, $xb, $yb, $zb)) {
                break;
            } else {
				$x = $xb;
				$y = $yb;
				$z = $zb;
			}
        }

		//Check if pearl lands in an area too small for the player
		foreach (range(0.1, 1.8, 0.1) as $n) {
			if($this->isInHitbox($level, $x, ($y + $n), $z)) {

				//Teleport the player into the middle of the block so they can't phase into an adjacent block.
				if(isset($level->getBlockAt((int)$xb, (int)$yb, (int)$zb)->getCollisionBoxes()[0])) {
					$blockHitBox = $level->getBlockAt((int)$xb, (int)$yb, (int)$zb)->getCollisionBoxes()[0];
					if($x < 0) {
						$x = (($blockHitBox->minX + $blockHitBox->maxX) / 2) - 1;
					} else {
						$x = ($blockHitBox->minX + $blockHitBox->maxX) / 2;
					}
					if($z < 0) {
						$z = (($blockHitBox->minZ + $blockHitBox->maxZ) / 2) - 1;
					} else {
						$z = ($blockHitBox->minZ + $blockHitBox->maxZ) / 2;
					}
				}
				//Prevent pearling into areas too small (configurable in config)
				if ($this->getConfig()->get("Prevent-Pearling-In-Small-Areas")) {
					if ($this->getConfig()->get("CancelPearl-In-Small-Area-Message")) {
						$entity->sendMessage($this->getConfig()->get("CancelPearl-In-Small-Area-Message"));
					}
					$event->cancel();
					return;
				} else {
					if($x < 0) $x = $x + 1;
					if($z < 0) $z = $z + 1;
					$yaw = $entity->getLocation()->getYaw();
					$pitch = $entity->getLocation()->getPitch();
					$this->getScheduler()->scheduleDelayedTask(new TeleportTask($entity, new Location($x, $y, $z, $entity->getWorld(), $yaw, $pitch)), 5);
				}
				break;
			}
		}

        //Readjust for negative quadrants
        if($x < 0) $x = $x + 1;
        if($z < 0) $z = $z + 1;

		//Send new safe location
		$event->setTo(new Location($x, $y, $z, $level, 314.5, 0));
    }

    //Check if a set of coords are inside a block's HitBox
    public function isInHitbox($level, $x, $y, $z) {
        if(!isset($level->getBlockAt((int)$x, (int)$y, (int)$z)->getCollisionBoxes()[0])) return False;
        foreach ($level->getBlockAt((int)$x, (int)$y, (int)$z)->getCollisionBoxes() as $blockHitBox) {
			if($x < 0) $x = $x + 1;
			if($z < 0) $z = $z + 1;
			if (($blockHitBox->minX < $x) AND ($x < $blockHitBox->maxX) AND ($blockHitBox->minY < $y) AND ($y < $blockHitBox->maxY) AND ($blockHitBox->minZ < $z) AND ($z < $blockHitBox->maxZ)) return True;
		}
        return False;
    }

	/**
	 * @priority HIGH
	 * @handleCancelled true
	 */

	public function onInteract(PlayerInteractEvent $event) {
		if ($event->getAction() !== PlayerInteractEvent::RIGHT_CLICK_BLOCK) return;
		$player = $event->getPlayer();
		if ($player->isCreative() or $player->isSpectator()) return;
		$block = $event->getBlock();
		if ($event->isCancelled()) {
			if ($block instanceof Door or $block instanceof FenceGate or $block instanceof Trapdoor) {
				if ($this->getConfig()->get("Prevent-Open-Door-Glitching")) {

					$playerPos = $player->getLocation();
					$blockPos = $block->getPosition();

					$distance = $playerPos->distance($blockPos);
					if ($distance > 2) return;

					$playerX = intval($playerPos->getX());
					$playerY = intval($playerPos->getY());
					$playerZ = intval($playerPos->getZ());
					if ($playerX < 0) $playerX = $playerX - 1;
					if ($playerZ < 0) $playerZ = $playerZ - 1;


					$blockX = intval($blockPos->getX());
					$blockY = intval($blockPos->getY());
					$blockZ = intval($blockPos->getZ());

					if (($blockX == (int)$playerX) and ($blockZ == (int)$playerZ) and ($playerY > $blockY)) { #If block is under the player
						foreach ($block->getCollisionBoxes() as $blockHitBox) {
							$playerY = max([$playerY, $blockHitBox->maxY + 0.05]);
						}
						$player->teleport(new Vector3($playerX, $playerY, $playerZ), $playerPos->getYaw(), $playerPos->getPitch());
					} else { #If block is on the side of the player
						foreach ($block->getCollisionBoxes() as $blockHitBox) {
							if (abs($playerX - ($blockHitBox->minX + $blockHitBox->maxX) / 2) > abs($playerZ - ($blockHitBox->minZ + $blockHitBox->maxZ) / 2)) {
								$xb = (9 / ($playerX - ($blockHitBox->minX + $blockHitBox->maxX) / 2)) / 25;
								$zb = 0;
							} else {
								$xb = 0;
								$zb = (9 / ($playerZ - ($blockHitBox->minZ + $blockHitBox->maxZ) / 2)) / 25;
							}
							$player->teleport(new Vector3($playerPos->getX(), $playerPos->getY(), $playerPos->getZ()), $playerPos->getYaw(), $playerPos->getPitch());
							$player->setMotion(new Vector3($xb, 0, $zb));
						}

					}

					if ($this->getConfig()->get("CancelOpenDoor-Message")) $player->sendMessage($this->getConfig()->get("CancelOpenDoor-Message"));
				}
			}
		}
	}

	/**
	 * @priority HIGH
	 * @handleCancelled true
	 */

    public function onBlockBreak(BlockBreakEvent $event) {
        if ($this->getConfig()->get("Prevent-Break-Block-Glitching")) {
            $player = $event->getPlayer();
            $block = $event->getBlock();
			if ($player->isCreative() or $player->isSpectator()) return;
            if ($event->isCancelled()) {

				$playerPos = $player->getLocation();
				$playerX = intval($playerPos->getX());
				$playerY = intval($playerPos->getY());
				$playerZ = intval($playerPos->getZ());

				if($playerX < 0) $playerX = $playerX - 1;
				if($playerZ < 0) $playerZ = $playerZ - 1;

				$blockPos = $block->getPosition();
				$blockX = intval($blockPos->getX());
				$blockY = intval($blockPos->getY());
				$blockZ = intval($blockPos->getZ());

				if (($blockX == (int)$playerX) AND ($blockZ == (int)$playerZ) AND ($playerY > $blockY)) { #If block is under the player
					foreach ($block->getCollisionBoxes() as $blockHitBox) {
						$y = max([$playerY, $blockHitBox->maxY]);
					}
					$player->teleport(new Vector3($playerX, $y, $playerZ, $playerPos->getYaw(), $playerPos->getPitch()));
				} else { #If block is on the side of the player
					$xb = 0;
					$zb = 0;
					foreach ($block->getCollisionBoxes() as $blockHitBox) {
						if (abs($playerX - ($blockHitBox->minX + $blockHitBox->maxX) / 2) > abs($playerZ - ($blockHitBox->minZ + $blockHitBox->maxZ) / 2)) {
							$xb = (5 / ($playerX - ($blockHitBox->minX + $blockHitBox->maxX) / 2)) / 24;
						} else {
							$zb = (5 / ($playerZ - ($blockHitBox->minZ + $blockHitBox->maxZ) / 2)) / 24;
						}
					}
					$player->setMotion(new Vector3($xb, 0, $zb));
				}
				if ($this->getConfig()->get("CancelBlockBreak-Message")) $player->sendMessage($this->getConfig()->get("CancelBlockBreak-Message"));
            }
        }
    }

	/**
	 * @priority HIGH
	 * @handleCancelled true
	 */

    public function onBlockPlace(BlockPlaceEvent $event) {
        if ($this->getConfig()->get("Prevent-Place-Block-Glitching")) {
            $player = $event->getPlayer();
			if ($player->isCreative() or $player->isSpectator()) return;
			if ($event->isCancelled()) {

				$playerPos = $player->getLocation();
				$playerX = intval($playerPos->getX());
				$playerY = intval($playerPos->getY());
				$playerZ = intval($playerPos->getZ());

				if ($playerX < 0) $playerX = $playerX - 1;
				if ($playerZ < 0) $playerZ = $playerZ - 1;

				foreach ($event->getTransaction()->getBlocks() as [$x, $y, $z, $block]) {
					$blockPos = $block->getPosition();
					$blockX = intval($blockPos->getX());
					$blockY = intval($blockPos->getY());
					$blockZ = intval($blockPos->getZ());

					if (($blockX == (int)$playerX) and ($blockZ == (int)$playerZ) and ($playerY > $blockY)) { #If block is under the player
						$playerMotion = $player->getMotion();
						$this->getScheduler()->scheduleDelayedTask(new MotionTask($player, new Vector3($playerMotion->getX(), -0.1, $playerMotion->getZ())), 2);
						if ($this->getConfig()->get("CancelBlockPlace-Message")) $player->sendMessage($this->getConfig()->get("CancelBlockPlace-Message"));
					}
				}
			}
        }
    }

	public function onCommandPre(CommandEvent $event){
        if ($this->getConfig()->get("Prevent-Command-Glitching")) {
            if((substr($event->getCommand(), 0, 2) == "/ ") || (substr($event->getCommand(), 0, 2) == "/\\") || (substr($event->getCommand(), 0, 2) == "/\"") || (substr($event->getCommand(), -1, 1) === "\\")){
                $event->cancel();
                if ($this->getConfig()->get("CancelCommand-Message")) {
                    $event->getSender()->sendMessage($this->getConfig()->get("CancelCommand-Message"));
                }
            }
        }
    }
}

class TeleportTask extends Task {
	private $entity;
	private $location;

	public function __construct(Entity $entity, Location $location) {
		$this->entity = $entity;
		$this->location = $location;
	}

	public function onRun(): void
	{
		$this->entity->teleport($this->location);
	}
}

class MotionTask extends Task {
	private $entity;
	private $vector3;

	public function __construct(Entity $entity, Vector3 $vector3) {
		$this->entity = $entity;
		$this->vector3 = $vector3;
	}

	public function onRun(): void {
		$this->entity->setMotion($this->vector3);
	}
}
