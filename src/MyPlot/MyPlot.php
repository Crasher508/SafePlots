<?php
declare(strict_types=1);
namespace MyPlot;

use EssentialsPE\Loader;
use muqsit\worldstyler\shapes\Cuboid;
use muqsit\worldstyler\WorldStyler;
use MyPlot\events\MyPlotClearEvent;
use MyPlot\events\MyPlotDisposeEvent;
use MyPlot\events\MyPlotGenerationEvent;
use MyPlot\events\MyPlotResetEvent;
use MyPlot\events\MyPlotSettingEvent;
use MyPlot\events\MyPlotTeleportEvent;
use MyPlot\provider\DataProvider;
use MyPlot\provider\EconomyProvider;
use MyPlot\provider\EconomySProvider;
use MyPlot\provider\EssentialsPEProvider;
use MyPlot\provider\JSONDataProvider;
use MyPlot\provider\MySQLProvider;
use MyPlot\provider\PocketMoneyProvider;
use MyPlot\provider\SQLiteDataProvider;
use MyPlot\provider\YAMLDataProvider;
use MyPlot\task\ClearPlotTask;
use onebone\economyapi\EconomyAPI;
use pocketmine\block\BlockFactory;
use pocketmine\block\BlockLegacyIds;
use pocketmine\event\world\WorldLoadEvent;
use pocketmine\lang\Language;
use pocketmine\player\Player;
use pocketmine\world\biome\Biome;
use pocketmine\world\format\Chunk;
use pocketmine\world\generator\GeneratorManager;
use pocketmine\world\World;
use pocketmine\world\Position;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\permission\Permission;
use pocketmine\permission\PermissionManager;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat as TF;
use PocketMoney\PocketMoney;
use spoondetector\SpoonDetector;

class MyPlot extends PluginBase
{
	/** @var MyPlot $instance */
	private static $instance;
	/** @var PlotLevelSettings[] $worlds */
	private $worlds = [];
	/** @var DataProvider $dataProvider */
	private $dataProvider = null;
	/** @var EconomyProvider $economyProvider */
	private $economyProvider = null;
	/** @var Language $baseLang */
	private $baseLang = null;

	/**
	 * @return MyPlot
	 */
	public static function getInstance() : self {
		return self::$instance;
	}

	/**
	 * Returns the Multi-lang management class
	 *
	 * @api
	 *
	 * @return Language
	 */
	public function getLanguage() : Language {
		return $this->baseLang;
	}

	/**
	 * Returns the DataProvider that is being used
	 *
	 * @api
	 *
	 * @return DataProvider
	 */
	public function getProvider() : DataProvider {
		return $this->dataProvider;
	}

	/**
	 * Returns the EconomyProvider that is being used
	 *
	 * @api
	 *
	 * @return EconomyProvider|null
	 */
	public function getEconomyProvider() : ?EconomyProvider {
		return $this->economyProvider;
	}

	/**
	 * Allows setting the economy provider to a custom provider or to null to disable economy mode
	 *
	 * @api
	 *
	 * @param EconomyProvider|null $provider
	 */
	public function setEconomyProvider(?EconomyProvider $provider) : void {
		if($provider === null) {
			$this->getConfig()->set("UseEconomy", false);
			$this->getLogger()->info("Economy mode disabled!");
		}else{
			$this->getLogger()->info("A custom economy provider has been registered. Economy mode now enabled!");
			$this->getConfig()->set("UseEconomy", true);
			$this->economyProvider = $provider;
		}
	}

	/**
	 * Returns a PlotLevelSettings object which contains all the settings of a world
	 *
	 * @api
	 *
	 * @param string $worldName
	 *
	 * @return PlotLevelSettings|null
	 */
	public function getLevelSettings(string $worldName) : ?PlotLevelSettings {
		return $this->worlds[$worldName] ?? null;
	}

	/**
	 * Checks if a plot world is loaded
	 *
	 * @api
	 *
	 * @param string $worldName
	 *
	 * @return bool
	 */
	public function isLevelLoaded(string $worldName) : bool {
		return isset($this->worlds[$worldName]);
	}

