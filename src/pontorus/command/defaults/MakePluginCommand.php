<?php
/*____             _
|  _ \ ___  _ __ | |_ ___  _ __ _   _ ___
| |_) / _ \| '_ \| __/ _ \| '__| | | / __|
|  __/ (_) | | | | || (_) | |  | |_| \__ \
|_|   \___/|_| |_|\__\___/|_|   \__,_|___/
 */

namespace pontorus\command\defaults;

use pontorus\command\CommandSender;
use pontorus\plugin\FolderPluginLoader;
use pontorus\plugin\Plugin;
use pontorus\Server;
use pontorus\utils\TextFormat;

class MakePluginCommand extends VanillaCommand{

	public function __construct($name){
		parent::__construct(
			$name,
			"Creates a Phar plugin from a unarchived",
			"/makeplugin <pluginName> (nogz)"
		);
	}

	public function execute(CommandSender $sender, $commandLabel, array $args){

		if(count($args) === 0){
			$sender->sendMessage(TextFormat::RED . "Usage: " . $this->usageMessage);
			return true;
		}

		$pluginName = trim(implode(" ", $args));
		if($pluginName === "" or !(($plugin = Server::getInstance()->getPluginManager()->getPlugin($pluginName)) instanceof Plugin)){
			$sender->sendMessage(TextFormat::RED . "Invalid plugin name, check the name case.");
			return true;
		}
		$description = $plugin->getDescription();

		if(!($plugin->getPluginLoader() instanceof FolderPluginLoader)){
			$sender->sendMessage(TextFormat::RED . "Plugin " . $description->getName() . " is not in folder structure.");
			return true;
		}

		$pharPath = Server::getInstance()->getPluginPath() . DIRECTORY_SEPARATOR . "pontorus" . DIRECTORY_SEPARATOR . $description->getName() . "_v" . $description->getVersion() . ".phar";
		if(file_exists($pharPath)){
			$sender->sendMessage("Phar plugin already exists, overwriting...");
			@unlink($pharPath);
		}
		$phar = new \Phar($pharPath);
		$phar->setMetadata([
			"name" => $description->getName(),
			"version" => $description->getVersion(),
			"main" => $description->getMain(),
			"api" => $description->getCompatibleApis(),
			"depend" => $description->getDepend(),
			"description" => $description->getDescription(),
			"authors" => $description->getAuthors(),
			"website" => $description->getWebsite(),
			"creator" => "pontorus MakePluginCommand",
			"creationDate" => time()
		]);
		$phar->setStub('<?php echo "pontorus plugin ' . $description->getName() . ' v' . $description->getVersion() . '\nThis file has been generated using pontorus by iTX Technologies at ' . date("r") . '\n----------------\n";if(extension_loaded("phar")){$phar = new \Phar(__FILE__);foreach($phar->getMetadata() as $key => $value){echo ucfirst($key).": ".(is_array($value) ? implode(", ", $value):$value)."\n";}} __HALT_COMPILER();');
		$phar->setSignatureAlgorithm(\Phar::SHA1);
		$reflection = new \ReflectionClass("pontorus\\plugin\\PluginBase");
		$file = $reflection->getProperty("file");
		$file->setAccessible(true);
		$filePath = rtrim(str_replace("\\", "/", $file->getValue($plugin)), "/") . "/";
		$phar->startBuffering();
		foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($filePath)) as $file){
			$path = ltrim(str_replace(["\\", $filePath], ["/", ""], $file), "/");
			if($path{0} === "." or strpos($path, "/.") !== false){
				continue;
			}
			$phar->addFile($file, $path);
			$sender->sendMessage("[pontorus] Adding $path");
		}

		foreach($phar as $file => $finfo){
			/** @var \PharFileInfo $finfo */
			if($finfo->getSize() > (1024 * 512)){
				$finfo->compress(\Phar::GZ);
			}
		}
		if(!isset($args[1]) or (isset($args[1]) and $args[1] != "nogz")){
			$phar->compressFiles(\Phar::GZ);
		}
		$phar->stopBuffering();
		$sender->sendMessage("Phar plugin " . $description->getName() . " v" . $description->getVersion() . " has been created on " . $pharPath);
		return true;
	}
}
