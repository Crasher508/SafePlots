<?php
declare(strict_types=1);
/**
 * @name MyPlotWalls
 * @main Crasher508\MyPlotWalls\Main
 * @version 1.0.0
 * @api 3.0.0
 * @description A plugin script which allows players to set the block of the plot wall via form
 * @author Crasher508
 * @depend FormAPI
 * @softdepend MyPlot
 */
namespace jasonwynn10\MyPlotWalls {
	use jojoe77777\FormAPI\SimpleForm;
	use MyPlot\Commands;
	use MyPlot\MyPlot;
	use MyPlot\Plot;
	use MyPlot\subcommand\SubCommand;
	use pocketmine\block\Block;
	use pocketmine\block\BlockFactory;
	use pocketmine\command\CommandSender;
	use pocketmine\math\Vector3;
	use pocketmine\Player;
	use pocketmine\plugin\PluginBase;
	use pocketmine\scheduler\Task;
	use pocketmine\utils\TextFormat;
	class Main extends PluginBase {
		/** @var string[] $blocks */
		public static $blocks = [];
		/** @var self|null $instance */
		private static $instance = null;
		/**
		 * @return self|null
		 */
		public static function getInstance() : ?self {
			return self::$instance;
		}
		public function onEnable() {
			self::$instance = $this;
			$blocks = ["57:0"; "41:0", "47:0", "169:0", "89:0", "46:0", "173:0", "42:0", "133:0", "22:0"];
			foreach($blocks as $string) {
				$arr = explode(":", $string);
				$id = (int) $arr[0];
				$damage = (int) ($arr[1] ?? 0);
				$block = BlockFactory::get($id, $damage);
				self::$blocks[$string] = $block->getName();
			}
			/** @var Commands $commands */
			$commands = $this->getServer()->getCommandMap()->getCommand("plot");
			if(version_compare(MyPlot::getInstance()->getDescription()->getVersion(), "1.5.3", ">=")) {
				$command = new class(MyPlot::getInstance(), "wall") extends SubCommand {
					public function getName() : string {
						return "wall";
					}
					public function getAlias() : string {
						return "w";
					}
					public function canUse(CommandSender $sender) : bool {
						return ($sender instanceof Player) and $sender->hasPermission("myplot.command.wall");
					}
					/**
					 * @param Player $sender
					 * @param array $args
					 *
					 * @return bool
					 */
					public function execute(CommandSender $sender, array $args) : bool {
						$plot = $this->getPlugin()->getPlotByPosition($sender);
						if($plot === null) {
							$sender->sendMessage(TextFormat::RED . $this->getPlugin()->getLanguage()->translateString("notinplot"));
							return true;
						}
						if($plot->owner !== $sender->getName() and !$sender->hasPermission("wall.command.admin")) {
							$sender->sendMessage(TextFormat::RED . $this->getPlugin()->getLanguage()->translateString("notowner"));
							return true;
						}
						$sender->sendForm(new class(function($player, $data) use ($plot) {
							if($data === null)
								return; // form cancelled. do nothing
							$arr = explode(":", $data);
							$id = (int) $arr[0];
							$damage = (int) $arr[1];
							$block = BlockFactory::get($id, $damage);
							Main::getInstance()->setPlotBorderBlocks($plot, $block, $sender);
						}) extends SimpleForm {
							public function __construct(?callable $callable) {
								$this->setTitle("Plot Wall");
								foreach(Main::$blocks as $id => $name) {
									$this->addButton($name);
								}
								parent::__construct($callable);
							}
							public function processData(&$data) : void {
								$i = 0;
								foreach(Main::$blocks as $id => $name) {
									if($i === $data) {
										$data = $id;
										return;
									}
									$i++;
								}
							}
						});
						return true;
					}
				};
				$commands->loadSubCommand($command);
				$this->getLogger()->debug("SubCommand loaded");
			}else{
				$command = new class(MyPlot::getInstance(), "wall") extends SubCommand {
					public function canUse(CommandSender $sender) : bool {
						return ($sender instanceof Player) and $sender->hasPermission("myplot.command.wall");
					}
					/**
					 * @param Player $sender
					 * @param array $args
					 *
					 * @return bool
					 */
					public function execute(CommandSender $sender, array $args) : bool {
						$plot = $this->getPlugin()->getPlotByPosition($sender);
						if($plot === null) {
							$sender->sendMessage(TextFormat::RED . $this->getPlugin()->getLanguage()->translateString("notinplot"));
							return true;
						}
						if($plot->owner !== $sender->getName() and !$sender->hasPermission("wall.command.admin")) {
							$sender->sendMessage(TextFormat::RED . $this->getPlugin()->getLanguage()->translateString("notowner"));
							return true;
						}
						$sender->sendForm(new class(function($player, $data) use ($plot) {
							if($data === null)
								return; // form cancelled. do nothing
							$arr = explode(":", $data);
							$id = (int) $arr[0];
							$damage = (int) $arr[1];
							$block = BlockFactory::get($id, $damage);
							Main::getInstance()->setPlotBorderBlocks($plot, $block, $sender);
						}) extends SimpleForm {
							public function __construct(?callable $callable) {
								$this->setTitle("Plot Wall");
								foreach(Main::$blocks as $id => $name) {
									$this->addButton($name);
								}
								parent::__construct($callable);
							}
							public function processData(&$data) : void {
								$i = 0;
								foreach(Main::$blocks as $id => $name) {
									if($i === $data) {
										$data = $id;
										return;
									}
									$i++;
								}
							}
						});
						return true;
					}
				};
				$refObject = new \ReflectionClass($commands);
				$refProperty = $refObject->getProperty("subCommands");
				$refProperty->setAccessible(true);
				$val = $refProperty->getValue($commands);
				$val["wall"] = $command;
				$refProperty->setValue($commands, $val);
				$refObject = new \ReflectionClass($commands);
				$refProperty = $refObject->getProperty("aliasSubCommands");
				$refProperty->setAccessible(true);
				$val = $refProperty->getValue($commands);
				$val["w"] = $command;
				$refProperty->setValue($commands, $val);
			}
		}
		public function setPlotBorderBlocks(Plot $plot, Block $block, Player $player) {
			$this->getScheduler()->scheduleTask(new class(MyPlot::getInstance(), $plot, $block, $player) extends Task {
				private $plot, $level, $height, $plotWallBlock, $plotBeginPos, $xMax, $zMax;
				public function __construct(MyPlot $plugin, Plot $plot, Block $block, Player $player) {
					$this->plot = $plot;
					$this->player = $player;
					$this->plotBeginPos = $plugin->getPlotPosition($plot);
					$this->level = $this->plotBeginPos->getLevel();
					$this->plotBeginPos = $this->plotBeginPos->subtract(1,0,1);
					$plotLevel = $plugin->getLevelSettings($plot->levelName);
					$plotSize = $plotLevel->plotSize;
					$this->xMax = $this->plotBeginPos->x + $plotSize + 1;
					$this->zMax = $this->plotBeginPos->z + $plotSize + 1;
					$this->height = $plotLevel->groundHeight;
					$this->plotWallBlock = $block;
				}
				public function onRun(int $currentTick) : void {
					if($this->height === 32){
						$hs = array(0,1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,31,32);
						foreach($hs as $h){
					        for($x = $this->plotBeginPos->x; $x <= $this->xMax; $x++) {
						        $this->level->setBlock(new Vector3($x, $h, $this->plotBeginPos->z), $this->plotWallBlock, false, false);
						        $this->level->setBlock(new Vector3($x, $h, $this->zMax), $this->plotWallBlock, false, false);
					        }
					        for($z = $this->plotBeginPos->z; $z <= $this->zMax; $z++) {
						        $this->level->setBlock(new Vector3($this->plotBeginPos->x, $this->height + 1, $z), $this->plotWallBlock, false, false);
						        $this->level->setBlock(new Vector3($this->xMax, $h, $z), $this->plotWallBlock, false, false);
							}
						}
				    }elseif($this->height === 64){
						$hs = array(0,1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,31,32,33,34,35,36,37,38,39,40,41,42,43,44,45,46,47,48,49,50,51,52,53,54,55,56,57,58,59,60,61,62,63,64);
						foreach($hs as $h){
					        for($x = $this->plotBeginPos->x; $x <= $this->xMax; $x++) {
						        $this->level->setBlock(new Vector3($x, $h, $this->plotBeginPos->z), $this->plotWallBlock, false, false);
						        $this->level->setBlock(new Vector3($x, $h, $this->zMax), $this->plotWallBlock, false, false);
					        }
					        for($z = $this->plotBeginPos->z; $z <= $this->zMax; $z++) {
						        $this->level->setBlock(new Vector3($this->plotBeginPos->x, $this->height + 1, $z), $this->plotWallBlock, false, false);
						        $this->level->setBlock(new Vector3($this->xMax, $h, $z), $this->plotWallBlock, false, false);
							}
						}
					}else{
						$this->player->sendMessage("§cUnable world height. Please use 32 or 64!")
					}
				}
			});
		}
	}
}
