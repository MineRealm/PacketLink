<?php

/*
 * BigBrother plugin for PocketMine-MP
 * Copyright (C) 2014 shoghicp <https://github.com/shoghicp/BigBrother>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
*/

namespace MineRealm\PacketLink\network\protocol\Play;

use MineRealm\PacketLink\network\Packet;

class BlockActionPacket extends Packet{

	public $x;
	public $y;
	public $z;
	public $actionID;
	public $actionParam;
	public $blockType;

	public function pid(){
		return 0x0a;
	}

	public function encode(){
		$this->putPosition($this->x, $this->y, $this->z);
		$this->putByte($this->actionID);
		$this->putByte($this->actionParam);
		$this->putVarInt($this->blockType);
	}

	public function decode(){

	}
}