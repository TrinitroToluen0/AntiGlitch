<?php

declare(strict_types=1);

namespace Max\AntiGlitch;

use pocketmine\block\Door;
use pocketmine\block\FenceGate;
use pocketmine\block\Trapdoor;
use pocketmine\math\Vector3;
use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;

use pocketmine\event\player\PlayerInteractEvent;
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

		$playerLocation = $player->getLocation();
		$blockPosition = $block->getPosition();

		$distance = $playerLocation->distance($blockPosition);
		if ($distance > 2) return;

		$playerX = (int) $playerLocation->getX();
		$playerY = (int) $playerLocation->getY();
		$playerZ = (int) $playerLocation->getZ();

		if ($playerX < 0) $playerX = $playerX - 1;
		if ($playerZ < 0) $playerZ = $playerZ - 1;

		$blockX = (int) $blockPosition->getX();
		$blockY = (int) $blockPosition->getY();
		$blockZ = (int) $blockPosition->getZ();

		// If block is under the player
		if (($blockX === $playerX) && ($blockZ === $playerZ) && ($playerY > $blockY)) {
			foreach ($block->getCollisionBoxes() as $blockHitBox) {
				$newY = max([$playerY, $blockHitBox->maxY + 0.05]);
			}
			$player->teleport(new Vector3($playerX, $newY, $playerZ), $playerLocation->getYaw(), $playerLocation->getPitch());
		} else {
			// If block is on the side of the player
			foreach ($block->getCollisionBoxes() as $blockHitBox) {
				if (abs($playerX - ($blockHitBox->minX + $blockHitBox->maxX) / 2) > abs($playerZ - ($blockHitBox->minZ + $blockHitBox->maxZ) / 2)) {
					$xb = (9 / ($playerX - ($blockHitBox->minX + $blockHitBox->maxX) / 2)) / 25;
					$zb = 0;
				} else {
					$xb = 0;
					$zb = (9 / ($playerZ - ($blockHitBox->minZ + $blockHitBox->maxZ) / 2)) / 25;
				}
				$player->teleport(new Vector3($playerLocation->getX(), $playerLocation->getY(), $playerLocation->getZ()), $playerLocation->getYaw(), $playerLocation->getPitch());
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

		$playerLocation = $player->getLocation();
		$playerX = (int) $playerLocation->getX();
		$playerY = (int) $playerLocation->getY();
		$playerZ = (int) $playerLocation->getZ();

		if ($playerX < 0) $playerX = $playerX - 1;
		if ($playerZ < 0) $playerZ = $playerZ - 1;

		foreach ($event->getTransaction()->getBlocks() as [$x, $y, $z, $block]) {
			$blockPos = $block->getPosition();
			$blockX = (int) $blockPos->getX();
			$blockY = (int) $blockPos->getY();
			$blockZ = (int) $blockPos->getZ();

			// If block is under the player
			if (($blockX === $playerX) && ($blockZ === $playerZ) && ($playerY > $blockY)) {
				$player->teleport($playerLocation->subtract(0, 0.8, 0), $playerLocation->getYaw(), $playerLocation->getPitch());
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
			$player->teleport($playerLocation->add(0, 0.8, 0), $playerLocation->getYaw(), $playerLocation->getPitch());
		} else {
			$player->teleport($from);
		}
	}
}
