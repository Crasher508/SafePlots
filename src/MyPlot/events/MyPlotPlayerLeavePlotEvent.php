<?php
declare(strict_types=1);
namespace MyPlot\events;

use MyPlot\Plot;
use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;
use pocketmine\player\Player;

class MyPlotPlayerLeavePlotEvent extends MyPlotPlotEvent implements Cancellable {
	use CancellableTrait;

	/** @var Player $player */
	private $player;

	/**
	 * MyPlotPlayerLeavePlotEvent constructor.
	 *
	 * @param Plot $plot
	 * @param Player $player
	 */
	public function __construct(Plot $plot, Player $player) {
		$this->player = $player;
		parent::__construct($plot);
	}

	/**
	 * @return Player
	 */
	public function getPlayer() : Player {
		return $this->player;
	}

	/**
	 * @param Player $player
	 */
	public function setPlayer(Player $player) {
		$this->player = $player;
	}
}