	/**
	 * Generate a new plot world with optional settings
	 *
	 * @api
	 *
	 * @param string $worldName
	 * @param string $generator
	 * @param array $settings
	 *
	 * @return bool
	 */
	public function generateLevel(string $worldName, string $generator = "myplot", array $settings = []) : bool {
		$ev = new MyPlotGenerationEvent($worldName, $generator, $settings);
		$ev->call();
		$worldManager = $this->getServer()->getWorldManager();
		if($ev->isCancelled() or $worldManager->isWorldGenerated($worldName)) {
			return false;
		}
		$generator = GeneratorManager::getGenerator($generator);
		if(empty($settings)) {
			$this->getConfig()->reload();
			$settings = $this->getConfig()->get("DefaultWorld", []);
		}
		$default = array_filter($this->getConfig()->get("DefaultWorld", []), function($key){
			return !in_array($key, ["PlotSize", "GroundHeight", "RoadWidth", "RoadBlock", "WallBlock", "PlotFloorBlock", "PlotFillBlock", "BottomBlock"]);
		}, ARRAY_FILTER_USE_KEY);
		new Config($this->getDataFolder()."worlds".DIRECTORY_SEPARATOR.$worldName.".yml", Config::YAML, $default);
		$settings = ["preset" => json_encode($settings)];
		$return = $worldManager->generateWorld($worldName, null, $generator, $settings);
		$world = $worldManager->getWorldByName($worldName);
		if($world !== null)
			$world->setSpawnLocation(new Vector3(0,(int)$this->getConfig()->getNested("DefaultWorld.GroundHeight", 64) + 1,0));
		return $return;
	}

	/**
	 * Saves provided plot if changed
	 *
	 * @api
	 *
	 * @param Plot $plot
	 *
	 * @return bool
	 */
	public function savePlot(Plot $plot) : bool {
		return $this->dataProvider->savePlot($plot);
	}

	/**
	 * Get all the plots a player owns (in a certain world if $worldName is provided)
	 *
	 * @api
	 *
	 * @param string $username
	 * @param string $worldName
	 *
	 * @return Plot[]
	 */
	public function getPlotsOfPlayer(string $username, string $worldName) : array {
		return $this->dataProvider->getPlotsByOwner($username, $worldName);
	}

	/**
	 * Get the next free plot in a world
	 *
	 * @api
	 *
	 * @param string $worldName
	 * @param int $limitXZ
	 *
	 * @return Plot|null
	 */
	public function getNextFreePlot(string $worldName, int $limitXZ = 0) : ?Plot {
		return $this->dataProvider->getNextFreePlot($worldName, $limitXZ);
	}

	/**
	 * Finds the plot at a certain position or null if there is no plot at that position
	 *
	 * @api
	 *
	 * @param Position $position
	 *
	 * @return Plot|null
	 */
	public function getPlotByPosition(Position $position) : ?Plot {
		$x = $position->x;
		$z = $position->z;
		$worldName = $position->world->getFolderName();

		$plotWorld = $this->getLevelSettings($worldName);
		if($plotWorld === null)
			return null;
		$plotSize = $plotWorld->plotSize;
		$roadWidth = $plotWorld->roadWidth;
		$totalSize = $plotSize + $roadWidth;
		if($x >= 0) {
			$X = (int) floor($x / $totalSize);
			$difX = $x % $totalSize;
		}else{
			$X = (int) ceil(($x - $plotSize + 1) / $totalSize);
			$difX = abs(($x - $plotSize + 1) % $totalSize);
		}
		if($z >= 0) {
			$Z = (int) floor($z / $totalSize);
			$difZ = $z % $totalSize;
		}else{
			$Z = (int) ceil(($z - $plotSize + 1) / $totalSize);
			$difZ = abs(($z - $plotSize + 1) % $totalSize);
		}
		if(($difX > $plotSize - 1) or ($difZ > $plotSize - 1)) {
			return null;
		}
		return $this->dataProvider->getPlot($worldName, $X, $Z);
	}

	/**
	 * Get the begin position of a plot
	 *
	 * @api
	 *
	 * @param Plot $plot
	 *
	 * @return Position|null
	 */
	public function getPlotPosition(Plot $plot) : ?Position {
		$plotWorld = $this->getLevelSettings($plot->levelName);
		if($plotWorld === null)
			return null;
		$plotSize = $plotWorld->plotSize;
		$roadWidth = $plotWorld->roadWidth;
		$totalSize = $plotSize + $roadWidth;
		$x = $totalSize * $plot->X;
		$z = $totalSize * $plot->Z;
		$world = $this->getServer()->getWorldManager()->getWorldByName($plot->levelName);
		return new Position($x, $plotWorld->groundHeight, $z, $world);
	}

