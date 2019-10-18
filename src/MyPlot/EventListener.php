<?php
declare(strict_types=1);
namespace MyPlot;

use MyPlot\events\MyPlotBlockEvent;
use MyPlot\events\MyPlotPlayerEnterPlotEvent;
use MyPlot\events\MyPlotPlayerLeavePlotEvent;
use MyPlot\events\MyPlotPvpEvent;
use pocketmine\block\Sapling;
use pocketmine\block\utils\TreeType;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\block\BlockSpreadEvent;
use pocketmine\event\block\SignChangeEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityExplodeEvent;
use pocketmine\event\entity\EntityMotionEvent;
use pocketmine\event\world\WorldLoadEvent;
use pocketmine\event\world\WorldUnloadEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\player\Player;
use pocketmine\world\World;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;

class EventListener implements Listener
{
	/** @var MyPlot $plugin */
	private $plugin;

	/**
	 * EventListener constructor.
	 *
	 * @param MyPlot $plugin
	 */
	public function __construct(MyPlot $plugin) {
		$this->plugin = $plugin;
	}

	/**
	 * @ignoreCancelled false
	 * @priority LOWEST
	 *
	 * @param WorldLoadEvent $event
	 */
	public function onLevelLoad(WorldLoadEvent $event) : void {
		if(file_exists($this->plugin->getDataFolder()."worlds".DIRECTORY_SEPARATOR.$event->getWorld()->getFolderName().".yml")) {
			$this->plugin->getLogger()->debug("MyPlot world " . $event->getWorld()->getFolderName() . " loaded!");
			$settings = $event->getWorld()->getProvider()->getWorldData()->getGeneratorOptions();
			if(!isset($settings["preset"]) or empty($settings["preset"])) {
				return;
			}
			$settings = json_decode($settings["preset"], true);
			if($settings === false) {
				return;
			}
			$worldName = $event->getWorld()->getFolderName();
			$default = $this->plugin->getConfig()->get("DefaultWorld", []);
			$config = new Config($this->plugin->getDataFolder()."worlds".DIRECTORY_SEPARATOR.$worldName.".yml", Config::YAML, $default);
			foreach(array_keys($default) as $key) {
				$settings[$key] = $config->get($key);
			}
			$this->plugin->addLevelSettings($worldName, new PlotLevelSettings($worldName, $settings));
		}
	}

	/**
	 * @ignoreCancelled false
	 * @priority MONITOR
	 *
	 * @param WorldUnloadEvent $event
	 */
	public function onWorldUnload(WorldUnloadEvent $event) : void {
		if($event->isCancelled()) {
			return;
		}
		$worldName = $event->getWorld()->getFolderName();
		if($this->plugin->unloadLevelSettings($worldName)) {
			$this->plugin->getLogger()->debug("World " . $event->getWorld()->getFolderName() . " unloaded!");
		}
	}

	/**
	 * @ignoreCancelled false
	 * @priority LOWEST
	 *
	 * @param BlockPlaceEvent $event
	 */
	public function onBlockPlace(BlockPlaceEvent $event) : void {
		$this->onEventOnBlock($event);
	}

	/**
	 * @ignoreCancelled false
	 * @priority LOWEST
	 *
	 * @param BlockBreakEvent $event
	 */
	public function onBlockBreak(BlockBreakEvent $event) : void {
		$this->onEventOnBlock($event);
	}

	/**
	 * @ignoreCancelled false
	 * @priority LOWEST
	 *
	 * @param PlayerInteractEvent $event
	 */
	public function onPlayerInteract(PlayerInteractEvent $event) : void {
		$this->onEventOnBlock($event);
	}

	/**
	 * @ignoreCancelled false
	 * @priority LOWEST
	 *
	 * @param SignChangeEvent $event
	 */
	public function onSignChange(SignChangeEvent $event) : void {
		$this->onEventOnBlock($event);
	}

