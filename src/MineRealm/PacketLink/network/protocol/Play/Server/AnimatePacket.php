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

namespace MineRealm\PacketLink\network\protocol\Play\Server;

use MineRealm\PacketLink\network\Packet;

class AnimatePacket extends Packet{

	public $eid;
	public $actionID;

	public function pid(){
		return 0x06;
	}

	public function encode(){
		$this->putVarInt($this->eid);
		$this->putByte($this->actionID);
	}

	public function decode(){

	}
}