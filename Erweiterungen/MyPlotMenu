<?php
declare(strict_types=1);
/**
 * @name MyPlotMenu
 * @main Crasher508\MyPlotMenu\Main
 * @version 1.0.0
 * @api 3.0.0
 * @description A plugin script which allows players to open menu via form
 * @author Crasher508
 * @depend FormAPI
 * @softdepend MyPlot
 */
namespace Crasher508\MyPlotMenu {

	use MyPlot\Commands;
	use MyPlot\MyPlot;
	use MyPlot\Plot;
	use MyPlot\subcommand\SubCommand;
	use pocketmine\command\CommandSender;
	use pocketmine\OfflinePlayer;
	use pocketmine\Player;
	use pocketmine\Server;
	use pocketmine\plugin\PluginBase;
	use pocketmine\utils\TextFormat;
	use jojoe77777\FormAPI\SimpleForm;
	use jojoe77777\FormAPI\CustomForm;

	class Main extends PluginBase {
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
			$command = new class(MyPlot::getInstance(), "menu") extends SubCommand {
			
			  public function getName() : string {
						return "menu";
					}
					public function getAlias() : string {
						return "m";
					}
					public function getDescription() : string {
						return "Öffne das Plot Menü";
					}

					public function canUse(CommandSender $sender) : bool {
						return ($sender instanceof Player) and $sender->hasPermission("myplot.command.menu");
					}

				/**
				 * @param Player $sender
				 * @param array $args
				 *
				 * @return bool
				 */
				public function execute(CommandSender $sender, array $args) : bool {
					$plot = MyPlot::getInstance()->getPlotByPosition($sender);
					$levelName = $sender->getLevel()->getFolderName();
					$p = $sender->getName();
						$plots = $this->getPlugin()->getPlotsOfPlayer($p, $levelName);
						if (empty($plots)) {
							$sender->sendMessage("§l§bCG §c> §r§6Du befindest dich in §ckeiner §6Grundstückswelt.");
							return true;
						}
					if($plot === null) {
						$form = new SimpleForm(function (Player $sender, $data) {
							$result = $data;
						if ($result == null) {
						}
						switch ($result) {
							case 0:
										break;
									case 1:
									$command = "p help";
											$this->getPlugin()->getServer()->getCommandMap()->dispatch($sender, $command);
										break;
										case 2:
										$command = "p auto";
												$this->getPlugin()->getServer()->getCommandMap()->dispatch($sender, $command);
											break;
										case 3:
										$command = "p h";
												$this->getPlugin()->getServer()->getCommandMap()->dispatch($sender, $command);
											break;
									}
								});
								$form->setTitle("§l§bPlot Menü");
								$form->setContent("§6Du befindest dich gerade auf keinem Plot! Du kannst daher das Menü nur eingeschränkt nutzen.");
								$form->addButton("§4Abbruch");
								$form->addButton("§cHelp");
								$form->addButton("§cAuto");
								$form->addButton("§cHome");
								$form->sendToPlayer($sender);
						return true;
					}
					if($plot->owner !== $sender->getName() and !$sender->hasPermission("myplot.admin.menu")) {
						$sender->sendMessage("§l§bCG §c> §r§cDas Grundstück gehört dir nicht!");
						return true;
					}
			
					if (empty($args[0])) {
						$form = new SimpleForm(function (Player $sender, $data) {
							$result = $data;
						if ($result == null) {
						}
						switch ($result) {
							case 0:
										break;
									case 1:
									$command = "p help";
									$this->getPlugin()->getServer()->getCommandMap()->dispatch($sender, $command);
									break;
									case 2:
									$command = "p i";
									$this->getPlugin()->getServer()->getCommandMap()->dispatch($sender, $command);
									break;
									case 3:
									$command = "p middle";
									$this->getPlugin()->getServer()->getCommandMap()->dispatch($sender, $command);
									break;
									case 4:
									$command = "p claim";
									$this->getPlugin()->getServer()->getCommandMap()->dispatch($sender, $command);
									break;
									case 5:
									$api = $this->getPlugin()->getServer()->getPluginManager()->getPlugin("FormAPI");
					$form = $api->createCustomForm(function (Player $sender, $data){
						$result = $data[0];
									if($result != null){
									$command = "p addhelper $result";
											$this->getPlugin()->getServer()->getCommandMap()->dispatch($sender, $command);
									}else{
	
									}
								});
					$form->setTitle("§l§bHelfer hinzufügn");
					$form->addInput("§6Füge einen Helfer hinzu", "Name des Spielers");
					$form->sendToPlayer($sender);
									break;
									case 6:
									$api = $this->getPlugin()->getServer()->getPluginManager()->getPlugin("FormAPI");
							$form = $api->createCustomForm(function (Player $sender, $data){
								$result = $data[0];
											if($result != null){
											$command = "p removehelper $result";
													$this->getPlugin()->getServer()->getCommandMap()->dispatch($sender, $command);
											}else{
			
											}
										});
							$form->setTitle("§l§bHelfer entfernen");
							$form->addInput("§6Entfernen einen Helfer", "Name des Spielers");
							$form->sendToPlayer($sender);
									break;
									case 7:
									$command = "p auto";
									$this->getPlugin()->getServer()->getCommandMap()->dispatch($sender, $command);
									break;
						}
					});
					$plot = $this->getPlugin()->getPlotByPosition($sender);
					$form->setTitle("§l§bPlot Menü");
					$form->setContent("§6Plot Menü für das Grundstück§c $plot §6.");
					$form->addButton("§4Abbruch");
					$form->addButton("§cHelp");
					$form->addButton("§cInfo");
					$form->addButton("§cMiddle");
					$form->addButton("§cClaim");
					$form->addButton("§cHelfer hinzufügen");
					$form->addButton("§cHelfer entfernen");
					$form->addButton("§cAuto");
					$form->sendToPlayer($sender);
							return true;
				}
				return true;
			}
			};
			/** @var Commands $commands */
			$commands = $this->getServer()->getCommandMap()->getCommand("plot");
			$commands->loadSubCommand($command);
			$this->getLogger()->debug("SubCommand loaded");
		}
		
	}
}