	/**
	 * @param BlockPlaceEvent|BlockBreakEvent|PlayerInteractEvent|SignChangeEvent $event
	 */
	private function onEventOnBlock($event) : void {
		$worldName = $event->getBlock()->getPos()->getWorld()->getFolderName();
		if(!$this->plugin->isLevelLoaded($worldName)) {
			return;
		}
		$plot = $this->plugin->getPlotByPosition($event->getBlock()->getPos());
		if($plot !== null) {
			$ev = new MyPlotBlockEvent($plot, $event->getBlock(), $event->getPlayer(), $event);
			if($event->isCancelled()) {
				$ev->setCancelled($event->isCancelled());
			}
			$ev->call();
			$event->setCancelled($ev->isCancelled());
			$username = $event->getPlayer()->getName();
			if($plot->owner == $username or $plot->isHelper($username) or $plot->isHelper("*") or $event->getPlayer()->hasPermission("myplot.admin.build.plot")) {
				if(!($event instanceof PlayerInteractEvent and $event->getBlock() instanceof Sapling))
					return;
				/*
				 * Prevent growing a tree near the edge of a plot
				 * so the leaves won't go outside the plot
				 */
				$block = $event->getBlock();
				$maxLengthLeaves = (($block->getMeta() & 0x07) == TreeType::SPRUCE()) ? 3 : 2;
				$beginPos = $this->plugin->getPlotPosition($plot);
				$endPos = clone $beginPos;
				$beginPos->x += $maxLengthLeaves;
				$beginPos->z += $maxLengthLeaves;
				$plotSize = $this->plugin->getLevelSettings($worldName)->plotSize;
				$endPos->x += $plotSize - $maxLengthLeaves;
				$endPos->z += $plotSize - $maxLengthLeaves;
				if($block->getPos()->x >= $beginPos->x and $block->getPos()->z >= $beginPos->z and $block->getPos()->x < $endPos->x and $block->getPos()->z < $endPos->z) {
					return;
				}
			}
		}elseif($event->getPlayer()->hasPermission("myplot.admin.build.road"))
			return;
		$event->setCancelled();
		$this->plugin->getLogger()->debug("Block placement/interaction of {$event->getBlock()->getName()} was cancelled");
	}

	/**
	 * @ignoreCancelled false
	 * @priority LOWEST
	 *
	 * @param EntityExplodeEvent $event
	 */
	public function onExplosion(EntityExplodeEvent $event) : void {
		if($event->isCancelled()) {
			return;
		}
		$worldName = $event->getEntity()->getWorld()->getFolderName();
		if(!$this->plugin->isLevelLoaded($worldName))
			return;
		$plot = $this->plugin->getPlotByPosition($event->getPosition());
		if($plot === null) {
			$event->setCancelled();
			return;
		}
		$beginPos = $this->plugin->getPlotPosition($plot);
		$endPos = clone $beginPos;
		$plotSize = $this->plugin->getLevelSettings($worldName)->plotSize;
		$endPos->x += $plotSize;
		$endPos->z += $plotSize;
		$blocks = array_filter($event->getBlockList(), function($block) use ($beginPos, $endPos) {
			if($block->x >= $beginPos->x and $block->z >= $beginPos->z and $block->x < $endPos->x and $block->z < $endPos->z) {
				return true;
			}
			return false;
		});
		$event->setBlockList($blocks);
	}

	/**
	 * @ignoreCancelled false
	 * @priority LOWEST
	 *
	 * @param EntityMotionEvent $event
	 */
	public function onEntityMotion(EntityMotionEvent $event) : void {
		if($event->isCancelled()) {
			return;
		}
		$world = $event->getEntity()->getWorld();
		if(!$world instanceof World)
			return;
		$worldName = $world->getFolderName();
		if(!$this->plugin->isLevelLoaded($worldName))
			return;
		$settings = $this->plugin->getLevelSettings($worldName);
		if($settings->restrictEntityMovement and !($event->getEntity() instanceof Player)) {
			$event->setCancelled();
			$this->plugin->getLogger()->debug("Cancelled entity motion on " . $worldName);
		}
	}

	/**
	 * @ignoreCancelled false
	 * @priority LOWEST
	 *
	 * @param BlockSpreadEvent $event
	 */
	public function onBlockSpread(BlockSpreadEvent $event) : void {
		if($event->isCancelled()) {
			return;
		}
		$worldName = $event->getBlock()->getPos()->getWorld()->getFolderName();
		if(!$this->plugin->isLevelLoaded($worldName))
			return;
		$settings = $this->plugin->getLevelSettings($worldName);
		if(!$settings->updatePlotLiquids) {
			$event->setCancelled();
			$this->plugin->getLogger()->debug("Cancelled block spread of {$event->getBlock()->getName()} on " . $worldName);
		}
	}