	/**
	 * Returns the AABB of the plot area
	 *
	 * @api
	 *
	 * @param Plot $plot
	 *
	 * @return AxisAlignedBB|null
	 */
	public function getPlotBB(Plot $plot) : ?AxisAlignedBB {
		$plotWorld = $this->getLevelSettings($plot->levelName);
		if($plotWorld === null)
			return null;
		$pos = $this->getPlotPosition($plot);
		$plotSize = $plotWorld->plotSize-1;

		return new AxisAlignedBB(
			min($pos->x, $pos->x + $plotSize),
			0,
			min($pos->z, $pos->z + $plotSize),
			max($pos->x, $pos->x + $plotSize),
			$pos->getWorld()->getWorldHeight(),
			max($pos->z, $pos->z + $plotSize)
		);
	}

	/**
	 * Teleport a player to a plot
	 *
	 * @api
	 *
	 * @param Player $player
	 * @param Plot $plot
	 * @param bool $center
	 *
	 * @return bool
	 */
	public function teleportPlayerToPlot(Player $player, Plot $plot, bool $center = false) : bool {
		$ev = new MyPlotTeleportEvent($plot, $player, $center);
		$ev->call();
		if($ev->isCancelled()) {
			return false;
		}
		if($center)
			return $this->teleportMiddle($player, $plot);
		$plotWorld = $this->getLevelSettings($plot->levelName);
		if($plotWorld === null)
			return false;
		$pos = $this->getPlotPosition($plot);
		$pos->x += floor($plotWorld->plotSize / 2);
		$pos->y += 1.5;
		$pos->z -= 1;
		return $player->teleport($pos);
	}

	/**
	 * Claims a plot in a players name
	 *
	 * @api
	 *
	 * @param Plot $plot
	 * @param string $claimer
	 * @param string $plotName
	 *
	 * @return bool
	 */
	public function claimPlot(Plot $plot, string $claimer, string $plotName = "") : bool {
		$newPlot = clone $plot;
		$newPlot->owner = $claimer;
		$ev = new MyPlotSettingEvent($plot, $newPlot);
		$ev->call();
		if($ev->isCancelled()) {
			return false;
		}
		$plot = $ev->getPlot();
		if(!empty($plotName)) {
			$this->renamePlot($plot, $plotName);
		}
		return $this->savePlot($plot);
	}

	/**
	 * @param Plot $plot
	 * @param string $newName
	 *
	 * @return bool
	 */
	public function renamePlot(Plot $plot, string $newName = "") : bool {
		$newPlot = clone $plot;
		$newPlot->name = $newName;
		$ev = new MyPlotSettingEvent($plot, $newPlot);
		$ev->call();
		if($ev->isCancelled()) {
			return false;
		}
		return $this->savePlot($ev->getPlot());
	}

