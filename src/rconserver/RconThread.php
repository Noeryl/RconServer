<?php

declare(strict_types = 1);

namespace rconserver;

use pocketmine\thread\Thread;
use pocketmine\thread\log\ThreadSafeLogger;
use pocketmine\snooze\SleeperHandlerEntry;
use pocketmine\utils\Binary;
use Socket;
use function count;
use function ltrim;
use function microtime;
use function socket_accept;
use function socket_close;
use function socket_getpeername;
use function socket_last_error;
use function socket_read;
use function socket_select;
use function socket_set_block;
use function socket_set_nonblock;
use function socket_set_option;
use function socket_shutdown;
use function socket_strerror;
use function socket_write;
use function str_replace;
use function strlen;
use function substr;
use function trim;
use const SO_KEEPALIVE;
use const SO_LINGER;
use const SOCKET_ECONNRESET;
use const SOL_SOCKET;

final class RconThread extends Thread{

    public string $cmd = "";
    public string $response = "";

    private bool $stop = false;

    public function __construct(
        private readonly Socket $socket,
        private readonly string $password,
        private readonly int $maxClients,
        private readonly ThreadSafeLogger $logger,
        private readonly Socket $ipcSocket,
        private readonly SleeperHandlerEntry $sleeperEntry
    ){}

    public function getThreadName() : string{
        return "RCON";
    }

    public function close() : void{
        $this->stop = true;
    }

    protected function onRun() : void{
        /** @var Socket[] $clients */
        $clients = [];
        /** @var bool[] $authenticated */
        $authenticated = [];
        /** @var float[] $timeouts */
        $timeouts = [];
        $nextClientId = 0;
        $notifier = $this->sleeperEntry->createNotifier();

        while(!$this->stop){
            $r = $clients;
            $r["main"] = $this->socket;
            $r["ipc"] = $this->ipcSocket;
            $w = null;
            $e = null;
            $disconnect = [];

            if(socket_select($r, $w, $e, 5) > 0){
                foreach($r as $id => $sock){
                    if($sock === $this->socket){
                        if(($client = socket_accept($this->socket)) !== false){
                            if(count($clients) >= $this->maxClients){
                                @socket_close($client);
                            } else {
                                socket_set_nonblock($client);
                                socket_set_option($client, SOL_SOCKET, SO_KEEPALIVE, 1);

                                $id = $nextClientId++;
                                $clients[$id] = $client;
                                $authenticated[$id] = false;
                                $timeouts[$id] = microtime(true) + 5;
                            }
                        }
                    } elseif($sock === $this->ipcSocket){
                        socket_read($sock, 65535);
                    } else {
                        $p = $this->readPacket($sock, $requestID, $packetType, $payload);
                        if($p === false){
                            $disconnect[$id] = $sock;
                            continue;
                        }

                        switch($packetType){
                            case 3:
                                if($authenticated[$id]){
                                    $disconnect[$id] = $sock;
                                    break;
                                }

                                //FIX
                                $ip = "unknown";
                                @socket_getpeername($sock, $ip);

                                if($payload === $this->password){
                                    $this->writePacket($sock, $requestID, 2, "");
                                    $authenticated[$id] = true;
                                } else {
                                    $disconnect[$id] = $sock;
                                    $this->writePacket($sock, -1, 2, "");
                                }
                                break;
                            case 2:
                                if(!$authenticated[$id]){
                                    $disconnect[$id] = $sock;
                                    break;
                                }

                                if($payload !== ""){
                                    $this->cmd = ltrim($payload);
                                    $this->synchronized(function() use ($notifier) : void{
                                        $notifier->wakeupSleeper();
                                        $this->wait();
                                    });
                                    $this->writePacket($sock, $requestID, 0, str_replace("\n", "\r\n", trim($this->response)));
                                    $this->response = "";
                                    $this->cmd = "";
                                }
                                break;
                        }
                    }
                }
            }

            foreach($authenticated as $id => $status){
                if(!isset($disconnect[$id]) && !$status && $timeouts[$id] < microtime(true)){
                    $disconnect[$id] = $clients[$id];
                }
            }

            foreach($disconnect as $id => $client){
                $this->disconnectClient($client);
                unset($clients[$id], $authenticated[$id], $timeouts[$id]);
            }
        }

        foreach($clients as $client){
            $this->disconnectClient($client);
        }
    }

    private function writePacket(Socket $client, int $requestID, int $packetType, string $payload) : void{
        $pk = Binary::writeLInt($requestID)
            . Binary::writeLInt($packetType)
            . $payload
            . "\x00\x00";
        socket_write($client, Binary::writeLInt(strlen($pk)) . $pk);
    }

    private function readPacket(Socket $client, ?int &$requestID, ?int &$packetType, ?string &$payload) : bool{
        $d = @socket_read($client, 4);

        //FIX
        $ip = "unknown";
        $port = 0;
        @socket_getpeername($client, $ip, $port);

        if($d === false){
            $err = socket_last_error($client);
            if($err !== SOCKET_ECONNRESET){
                $this->logger->debug("Connection error with $ip $port: " . trim(socket_strerror($err)));
            }
            return false;
        }
        if(strlen($d) !== 4){
            if($d !== ""){
                $this->logger->debug("Truncated packet from $ip $port (want 4 bytes, have " . strlen($d) . "), disconnecting");
            }
            return false;
        }

        $size = Binary::readLInt($d);
        if($size < 0 || $size > 65535){
            $this->logger->debug("Packet with too-large length header $size from $ip $port, disconnecting");
            return false;
        }

        $buf = @socket_read($client, $size);
        if($buf === false){
            $err = socket_last_error($client);
            if($err !== SOCKET_ECONNRESET){
                $this->logger->debug("Connection error with $ip $port: " . trim(socket_strerror($err)));
            }
            return false;
        }
        if(strlen($buf) !== $size){
            $this->logger->debug("Truncated packet from $ip $port (want $size bytes, have " . strlen($buf) . "), disconnecting");
            return false;
        }

        $requestID = Binary::readLInt(substr($buf, 0, 4));
        $packetType = Binary::readLInt(substr($buf, 4, 4));
        $payload = substr($buf, 8, -2);
        return true;
    }

    private function disconnectClient(Socket $client) : void{
        //FIX
        $ip = "unknown";
        @socket_getpeername($client, $ip);

        @socket_set_option($client, SOL_SOCKET, SO_LINGER, ["l_onoff" => 1, "l_linger" => 1]);
        @socket_shutdown($client);
        @socket_set_block($client);
        @socket_read($client, 1);
        @socket_close($client);
    }
}