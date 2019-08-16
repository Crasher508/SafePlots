<?php
declare(strict_types=1);
namespace MyPlot\subcommand;

use CortexPE\Commando\args\TargetArgument;
use pocketmine\command\CommandSender;
use pocketmine\OfflinePlayer;
use pocketmine\Player;
use pocketmine\utils\TextFormat;

class UnDenySubCommand extends SubCommand
{
	/**
	 * @param CommandSender $sender
	 *
	 * @return bool
	 */
	public function canUse(CommandSender $sender) : bool {
		return ($sender instanceof Player) and $sender->hasPermission("myplot.command.undenyplayer");
	}

	/**
	 * @param Player $sender
	 * @param string[] $args
	 *
	 * @return bool
	 */
	public function execute(CommandSender $sender, array $args) : bool {
		if(empty($args)) {
			return false;
		}
		$dplayerName = $args[0];
		$plot = $this->getPlugin()->getPlotByPosition($sender);
		if($plot === null) {
			$sender->sendMessage(TextFormat::RED . $this->translateString("notinplot"));
			return true;
		}
		if($plot->owner !== $sender->getName() and !$sender->hasPermission("myplot.admin.undenyplayer")) {
			$sender->sendMessage(TextFormat::RED . $this->translateString("notowner"));
			return true;
		}
		if(!$plot->unDenyPlayer($dplayerName)) {
			$sender->sendMessage(TextFormat::RED . $this->translateString("undenyplayer.failure", [$dplayerName]));
			return true;
		}
		$dplayer = $this->getPlugin()->getServer()->getPlayer($dplayerName);
		if($dplayer === null)
			$dplayer = new OfflinePlayer($this->getPlugin()->getServer(), $dplayerName);
		if($this->getPlugin()->removePlotDenied($plot, $dplayer->getName())) {
			$sender->sendMessage($this->translateString("undenyplayer.success1", [$dplayer->getName()]));
			if($dplayer instanceof Player) {
				$dplayer->sendMessage($this->translateString("undenyplayer.success2", [$plot->X, $plot->Z, $sender->getName()]));
			}
		}else{
			$sender->sendMessage(TextFormat::RED . $this->translateString("error"));
		}
		return true;
	}

	/**
	 * This is where all the arguments, permissions, sub-commands, etc would be registered
	 */
	protected function prepare() : void {
		$this->registerArgument(0, new TargetArgument("player", false));
		// TODO: Implement prepare() method.
	}

	public function onRun(CommandSender $sender, string $aliasUsed, array $args) : void {
		// TODO: Implement onRun() method.
	}
}