	/**
	 * Reset all the blocks inside a plot
	 *
	 * @api
	 *
	 * @param Plot $plot
	 * @param int $maxBlocksPerTick
	 *
	 * @return bool
	 */
	public function clearPlot(Plot $plot, int $maxBlocksPerTick = 256) : bool {
		if(!$this->isLevelLoaded($plot->levelName)) {
			return false;
		}
		$ev = new MyPlotClearEvent($plot, $maxBlocksPerTick);
		$ev->call();
		if($ev->isCancelled()) {
			return false;
		}
		$plot = $ev->getPlot();
		$maxBlocksPerTick = $ev->getMaxBlocksPerTick();
		foreach($this->getServer()->getWorldManager()->getWorldByName($plot->levelName)->getEntities() as $entity) {
			if($this->getPlotBB($plot)->isVectorInXZ($entity)) {
				if(!$entity instanceof Player) {
					$entity->flagForDespawn();
				}else{
					$this->teleportPlayerToPlot($entity, $plot);
				}
			}
		}
		if($this->getConfig()->get("FastClearing", false)) {
			/** @var WorldStyler $styler */
			$styler = $this->getServer()->getPluginManager()->getPlugin("WorldStyler");
			$plotWorld = $this->getLevelSettings($plot->levelName);
			$plotSize = $plotWorld->plotSize-1;
			$plotBeginPos = $this->getPlotPosition($plot);
			$plugin = $this;
			// Above ground
			$selection = $styler->getSelection(99998);
			$plotBeginPos->y = $plotWorld->groundHeight+1;
			$selection->setPosition(1, $plotBeginPos);
			$selection->setPosition(2, new Vector3($plotBeginPos->x + $plotSize, World::Y_MAX, $plotBeginPos->z + $plotSize));
			$cuboid = Cuboid::fromSelection($selection);
			//$cuboid = $cuboid->async();
			$cuboid->set($plotBeginPos->world, BlockFactory::get(BlockLegacyIds::AIR)->getFullId(), function (float $time, int $changed) use ($plugin) : void {
				$plugin->getLogger()->debug('Set ' . number_format($changed) . ' blocks in ' . number_format($time, 10) . 's');
			});
			$styler->removeSelection(99998);
			// Ground Surface
			$selection = $styler->getSelection(99998);
			$plotBeginPos->y = $plotWorld->groundHeight;
			$selection->setPosition(1, $plotBeginPos);
			$selection->setPosition(2, new Vector3($plotBeginPos->x + $plotSize, $plotWorld->groundHeight, $plotBeginPos->z + $plotSize));
			$cuboid = Cuboid::fromSelection($selection);
			//$cuboid = $cuboid->async();
			$cuboid->set($plotBeginPos->world, $plotWorld->plotFloorBlock->getFullId(), function (float $time, int $changed) use ($plugin) : void {
				$plugin->getLogger()->debug('Set ' . number_format($changed) . ' blocks in ' . number_format($time, 10) . 's');
			});
			$styler->removeSelection(99998);
			// Ground
			$selection = $styler->getSelection(99998);
			$plotBeginPos->y = 1;
			$selection->setPosition(1, $plotBeginPos);
			$selection->setPosition(2, new Vector3($plotBeginPos->x + $plotSize, $plotWorld->groundHeight-1, $plotBeginPos->z + $plotSize));
			$cuboid = Cuboid::fromSelection($selection);
			//$cuboid = $cuboid->async();
			$cuboid->set($plotBeginPos->world, $plotWorld->plotFillBlock->getFullId(), function (float $time, int $changed) use ($plugin) : void {
				$plugin->getLogger()->debug('Set ' . number_format($changed) . ' blocks in ' . number_format($time, 10) . 's');
			});
			$styler->removeSelection(99998);
			// Bottom of world
			$selection = $styler->getSelection(99998);
			$plotBeginPos->y = 0;
			$selection->setPosition(1, $plotBeginPos);
			$selection->setPosition(2, new Vector3($plotBeginPos->x + $plotSize, 0, $plotBeginPos->z + $plotSize));
			$cuboid = Cuboid::fromSelection($selection);
			//$cuboid = $cuboid->async();
			$cuboid->set($plotBeginPos->world, $plotWorld->bottomBlock->getFullId(), function (float $time, int $changed) use ($plugin) : void {
				$plugin->getLogger()->debug('Set ' . number_format($changed) . ' blocks in ' . number_format($time, 10) . 's');
			});
			$styler->removeSelection(99998);
			return true;
		}
		$this->getScheduler()->scheduleTask(new ClearPlotTask($this, $plot, $maxBlocksPerTick));
		return true;
	}

	/**
	 * Delete the plot data
	 *
	 * @api
	 *
	 * @param Plot $plot
	 *
	 * @return bool
	 */
	public function disposePlot(Plot $plot) : bool {
		$ev = new MyPlotDisposeEvent($plot);
		$ev->call();
		if($ev->isCancelled()) {
			return false;
		}
		return $this->dataProvider->deletePlot($plot);
	}

	/**
	 * Clear and dispose a plot
	 *
	 * @api
	 *
	 * @param Plot $plot
	 * @param int $maxBlocksPerTick
	 *
	 * @return bool
	 */
	public function resetPlot(Plot $plot, int $maxBlocksPerTick = 256) : bool {
		$ev = new MyPlotResetEvent($plot);
		$ev->call();
		if($ev->isCancelled())
			return false;
		if($this->disposePlot($plot)) {
			return $this->clearPlot($plot, $maxBlocksPerTick);
		}
		return false;
	}

