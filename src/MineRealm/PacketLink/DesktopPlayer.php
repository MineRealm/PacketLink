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

namespace MineRealm\PacketLink;

use pocketmine\event\Timings;
use pocketmine\level\Level;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\network\mcpe\protocol\RequestChunkRadiusPacket;
use pocketmine\network\SourceInterface;
use pocketmine\Player;
use pocketmine\Server;
use pocketmine\tile\Sign;
use pocketmine\utils\Utils;
use pocketmine\utils\UUID;
use pocketmine\utils\TextFormat;
use MineRealm\PacketLink\network\Packet;
use MineRealm\PacketLink\network\protocol\Login\EncryptionRequestPacket;
use MineRealm\PacketLink\network\protocol\Login\EncryptionResponsePacket;
use MineRealm\PacketLink\network\protocol\Login\LoginSuccessPacket;
use MineRealm\PacketLink\network\protocol\Play\Server\KeepAlivePacket;
use MineRealm\PacketLink\network\protocol\Play\ChunkDataPacket;
use MineRealm\PacketLink\network\protocol\Play\PlayerListPacket;
use MineRealm\PacketLink\network\protocol\Play\TitlePacket;
use MineRealm\PacketLink\network\ProtocolInterface;
use MineRealm\PacketLink\utils\Binary;

class DesktopPlayer extends Player{

	private $packetLink_status = 0; //0 = log in, 1 = playing
	protected $packetLink_uuid;
	protected $packetLink_formatedUUID;
	protected $packetLink_properties = [];
	private $packetLink_checkToken;
	private $packetLink_secret;
	private $packetLink_username;
	private $bigbrother_clientId;
	private $packetLink_dimension;
	protected $Settings = [];
	/** @var ProtocolInterface */
	protected $interface;

	public function __construct(SourceInterface $interface, $clientID, $address, $port, PacketLink $plugin){
		$this->plugin = $plugin;
		$this->bigbrother_clientId = $clientID;
		parent::__construct($interface, $clientID, $address, $port);
		$this->setRemoveFormat(false);// Color Code
	}

	public function packetLink_getDimension(){
		return $this->packetLink_dimension;
	}

	public function packetLink_getDimensionPEToPC($level_dimension){
		switch($level_dimension){
			case 0://Overworld
				$dimension = 0;
			break;
			case 1://Nether
				$dimension = -1;
			break;
			case 2://The End
				$dimension = 1;
			break;
		}
		$this->packetLink_dimension = $dimension;
		return $dimension;
	}

	public function packetLink_getStatus(){
		return $this->packetLink_status;
	}

	public function packetLink_getPeroperties(){
		return $this->packetLink_properties;
	}

	public function packetLink_getUniqueId(){
		return $this->packetLink_uuid;
	}

	public function getSettings(){
		return $this->Settings;
	}

	public function getSetting($settingname = null){
		if(isset($this->Settings[$settingname])){
			return $this->Settings[$settingname];
		}
		return false;
	}

	public function setSetting($settings){
		$this->Settings = array_merge($this->Settings, $settings);
	}

	public function removeSetting($settingname){
		if(isset($this->Settings[$settingname])){
			unset($this->Settings[$settingname]);
		}
	}

	public function cleanSetting($settingname){
		unset($this->Settings[$settingname]);
	}

	public function packetLink_sendChunk($x, $z){
		if($this->connected === false){
			return;
		}

		$this->usedChunks[Level::chunkHash($x, $z)] = true;
		$this->chunkLoadCount++;

		$blockEntities = [];
		foreach($this->level->getChunkTiles($x, $z) as $tile){
			$blockEntities[] = $tile->getSpawnCompound();
		}

		$chunk = new DesktopChunk($this, $x, $z);

		$pk = new ChunkDataPacket();
		$pk->chunkX = $x;
		$pk->chunkZ = $z;
		$pk->groundUp = true;
		$pk->primaryBitmap = $chunk->getBitMapData();
		$pk->payload = $chunk->getChunkData();
		$pk->biomes = $chunk->getBiomesData();
		$pk->blockEntities = $blockEntities;
		$this->putRawPacket($pk);

		if($this->spawned){
			foreach($this->level->getChunkEntities($x, $z) as $entity){
				if($entity !== $this and !$entity->closed and $entity->isAlive()){
					$entity->spawnTo($this);
				}
			}
		}
	}

