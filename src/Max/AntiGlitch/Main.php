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
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\server\CommandEvent;

class Main extends PluginBase implements Listener
{

	public function onEnable(): void
	{
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		$this->saveDefaultConfig();
	}

	/**
	 * @priority HIGHEST
	 * @handleCancelled true
	 */
	public function onInteract(PlayerInteractEvent $event)
	{
		if ($event->getAction() !== PlayerInteractEvent::RIGHT_CLICK_BLOCK) return;
		$player = $event->getPlayer();
		if ($player->isCreative()) return;
		$block = $event->getBlock();
		if (!$event->isCancelled()) return;
		if (!($block instanceof Door || $block instanceof FenceGate || $block instanceof Trapdoor)) return;

		if (!$this->getConfig()->get("Prevent-Open-Door-Glitching")) return;

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

	/**
	 * @priority HIGHEST
	 * @handleCancelled true
	 */
	public function onBlockPlace(BlockPlaceEvent $event)
	{
		if (!$this->getConfig()->get("Prevent-Place-Block-Glitching")) return;
		$player = $event->getPlayer();
		if ($player->isCreative()) return;
		if (!$event->isCancelled()) return;

		$playerPos = $player->getLocation();
		$playerX = (int) $playerPos->getX();
		$playerY = (int) $playerPos->getY();
		$playerZ = (int) $playerPos->getZ();

		if ($playerX < 0) $playerX = $playerX - 1;
		if ($playerZ < 0) $playerZ = $playerZ - 1;

		foreach ($event->getTransaction()->getBlocks() as [$x, $y, $z, $block]) {
			$blockPos = $block->getPosition();
			$blockX = (int) $blockPos->getX();
			$blockY = (int) $blockPos->getY();
			$blockZ = (int) $blockPos->getZ();

			// If block is under the player
			if (($blockX === $playerX) && ($blockZ === $playerZ) && ($playerY > $blockY)) {
				$playerMotion = $player->getMotion();
				$player->setMotion(new Vector3($playerMotion->getX(), -0.5, $playerMotion->getZ()));
				if ($this->getConfig()->get("CancelBlockPlace-Message")) $player->sendMessage($this->getConfig()->get("CancelBlockPlace-Message"));
			}
		}
	}

	public function onCommandPre(CommandEvent $event)
	{
		if ($this->getConfig()->get("Prevent-Command-Glitching")) {
			if ((substr($event->getCommand(), 0, 2) == "/ ") || (substr($event->getCommand(), 0, 2) == "/\\") || (substr($event->getCommand(), 0, 2) == "/\"") || (substr($event->getCommand(), -1, 1) === "\\")) {
				$event->cancel();
				if ($this->getConfig()->get("CancelCommand-Message")) {
					$event->getSender()->sendMessage($this->getConfig()->get("CancelCommand-Message"));
				}
			}
		}
	}

	public function onPlayerMove(PlayerMoveEvent $event)
	{
		$from = $event->getFrom();
		$to = $event->getTo();

		if ($from->getX() === $to->getX() && $from->getY() === $to->getY() && $from->getZ() === $to->getZ()) return;

		$player = $event->getPlayer();
		$playerLocation = $player->getLocation();
		$world = $player->getWorld();
		$block = $world->getBlock($playerLocation);
		$blockAbove = $world->getBlock($playerLocation->add(0, 1, 0));
		$blockAboveTwo = $world->getBlock($playerLocation->add(0, 2, 0));

		if ($block->isTransparent()) return;
		if ($player->isSpectator()) return;

		if ($blockAbove->isTransparent() && $blockAboveTwo->isTransparent()) {
			$playerMotion = $player->getMotion();
			$player->setMotion(new Vector3($playerMotion->getX(), 0.5, $playerMotion->getZ()));
		} else {
			$player->teleport($from);
		}
	}
}

class MotionTask extends Task
{
	private $entity;
	private $vector3;

	public function __construct(Entity $entity, Vector3 $vector3)
	{
		$this->entity = $entity;
		$this->vector3 = $vector3;
	}

	public function onRun(): void
	{
		$this->entity->setMotion($this->vector3);
	}
}