	/**
	 * Changes the biome of a plot
	 *
	 * @api
	 *
	 * @param Plot $plot
	 * @param Biome $biome
	 *
	 * @return bool
	 */
	public function setPlotBiome(Plot $plot, Biome $biome) : bool {
		$newPlot = clone $plot;
		$newPlot->biome = strtoupper($biome->getName());
		$ev = new MyPlotSettingEvent($plot, $newPlot);
		$ev->call();
		if($ev->isCancelled()) {
			return false;
		}
		$plot = $ev->getPlot();
		$biome = Biome::getBiome(constant(Biome::class . "::" . $plot->biome) ?? Biome::PLAINS);
		$plotWorld = $this->getLevelSettings($plot->levelName);
		if($plotWorld === null) {
			return false;
		}
		$world = $this->getServer()->getWorldManager()->getWorldByName($plot->levelName);
		$pos = $this->getPlotPosition($plot);
		$plotSize = $plotWorld->plotSize;
		$xMax = $pos->x + $plotSize;
		$zMax = $pos->z + $plotSize;
		$chunkIndexes = [];
		for($x = $pos->x; $x < $xMax; $x++) {
			for($z = $pos->z; $z < $zMax; $z++) {
				$index = World::chunkHash($x >> 4, $z >> 4);
				if(!in_array($index, $chunkIndexes)) {
					$chunkIndexes[] = $index;
				}
				World::getXZ($index, $cx, $cz);
				$chunk = $world->getChunk($cx, $cz, true);
				$chunk->setBiomeId($x, $z, $biome->getId());
			}
		}
		foreach($chunkIndexes as $index) {
			World::getXZ($index, $cx, $cz);
			$chunk = $world->getChunk($cx, $cz, true);
			foreach($world->getViewersForPosition(new Vector3($cx, 0, $cz)) as $player) {
				$player->onChunkChanged($chunk);
			}
		}
		$this->savePlot($plot);
		return true;
	}

	/**
	 * @param Plot $plot
	 * @param bool $pvp
	 *
	 * @return bool
	 */
	public function setPlotPvp(Plot $plot, bool $pvp) : bool {
		$newPlot = clone $plot;
		$newPlot->pvp = $pvp;
		$ev = new MyPlotSettingEvent($plot, $newPlot);
		$ev->call();
		if($ev->isCancelled()) {
			return false;
		}
		return $this->savePlot($ev->getPlot());
	}

	/**
	 * @param Plot $plot
	 * @param string $player
	 *
	 * @return bool
	 */
	public function addPlotHelper(Plot $plot, string $player) : bool {
		$newPlot = clone $plot;
		$ev = new MyPlotSettingEvent($plot, $newPlot);
		$ev->setCancelled(!$newPlot->addHelper($player));
		$ev->call();
		if($ev->isCancelled()) {
			return false;
		}
		return $this->savePlot($ev->getPlot());
	}

	/**
	 * @param Plot $plot
	 * @param string $player
	 *
	 * @return bool
	 */
	public function removePlotHelper(Plot $plot, string $player) : bool {
		$newPlot = clone $plot;
		$ev = new MyPlotSettingEvent($plot, $newPlot);
		$ev->setCancelled(!$newPlot->removeHelper($player));
		$ev->call();
		if($ev->isCancelled()) {
			return false;
		}
		return $this->savePlot($ev->getPlot());
	}

	/**
	 * @param Plot $plot
	 * @param string $player
	 *
	 * @return bool
	 */
	public function addPlotDenied(Plot $plot, string $player) : bool {
		$newPlot = clone $plot;
		$ev = new MyPlotSettingEvent($plot, $newPlot);
		$ev->setCancelled(!$newPlot->denyPlayer($player));
		$ev->call();
		if($ev->isCancelled()) {
			return false;
		}
		return $this->savePlot($ev->getPlot());
	}