	protected function sendNextChunk(){
		if($this->connected === false){
			return;
		}

		Timings::$playerChunkSendTimer->startTiming();

		$count = 0;
		foreach($this->loadQueue as $index => $distance){
			if($count >= $this->chunksPerTick){
				break;
			}

			$X = null;
			$Z = null;
			Level::getXZ($index, $X, $Z);
			++$count;

			$this->usedChunks[$index] = false;
			$this->level->registerChunkLoader($this, $X, $Z, false);

			if(!$this->level->populateChunk($X, $Z)){
				if($this->spawned and $this->teleportPosition === null){
					continue;
				}else{
					break;
				}
			}

			unset($this->loadQueue[$index]);
			$this->packetLink_sendChunk($X, $Z);
		}

		if($this->chunkLoadCount >= 4 and $this->spawned === false and $this->teleportPosition === null){
			$this->plugin->getServer()->sendFullPlayerListData($this);//PlayerList

			$this->doFirstSpawn();
			$this->inventory->sendContents($this);
			$this->inventory->sendArmorContents($this);
		}

		Timings::$playerChunkSendTimer->stopTiming();
	}

	public function packetLink_authenticate($uuid, $onlineModeData = null){
		if($this->packetLink_status === 0){
			$this->packetLink_uuid = $uuid;
			$this->packetLink_formatedUUID = Binary::UUIDtoString($this->packetLink_uuid);

			$this->interface->setCompression($this);

			$pk = new LoginSuccessPacket();
			$pk->uuid = $this->packetLink_formatedUUID;
			$pk->name = $this->packetLink_username;
			$this->putRawPacket($pk);

			$this->packetLink_status = 1;

			if($onlineModeData !== null and is_array($onlineModeData)){
				$this->packetLink_properties = $onlineModeData;
			}

			foreach($this->packetLink_properties as $property){
				if($property["name"] === "textures"){
					$skindata = json_decode(base64_decode($property["value"]), true);
					if(isset($skindata["textures"]["SKIN"]["url"])){
						$skin = $this->getSkinImage($skindata["textures"]["SKIN"]["url"]);
					}
				}
			}

			$pk = new LoginPacket();
			$pk->username = $this->packetLink_username;
			$pk->protocol = ProtocolInfo::CURRENT_PROTOCOL;
			$pk->clientUUID = UUID::fromString($this->packetLink_formatedUUID);
			$pk->clientId = crc32($this->bigbrother_clientId);
			$pk->serverAddress = "127.0.0.1:25565";
			if($skin === null or $skin === false){
				if($this->plugin->getConfig()->get("skin-slim")){
					$pk->skinId = "Standard_Custom";
				}else{
					$pk->skinId = "Standard_CustomSlim";
				}
				$pk->skin = file_get_contents($this->plugin->getDataFolder().$this->plugin->getConfig()->get("skin-yml"));
			}else{
				if(!isset($skindata["textures"]["SKIN"]["metadata"]["model"])){
					$pk->skinId = "Standard_Custom";
				}else{
					$pk->skinId = "Standard_CustomSlim";
				}
				$pk->skin = $skin;
			}

			$this->handleDataPacket($pk);

			$pk = new RequestChunkRadiusPacket();//for PocketMine-MP
			$pk->radius = 8;
			$this->handleDataPacket($pk);

			$pk = new KeepAlivePacket();
			$pk->id = mt_rand();
			$this->putRawPacket($pk);

			$pk = new PlayerListPacket();
			$pk->actionID = PlayerListPacket::TYPE_ADD;
			$pk->players[] = [
				UUID::fromString($this->packetLink_formatedUUID)->toBinary(),
				$this->packetLink_username,
				$this->packetLink_properties,
				$this->getGamemode(),
				0,
				true,
				PacketLink::toJSON($this->packetLink_username)
			];
			$this->putRawPacket($pk);

			$playerlist = [];
			$playerlist[UUID::fromString($this->packetLink_formatedUUID)->toString()] = true;
			$this->setSetting(["PlayerList" => $playerlist]);

			$pk = new TitlePacket(); //Set SubTitle for this
			$pk->actionID = TitlePacket::TYPE_SET_TITLE;
			$pk->data = TextFormat::toJSON("");
			$this->putRawPacket($pk);

			$pk = new TitlePacket();
			$pk->actionID = TitlePacket::TYPE_SET_SUB_TITLE;
			$pk->data = TextFormat::toJSON(TextFormat::YELLOW . TextFormat::BOLD . "This is a beta version of PacketLink.");
			$this->putRawPacket($pk);
		}
	}