	/**
	 * @ignoreCancelled false
	 * @priority LOWEST
	 *
	 * @param PlayerMoveEvent $event
	 */
	public function onPlayerMove(PlayerMoveEvent $event) : void {
		$worldName = $event->getPlayer()->getWorld()->getFolderName();
		if(!$this->plugin->isLevelLoaded($worldName))
			return;
		$plot = $this->plugin->getPlotByPosition($event->getTo());
		if($plot !== null and $plot !== $this->plugin->getPlotByPosition($event->getFrom())) {
			$ev = new MyPlotPlayerEnterPlotEvent($plot, $event->getPlayer());
			$ev->setCancelled($event->isCancelled());
			if($plot->isDenied($event->getPlayer()->getName())) {
				$ev->setCancelled();
				return;
			}
			if(strpos((string) $plot, "-0")) {
				return;
			}
			$ev->call();
			$event->setCancelled($ev->isCancelled());
			if($event->isCancelled()) {
				return;
			}
			if(!$this->plugin->getConfig()->get("ShowPlotPopup", true))
				return;
			$popup = $this->plugin->getLanguage()->translateString("popup", [TextFormat::GREEN . $plot]);
			if($plot->owner !== "") {
				$owner = TextFormat::GREEN . $plot->owner;
				$ownerPopup = $this->plugin->getLanguage()->translateString("popup.owner", [$owner]);
				$paddingSize = (int) floor((strlen($popup) - strlen($ownerPopup)) / 2);
				$paddingPopup = str_repeat(" ", max(0, -$paddingSize));
				$paddingOwnerPopup = str_repeat(" ", max(0, $paddingSize));
				$popup = TextFormat::WHITE . $paddingPopup . $popup . "\n" . TextFormat::WHITE . $paddingOwnerPopup . $ownerPopup;
			}else{
				$ownerPopup = $this->plugin->getLanguage()->translateString("popup.available");
				$paddingSize = (int) floor((strlen($popup) - strlen($ownerPopup)) / 2);
				$paddingPopup = str_repeat(" ", max(0, -$paddingSize));
				$paddingOwnerPopup = str_repeat(" ", max(0, $paddingSize));
				$popup = TextFormat::WHITE . $paddingPopup . $popup . "\n" . TextFormat::WHITE . $paddingOwnerPopup . $ownerPopup;
			}
			$event->getPlayer()->sendTip($popup);
		}elseif($plot === null and ($plot = $this->plugin->getPlotByPosition($event->getFrom())) !== null) {
			$ev = new MyPlotPlayerLeavePlotEvent($plot, $event->getPlayer());
			$ev->setCancelled($event->isCancelled());
			$ev->call();
			$event->setCancelled($ev->isCancelled());
		}
	}

	/**
	 * @ignoreCancelled false
	 * @priority LOWEST
	 *
	 * @param EntityDamageByEntityEvent $event
	 */
	public function onEntityDamage(EntityDamageByEntityEvent $event) : void {
		if($event->getEntity() instanceof Player and $event->getDamager() instanceof Player) {
			$worldName = $event->getEntity()->getWorld()->getFolderName();
			if(!$this->plugin->isLevelLoaded($worldName)) {
				return;
			}
			$settings = $this->plugin->getLevelSettings($worldName);
			$plot = $this->plugin->getPlotByPosition($event->getEntity());
			if($plot !== null) {
				/** @noinspection PhpParamsInspection */
				$ev = new MyPlotPvpEvent($plot, $event->getDamager(), $event->getEntity(), $event);
				$ev->setCancelled($event->isCancelled());
				/** @noinspection PhpUndefinedMethodInspection */
				if(($settings->restrictPVP or !$plot->pvp) and !$event->getDamager()->hasPermission("myplot.admin.pvp.bypass")) {
					$ev->setCancelled();
					$this->plugin->getLogger()->debug("Cancelled pvp event in plot ".$plot->X.";".$plot->Z." on world '" . $worldName . "'");
				}
				$ev->call();
				$event->setCancelled($ev->isCancelled());
				if($event->isCancelled()) {
					$ev->getAttacker()->sendMessage(TextFormat::RED . $this->plugin->getLanguage()->translateString("pvp.disabled")); // generic message- we dont know if by config or plot
				}
				return;
			}
			/** @noinspection PhpUndefinedMethodInspection */
			if($event->isCancelled() or $event->getDamager()->hasPermission("myplot.admin.pvp.bypass")) {
				return;
			}
			if($settings->restrictPVP) {
				$event->setCancelled();
				/** @noinspection PhpUndefinedMethodInspection */
				$event->getDamager()->sendMessage(TextFormat::RED.$this->plugin->getLanguage()->translateString("pvp.world"));
				$this->plugin->getLogger()->debug("Cancelled pvp event on ".$worldName);
			}
		}
	}
}