	/**
	 * @param Plot $plot
	 * @param string $player
	 *
	 * @return bool
	 */
	public function removePlotDenied(Plot $plot, string $player) : bool {
		$newPlot = clone $plot;
		$ev = new MyPlotSettingEvent($plot, $newPlot);
		$ev->setCancelled(!$newPlot->unDenyPlayer($player));
		$ev->call();
		if($ev->isCancelled()) {
			return false;
		}
		return $this->savePlot($ev->getPlot());
	}

	/**
	 * Returns the PlotLevelSettings of all the loaded worlds
	 *
	 * @api
	 *
	 * @return PlotLevelSettings[]
	 */
	public function getPlotLevels() : array {
		return $this->worlds;
	}

	/**
	 * Returns the Chunks contained in a plot
	 *
	 * @api
	 *
	 * @param Plot $plot
	 *
	 * @return Chunk[]
	 */
	public function getPlotChunks(Plot $plot) : array {
		$plotWorld = $this->getLevelSettings($plot->levelName);
		$world = $this->getServer()->getWorldManager()->getWorldByName($plot->levelName);
		$pos = $this->getPlotPosition($plot);
		$plotSize = $plotWorld->plotSize;
		$xMax = ($pos->x + $plotSize) >> 4;
		$zMax = ($pos->z + $plotSize) >> 4;
		$chunks = [];
		for($x = $pos->x >> 4; $x < $xMax; $x++) {
			for($z = $pos->z >> 4; $z < $zMax; $z++) {
				$chunks[] = $world->getChunk($x, $z, true);
			}
		}
		return $chunks;
	}

	/**
	 * Get the maximum number of plots a player can claim
	 *
	 * @api
	 *
	 * @param Player $player
	 *
	 * @return int
	 */
	public function getMaxPlotsOfPlayer(Player $player) : int {
		if($player->hasPermission("myplot.claimplots.unlimited"))
			return PHP_INT_MAX;
		/** @var Permission[] $perms */
		$perms = array_merge(PermissionManager::getInstance()->getDefaultPermissions($player->isOp()), $player->getEffectivePermissions());
		$perms = array_filter($perms, function($name) {
			return (substr($name, 0, 18) === "myplot.claimplots.");
		}, ARRAY_FILTER_USE_KEY);
		if(count($perms) === 0)
			return 0;
		krsort($perms, SORT_FLAG_CASE | SORT_NATURAL);
		foreach($perms as $name => $perm) {
			$maxPlots = substr($name, 18);
			if(is_numeric($maxPlots)) {
				return (int) $maxPlots;
			}
		}
		return 0;
	}

	/**
	 * Finds the exact center of the plot at ground world
	 *
	 * @api
	 *
	 * @param Plot $plot
	 *
	 * @return Position|null
	 */
	public function getPlotMid(Plot $plot) : ?Position {
		$plotWorld = $this->getLevelSettings($plot->levelName);
		if($plotWorld === null) {
			return null;
		}
		$plotSize = $plotWorld->plotSize;
		$pos = $this->getPlotPosition($plot);
		$pos = new Position($pos->x + ($plotSize / 2), $pos->y + 1, $pos->z + ($plotSize / 2));
		return $pos;
	}

	/**
	 * Teleports the player to the exact center of the plot at nearest open space to the ground world
	 *
	 * @internal
	 *
	 * @param Plot $plot
	 * @param Player $player
	 *
	 * @return bool
	 */
	private function teleportMiddle(Player $player, Plot $plot) : bool {
		$mid = $this->getPlotMid($plot);
		if($mid === null) {
			return false;
		}
		return $player->teleport($mid);
	}

