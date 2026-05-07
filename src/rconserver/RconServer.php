<?php

declare(strict_types = 1);

namespace rconserver;

use pocketmine\plugin\PluginBase;
use pocketmine\plugin\PluginException;
use pocketmine\plugin\DisablePluginException;
use pocketmine\utils\Filesystem;
use pocketmine\errorhandler\ErrorToExceptionHandler;
use Symfony\Component\Filesystem\Path;
use ErrorException;
use RuntimeException;
use function base64_encode;
use function file_exists;
use function file_put_contents;
use function inet_pton;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function random_bytes;
use function yaml_emit;
use function yaml_parse;

final class RconServer extends PluginBase{

    protected function onEnable() : void{
        $server = $this->getServer();
        $logger = $this->getLogger();

        $configPath = Path::join($this->getDataFolder(), "rcon.yml");
        try{
            $config = $this->loadConfig($configPath);
        } catch(PluginException $e){
            $logger->alert("Failed to load config file " . $configPath . ": " . $e->getMessage());
            throw new DisablePluginException();
        }

        $logger->info("Starting RCON on " . $config->ip . ":" . $config->port);
        try{
            $server->getNetwork()->registerInterface(new Rcon(
                $config,
                function(string $commandLine) use ($server) : string{
                    $response = new RconCommandSender($server, $server->getLanguage());
                    $response->recalculatePermissions();
                    $server->dispatchCommand($response, $commandLine);
                    return $response->getMessage();
                },
                $server->getLogger(),
                $server->getTickSleeper()
            ));
        } catch(RconException $e){
            $logger->alert("Failed to start RCON: " . $e->getMessage());
            $logger->logException($e);
            $server->getPluginManager()->disablePlugin($this);
            return;
        }
    }

    private function loadConfig(string $configPath) : RconConfig{
        $server = $this->getServer();

        if(!file_exists($configPath)){
            $config = [
                "ip" => $server->getIp(),
                "port" => $server->getPort(),
                "password" => base64_encode(random_bytes(8)),
                "max-connections" => 100,
            ];
            file_put_contents($configPath, yaml_emit($config));
            $this->getLogger()->notice("RCON config file generated at " . $configPath . ". Please customize it.");
        } else {
            try{
                $rawConfig = Filesystem::fileGetContents($configPath);
            } catch(RuntimeException $e){
                throw new PluginException($e->getMessage(), 0, $e);
            }
            try{
                $config = ErrorToExceptionHandler::trapAndRemoveFalse(fn() => yaml_parse($rawConfig));
            } catch(ErrorException $e){
                throw new PluginException($e->getMessage());
            }
        }

        if(!is_array($config)){
            throw new PluginException("Failed to parse config file");
        }

        $ip = null;
        $port = null;
        $password = null;
        $maxConnections = null;
        foreach($config as $key => $value){
            match($key){
                "ip" => is_string($value) && inet_pton($value) !== false ? $ip = $value : throw new PluginException("Invalid IP address"),
                "port" => is_int($value) && $value > 0 && $value < 65535 ? $port = $value : throw new PluginException("Invalid port, must be a port in range 0-65535"),
                "password" => is_string($value) || is_int($value) || is_float($value) ? $password = (string) $value : throw new PluginException("Invalid password, must be a string"),
                "max-connections" => is_int($value) && $value > 0 ? $maxConnections = $value : throw new PluginException("Invalid max connections, must be a number greater than 0"),
                default => throw new PluginException("Unexpected config key \"$key\"")
            };
        }

        if($ip === null){
            throw new PluginException("Missing IP address");
        }
        if($port === null){
            throw new PluginException("Missing port");
        }
        if($password === null){
            throw new PluginException("Missing password");
        }
        if($maxConnections === null){
            throw new PluginException("Missing max connections");
        }

        return new RconConfig($ip, $port, $password, $maxConnections);
    }
}