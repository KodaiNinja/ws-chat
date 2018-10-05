<?php

namespace App;

use App\Response\ErrorResponse;
use App\Response\LoginResponse;
use App\Response\MessagesResponse;
use HTMLPurifier;
use HTMLPurifier_Config;
use Swoole\Http\Request;
use swoole_channel;
use swoole_table;
use swoole_websocket_server;

/**
 * Class WebsocketServer
 * @package App
 */
class WebsocketServer
{
    /**
     * @var swoole_websocket_server
     */
    private $ws;

    /**
     * @var swoole_channel
     * Storage for user IDs
     */
    private $global_channel;

    /**
     * Messages table. Columns:
     *  - username
     *  - message
     * @var swoole_table
     */
    private $messages_table;

    /**
     * Users table. Columns:
     *  - id
     *  - username
     * @var swoole_table
     */
    private $users_table;

    /**
     * WebsocketServer constructor.
     */
    public function __construct()
    {
        $this->purifier = new HTMLPurifier(HTMLPurifier_Config::createDefault());

        $this->ws = new swoole_websocket_server('0.0.0.0', 9502);

        $this->messages_table = new swoole_table(5000);
        $this->messages_table->column('username', swoole_table::TYPE_STRING, 100);
        $this->messages_table->column('message', swoole_table::TYPE_STRING, 250);
        $this->messages_table->create();

        $this->users_table = new swoole_table(5000);
        $this->users_table->column('id', swoole_table::TYPE_INT, 5);
        $this->users_table->column('username', swoole_table::TYPE_STRING, 100);
        $this->users_table->create();
        $this->global_channel = new swoole_channel(1000);

        $this->ws->on('open', function ($ws, $request) { $this->onConnection($request); });
        $this->ws->on('message', function ($ws, $frame) { $this->onMessage($frame); });
        $this->ws->on('close', function ($ws, $id) { $this->onClose($id); });
        $this->ws->start();
    }

    /**
     * Client connected
     * @param Request $request
     */
    private function onConnection(Request $request)
    {
        $Ids = $this->global_channel->pop() ?? [];
        $Ids[] = $request->fd;
        $this->global_channel->push($Ids);

        $messagesResponse = new MessagesResponse();
        $count = count($this->messages_table);
        for ($i = 0; $i < $count; $i++) {
            $username = $this->messages_table[$i]->value['username'];
            $message = $this->messages_table[$i]->value['message'];
            $messagesResponse->addMessage($username, $message);
        }
        $this->ws->push($request->fd, $messagesResponse->getJson());

        echo "client-{$request->fd} is connected\n";
    }

    /**
     * @param $frame
     */
    private function onMessage($frame)
    {
        $data = json_decode($frame->data);
        switch ($data->type) {
            case 'login':
                $this->login($frame->fd, $data->username);
                break;
            case 'message':
                $this->addMessage($frame->fd, $data);
        }
    }

    /**
     * @param $id
     */
    private function onClose(int $id)
    {
        $Ids = $this->global_channel->pop() ?? [];
        if (($key = array_search($id, $Ids)) !== FALSE) {
            unset($Ids[$key]);
        }
        $this->global_channel->push($Ids);

        echo "client-{$id} is closed\n";
    }

    /**
     * @param int $id
     * @return bool|string
     */
    private function getUsername(int $id)
    {
        $username = $this->users_table->get($id, 'username');
        if ($username != false) {
            return $username;
        }
        return false;
    }

    /**
     * @param int $id
     */
    private function return_unauthorized(int $id)
    {
        $this->ws->push($id, (new ErrorResponse('Unauthorized!'))->getJson());
    }

    /**
     * @param int $id
     * @param $data
     */
    function addMessage(int $id, $data)
    {
        $username = $this->getUsername($id);
        if ($username == false) {
            $this->return_unauthorized($id);
            return;
        }

        $count = count($this->messages_table);

        $message = $this->purifier->purify($data->message);

        $row = ['username' => $username, 'message' => $message];
        $this->messages_table->set($count, $row);

        $Ids = $this->global_channel->peek() ?? [];
        $response = (new MessagesResponse())->addMessage($username, $message)->getJson();
        foreach ($Ids as $id) {
            $this->ws->push($id, $response);
        }
    }

    /**
     * @param int $id
     * @param string $username
     */
    private function login(int $id, string $username = '')
    {
        if (empty($username)) {
            $this->ws->push($id, (new LoginResponse(false, 'username cannot be empty'))->getJson());
        }
        $row = ['id' => $id, 'username' => $username];

        foreach ($this->users_table as $user) {
            if ($user['username'] == $username) {
                $currentUserWithSomeNickId = $user['id'];
                if ($this->isUserOnline($currentUserWithSomeNickId)) {
                    $this->ws->push($id, (new LoginResponse(false, 'Choose another name!'))->getJson());
                    return;
                }
            }
        }

        $this->users_table->set($id, $row);

        $this->ws->push($id, (new LoginResponse(true))->getJson());
    }

    /**
     * Check if user with specified ID is currently online
     * @param int $id
     * @return bool
     */
    private function isUserOnline(int $id)
    {
        $idsList = $this->global_channel->peek();
        return ( ($key = array_search($id, $idsList)) !== false );
    }
}