	public function packetLink_processAuthentication(PacketLink $plugin, EncryptionResponsePacket $packet){
		$this->packetLink_secret = $plugin->decryptBinary($packet->sharedSecret);
		$token = $plugin->decryptBinary($packet->verifyToken);
		$this->interface->enableEncryption($this, $this->packetLink_secret);
		if($token !== $this->packetLink_checkToken){
			$this->close("", "Invalid check token");
		}else{
			$this->getAuthenticateOnline($this->packetLink_username, Binary::sha1("".$this->packetLink_secret.$plugin->getASN1PublicKey()));
		}
	}

	public function packetLink_handleAuthentication($plugin, $username, $onlineMode = false){
		if($this->packetLink_status === 0){
			$this->packetLink_username = $username;
			if($onlineMode === true){
				$pk = new EncryptionRequestPacket();
				$pk->serverID = "";
				$pk->publicKey = $plugin->getASN1PublicKey();
				$pk->verifyToken = $this->packetLink_checkToken = str_repeat("\x00", 4);//for PocketMine-MP  Random Bytes :(
				$this->putRawPacket($pk);
			}else{
				$info = $this->getProfile($username);
				if(is_array($info)){
					$this->packetLink_authenticate($info["id"], $info["properties"]);
				}
			}
		}
	}

	public function getProfile($username){
		$profile = json_decode(Utils::getURL("https://api.mojang.com/users/profiles/minecraft/".$username), true);
		if(!is_array($profile)){
			return false;
		}

		$uuid = $profile["id"];
		$info = json_decode(Utils::getURL("https://sessionserver.mojang.com/session/minecraft/profile/".$uuid."", 3), true);
		if(!isset($info["id"])){
			return false;
		}
		return $info;
	}

	public function getAuthenticateOnline($username, $hash){
		$result = json_decode(Utils::getURL("https://sessionserver.mojang.com/session/minecraft/hasJoined?username=".$username."&serverId=".$hash, 5), true);
		if(is_array($result) and isset($result["id"])){
			$this->packetLink_authenticate($result["id"], $result["properties"]);
		}else{
			$this->close("", "User not premium");
		}
	}

	public function getSkinImage($url){
		if(extension_loaded("gd")){
			$image = imagecreatefrompng($url);

			if($image !== false){
				$width = imagesx($image);
				$height = imagesy($image);
				$colors = [];
				for($y = 0; $y < $height; $y++){
					$y_array = [];
					for($x = 0; $x < $width; $x++){
						$rgb = imagecolorat($image, $x, $y);
						$r = ($rgb >> 16) & 0xFF;
						$g = ($rgb >> 8) & 0xFF;
						$b = $rgb & 0xFF;
						$alpha = imagecolorsforindex($image, $rgb)["alpha"];
						$x_array = [$r, $g, $b, $alpha];
						$y_array[] = $x_array;
					}
					$colors[] = $y_array;
				}
				$skin = null;
				foreach($colors as $width){
					foreach($width as $height){
						$alpha = 0;
						if($height[0] === 255 and $height[1] === 255 and $height[2] === 255){
							$height[0] = 0;
							$height[1] = 0;
							$height[2] = 0;
							if($height[3] === 127){
								$alpha = 255;
							}else{
								$alpha = 0;
							}
						}else{
							if($height[3] === 127){
								$alpha = 0;
							}else{
								$alpha = 255;
							}
						}
						$skin = $skin.chr($height[0]).chr($height[1]).chr($height[2]).chr($alpha);
					}
				}
				imagedestroy($image);
				return $skin;
			}
		}
		return false;
	}

	public function putRawPacket(Packet $packet){
		$this->interface->putRawPacket($this, $packet);
	}
}
