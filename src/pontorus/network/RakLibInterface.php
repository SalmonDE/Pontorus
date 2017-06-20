<?php

/*____             _
|  _ \ ___  _ __ | |_ ___  _ __ _   _ ___
| |_) / _ \| '_ \| __/ _ \| '__| | | / __|
|  __/ (_) | | | | || (_) | |  | |_| \__ \
|_|   \___/|_| |_|\__\___/|_|   \__,_|___/
 */


namespace pontorus\network;

use pontorus\network\protocol\mcpe\DataPacket;
use pontorus\network\protocol\mcpe\Info as ProtocolInfo;
use pontorus\Player;
use pontorus\Server;
use raklib\protocol\EncapsulatedPacket;
use raklib\RakLib;
use raklib\server\RakLibServer;
use raklib\server\ServerHandler;
use raklib\server\ServerInstance;

class RakLibInterface implements ServerInstance, AdvancedSourceInterface{

	/** @var Server */
	private $server;

	/** @var Network */
	private $network;

	/** @var RakLibServer */
	private $rakLib;

	/** @var Player[] */
	private $players = [];

	/** @var string[] */
	private $identifiers;

	/** @var int[] */
	private $identifiersACK = [];

	/** @var ServerHandler */
	private $interface;

	public function __construct(Server $server){

		$this->server = $server;
		$this->identifiers = [];

		$this->rakLib = new RakLibServer($this->server->getLogger(), $this->server->getLoader(), $this->server->getPort(), $this->server->getIp() === "" ? "0.0.0.0" : $this->server->getIp());
		$this->interface = new ServerHandler($this->rakLib, $this);
	}

	public function setNetwork(Network $network){
		$this->network = $network;
	}

	public function process(){
		$work = false;
		if($this->interface->handlePacket()){
			$work = true;
			$lasttime = time();
			while($this->interface->handlePacket()){
				$diff = time() - $lasttime;
				if($diff >= 1) break;
			}
		}

		if($this->rakLib->isTerminated()){
			$this->network->unregisterInterface($this);

			throw new \Exception("RakLib Thread crashed");
		}

		return $work;
	}

	public function closeSession($identifier, $reason){
		if(isset($this->players[$identifier])){
			$player = $this->players[$identifier];
			unset($this->identifiers[spl_object_hash($player)]);
			unset($this->players[$identifier]);
			unset($this->identifiersACK[$identifier]);
			$player->close($reason);
		}
	}

	public function close(Player $player, $reason = "unknown reason"){
		if(isset($this->identifiers[$h = spl_object_hash($player)])){
			unset($this->players[$this->identifiers[$h]]);
			unset($this->identifiersACK[$this->identifiers[$h]]);
			$this->interface->closeSession($this->identifiers[$h], $reason);
			unset($this->identifiers[$h]);
		}
	}

	public function shutdown(){
		$this->interface->shutdown();
	}

	public function emergencyShutdown(){
		$this->interface->emergencyShutdown();
	}

	public function openSession($identifier, $address, $port, $clientID){
		$player = new Player($this, $clientID, $address, $port);
		$this->players[$identifier] = $player;
		$this->identifiersACK[$identifier] = 0;
		$this->identifiers[spl_object_hash($player)] = $identifier;
		$this->server->addPlayer($identifier, $player);
	}

	public function handleEncapsulated($identifier, EncapsulatedPacket $packet, $flags){
		if(isset($this->players[$identifier])){
			try{
				if($packet->buffer !== ""){
					$pk = $this->getPacket($packet->buffer);
					if($pk !== null){
						$pk->decode();
						$this->players[$identifier]->handleDataPacket($pk);
					}
				}
			}catch(\Throwable $e){
				if(\pontorus\DEBUG > 1 and isset($pk)){
					$logger = $this->server->getLogger();
					$logger->debug("Packet " . get_class($pk) . " 0x" . bin2hex($packet->buffer));
					$logger->logException($e);
				}

				if(isset($this->players[$identifier])){
					$this->interface->blockAddress($this->players[$identifier]->getIp(), 5);
				}
			}
		}
	}

	public function blockAddress($address, $timeout = 300){
		$this->interface->blockAddress($address, $timeout);
	}

	public function handleRaw($address, $port, $payload){
		$this->server->handlePacket($address, $port, $payload);
	}

	public function sendRawPacket($address, $port, $payload){
		$this->interface->sendRaw($address, $port, $payload);
	}

	public function notifyACK($identifier, $identifierACK){

	}

	public function setName($name){
		$info = $this->server->getQueryInformation();
		$pc = $info->getMaxPlayerCount();
		$poc = $info->getPlayerCount();

		$this->interface->sendOption("name",
			"MCPE;" . addcslashes($name, ";") . ";" .
			ProtocolInfo::CURRENT_PROTOCOL . ";" .
			\pontorus\MINECRAFT_VERSION_NETWORK . ";" .
			$poc . ";" .
			$pc
		);
	}

	public function setPortCheck($name){
		$this->interface->sendOption("portChecking", (bool) $name);
	}

	public function handleOption($name, $value){
		if($name === "bandwidth"){
			$v = unserialize($value);
			$this->network->addStatistics($v["up"], $v["down"]);
		}
	}

	public function putPacket(Player $player, DataPacket $packet, $needACK = false, $immediate = false){
		if(isset($this->identifiers[$h = spl_object_hash($player)])){
			$identifier = $this->identifiers[$h];
			$pk = null;
			if(!$packet->isEncoded){
				$packet->encode();
			}elseif(!$needACK){
				if(!isset($packet->__encapsulatedPacket)){
					$packet->__encapsulatedPacket = new CachedEncapsulatedPacket;
					$packet->__encapsulatedPacket->identifierACK = null;
					$packet->__encapsulatedPacket->buffer = chr(0xfe) . $packet->buffer;
					$packet->__encapsulatedPacket->reliability = 3;
					$packet->__encapsulatedPacket->orderChannel = 0;
				}
				$pk = $packet->__encapsulatedPacket;
			}

			if($pk === null){
				$pk = new EncapsulatedPacket();
				$pk->buffer = chr(0xfe) . $packet->buffer;
				$packet->reliability = 3;
				$packet->orderChannel = 0;

				if($needACK === true){
					$pk->identifierACK = $this->identifiersACK[$identifier]++;
				}
			}

			$this->interface->sendEncapsulated($identifier, $pk, ($needACK === true ? RakLib::FLAG_NEED_ACK : 0) | ($immediate === true ? RakLib::PRIORITY_IMMEDIATE : RakLib::PRIORITY_NORMAL));

			return $pk->identifierACK;
		}

		return null;
	}

	private function getPacket($buffer){
		$pid = ord($buffer{0});
		$start = 1;
		if($pid == 0xfe){
			$pid = ord($buffer{1});
			$start++;
		}
		if(($data = $this->network->getPacket($pid)) === null){
			return null;
		}
		$data->setBuffer($buffer, $start);

		return $data;
	}
}
