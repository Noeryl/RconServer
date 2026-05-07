# RconServer
Fixed the RconServer plugin to resolve a bug that caused RCON to crash, displaying like this error in the console:

```
Fatal error: Uncaught ErrorException: socket_getpeername(): unable to retrieve peer name [107]: Transport endpoint is not connected in phar:///home/xacki/mpe/plugins/RconServer.phar/src/RconThread.php:98
Stack trace:
#0 [internal function]: pocketmine\errorhandler\ErrorToExceptionHandler::handle(2, 'socket_getpeern...', 'phar:///home/xa...', 98)
#1 phar:///home/xacki/mpe/plugins/RconServer.phar/src/RconThread.php(98): socket_getpeername(Object(Socket), NULL, NULL)
#2 phar:///home/xacki/mpe/plugins/RconServer.phar/src/RconThread.php(179): pmmp\RconServer\RconThread->readPacket(Object(Socket), 6, 2, 'help')
#3 phar:///home/xacki/mpe/PocketMine-MP.phar/src/thread/CommonThreadPartsTrait.php(93): pmmp\RconServer\RconThread->onRun()
#4 [internal function]: pocketmine\thread\Thread->run()
#5 {main}
thrown in phar:///home/xacki/mpe/plugins/RconServer.phar/src/RconThread.php on line 98
```
