<?php

declare(strict_types = 1);

namespace rconserver;

use pocketmine\network\NetworkInterface;
use pocketmine\thread\log\ThreadSafeLogger;
use pocketmine\snooze\SleeperHandler;
use pocketmine\utils\TextFormat;
use Socket;
use function socket_bind;
use function socket_close;
use function socket_create;
use function socket_create_pair;
use function socket_last_error;
use function socket_listen;
use function socket_set_block;
use function socket_set_option;
use function socket_strerror;
use function socket_write;
use function trim;
use const AF_INET;
use const AF_UNIX;
use const SO_REUSEADDR;
use const SOCK_STREAM;
use const SOCKET_ENOPROTOOPT;
use const SOCKET_EPROTONOSUPPORT;
use const SOL_SOCKET;
use const SOL_TCP;

final class Rcon implements NetworkInterface{

    private Socket $socket;

    private RconThread $thread;

    private Socket $ipcMainSocket;
    private Socket $ipcThreadSocket;

    public function __construct(RconConfig $config, callable $onCommandCallback, ThreadSafeLogger $logger, SleeperHandler $sleeper){
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if($socket === false){
            throw new RconException("Failed to create socket: " . socket_strerror(socket_last_error()));
        }
        $this->socket = $socket;

        if(!socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1)){
            throw new RconException("Unable to set option on socket: " . trim(socket_strerror(socket_last_error())));
        }
        if(!@socket_bind($this->socket, $config->ip, $config->port) || !@socket_listen($this->socket, 5)){
            throw new RconException("Failed to open main socket: " . trim(socket_strerror(socket_last_error())));
        }

        socket_set_block($this->socket);
        $ret = @socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $ipc);
        if(!$ret){
            $err = socket_last_error();
            if(($err !== SOCKET_EPROTONOSUPPORT && $err !== SOCKET_ENOPROTOOPT) || !@socket_create_pair(AF_INET, SOCK_STREAM, 0, $ipc)){
                throw new RconException("Failed to open IPC socket: " . trim(socket_strerror(socket_last_error())));
            }
        }

        [$this->ipcMainSocket, $this->ipcThreadSocket] = $ipc;
        $sleeperEntry = $sleeper->addNotifier(function() use ($onCommandCallback) : void{
            $response = $onCommandCallback($this->thread->cmd);

            $this->thread->response = TextFormat::clean($response);
            $this->thread->synchronized(function(RconThread $thread) : void{
                $thread->notify();
            }, $this->thread);
        });

        $this->thread = new RconThread($this->socket, $config->password, $config->maxConnections, $logger, $this->ipcThreadSocket, $sleeperEntry);
    }

    public function start() : void{
        $this->thread->start();
    }

    public function shutdown() : void{
        $this->thread->close();
        socket_write($this->ipcMainSocket, "\x00");
        $this->thread->quit();

        @socket_close($this->socket);
        @socket_close($this->ipcMainSocket);
        @socket_close($this->ipcThreadSocket);
    }

    public function setName(string $name) : void{
        //NOOP
    }

    public function tick() : void{
        //NOOP
    }
}