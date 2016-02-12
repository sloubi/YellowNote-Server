<?php

require_once('Db.php');
require_once('UserCredentialsWithDevice.php');
require_once('RefreshTokenWithDevice.php');

class Server
{
    protected $server;
    protected $storage;

    public function __construct($config)
    {
        $this->storage = new Db(
            array('dsn' => $config['dsn'], 'username' => $config['db_user'], 'password' => $config['db_pass']),
            array(
                'user_table'          => $config['db_prefix'] . 'users',
                'access_token_table'  => $config['db_prefix'] . 'access_tokens',
                'refresh_token_table' => $config['db_prefix'] . 'refresh_tokens',
                'client_table'        => $config['db_prefix'] . 'clients',
                'device_table'        => $config['db_prefix'] . 'devices',
                'device_note_table'   => $config['db_prefix'] . 'devices_notes',
                'note_table'          => $config['db_prefix'] . 'notes',
            )
        );

        // Pass a storage object to the OAuth2 server class
        $this->server = new OAuth2\Server($this->storage);

        // Password grant type
        $passwordGrantType = new UserCredentialsWithDevice($this->storage);

        // Refresh grant type
        $refreshGrantType = new RefreshTokenWithDevice($this->storage, array(
            'always_issue_new_refresh_token' => true,
            'refresh_token_lifetime' => 1209600, // 14 days
        ));

        // add the grant type to the OAuth server
        $this->server->addGrantType($passwordGrantType);
        $this->server->addGrantType($refreshGrantType);
    }

    // Magic to call server's methods
    public function __call($name, $arguments)
    {
        if ( ! is_callable(array($this->server, $name)))
            throw new BadMethodCallException("The method '$name' does not exist");
        return call_user_func_array(array($this->server, $name), $arguments);
    }

    public function getRequest()
    {
        return OAuth2\Request::createFromGlobals();
    }

    public function getStorage()
    {
        return $this->storage;
    }
}