	/* -------------------------- Non-API part -------------------------- */
	public function onLoad() : void {
		$this->getLogger()->debug(TF::BOLD."Loading...");
		self::$instance = $this;
		$this->getLogger()->debug(TF::BOLD . "Loading Config");
		$this->saveDefaultConfig();
		$this->reloadConfig();
		@mkdir($this->getDataFolder() . "worlds");
		$this->getLogger()->debug(TF::BOLD . "Loading MyPlot Generator");
		GeneratorManager::addGenerator(MyPlotGenerator::class, "myplot", true);
		$this->getLogger()->debug(TF::BOLD . "Loading Languages");
		// Loading Languages
		/** @var string $lang */
		$lang = $this->getConfig()->get("language", Language::FALLBACK_LANGUAGE);
		$this->baseLang = new Language($lang, $this->getFile() . "resources/");
		$this->getLogger()->debug(TF::BOLD . "Loading Data Provider settings");
		// Initialize DataProvider
		/** @var int $cacheSize */
		$cacheSize = $this->getConfig()->get("PlotCacheSize", 256);
		switch(strtolower($this->getConfig()->get("DataProvider", "sqlite3"))) {
			case "mysql":
				if(extension_loaded("mysqli")) {
					$settings = $this->getConfig()->get("MySQLSettings");
					$this->dataProvider = new MySQLProvider($this, $cacheSize, $settings);
				}else {
					$this->getLogger()->info("MySQLi is not installed in your php build! SQLite3 will be used instead.");
					$this->dataProvider = new SQLiteDataProvider($this, $cacheSize);
				}
			break;
			case "yaml":
				$this->dataProvider = new YAMLDataProvider($this, $cacheSize);
			break;
			case "json":
				$this->dataProvider = new JSONDataProvider($this, $cacheSize);
			break;
			case "sqlite3":
			case "sqlite":
			default:
				$this->dataProvider = new SQLiteDataProvider($this, $cacheSize);
			break;
		}
		$this->getLogger()->debug(TF::BOLD . "Loading MyPlot Commands");
		// Register command
		$this->getServer()->getCommandMap()->register("myplot", new Commands($this));
	}

	public function onEnable() : void {
		SpoonDetector::printSpoon($this, "spoon.txt");
		if($this->isDisabled()) {
			return;
		}
		$this->getLogger()->debug(TF::BOLD . "Loading economy settings");
		// Initialize EconomyProvider
		if($this->getConfig()->get("UseEconomy", false) === true) {
			if(($plugin = $this->getServer()->getPluginManager()->getPlugin("EconomyAPI")) !== null) {
				if($plugin instanceof EconomyAPI) {
					$this->economyProvider = new EconomySProvider($plugin);
					$this->getLogger()->debug("Eco set to EconomySProvider");
				}
				$this->getLogger()->debug("Eco not instance of EconomyAPI");
			}
			elseif(($plugin = $this->getServer()->getPluginManager()->getPlugin("EssentialsPE")) !== null) {
				if($plugin instanceof Loader) {
					$this->economyProvider = new EssentialsPEProvider($plugin);
					$this->getLogger()->debug("Eco set to EssentialsPE");
				}
				$this->getLogger()->debug("Eco not instance of EssentialsPE");
			}
			elseif(($plugin = $this->getServer()->getPluginManager()->getPlugin("PocketMoney")) !== null) {
				if($plugin instanceof PocketMoney) {
					$this->economyProvider = new PocketMoneyProvider($plugin);
					$this->getLogger()->debug("Eco set to PocketMoney");
				}
				$this->getLogger()->debug("Eco not instance of PocketMoney");
			}
			if(!isset($this->economyProvider)) {
				$this->getLogger()->info("No supported economy plugin found!");
				$this->getConfig()->set("UseEconomy", false);
				//$this->getConfig()->save();
			}
		}
		$this->getLogger()->debug(TF::BOLD . "Loading Events");
		$eventListener = new EventListener($this);
		$this->getServer()->getPluginManager()->registerEvents($eventListener, $this);
		$this->getLogger()->debug(TF::BOLD . "Registering Loaded Worlds");
		foreach($this->getServer()->getWorldManager()->getWorlds() as $world) {
			$eventListener->onLevelLoad(new WorldLoadEvent($world));
		}
		$this->getLogger()->debug(TF::BOLD.TF::GREEN."Enabled!");
	}

	/**
	 * @param string $worldName
	 * @param PlotLevelSettings $settings
	 *
	 * @return bool
	 */
	public function addLevelSettings(string $worldName, PlotLevelSettings $settings) : bool {
		$this->worlds[$worldName] = $settings;
		return true;
	}

	/**
	 * @param string $worldName
	 *
	 * @return bool
	 */
	public function unloadLevelSettings(string $worldName) : bool {
		if(isset($this->worlds[$worldName])) {
			unset($this->worlds[$worldName]);
			$this->getLogger()->debug("World " . $worldName . " settings unloaded!");
			return true;
		}
		return false;
	}

	public function onDisable() : void {
		if($this->dataProvider !== null)
			$this->dataProvider->close();
	}
}
