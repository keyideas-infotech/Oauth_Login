<?php

require_once ("src/Google_Client.php");
require_once ("src/contrib/Google_PlusService.php");

class Google_authentication{
    
    private $ci;
    private $client;
    private $plus;


    public function __construct() {
        $this->ci = & get_instance();
        $this->client = new Google_Client();
        $this->plus   = new Google_PlusService($this->client);
    }
    
    public function google_login(){
        //get instance;
        $authUrl = $this->client->createAuthUrl();
        Template::redirect($authUrl);
    }
    
    public function google_profile($code){
        $this->client->authenticate($code);
        $access_token = $this->client->getAccessToken();
        $this->ci->session->set_userdata("access_token", $access_token);

        if ($this->client->getAccessToken()) {
            $me = $this->plus->people->get('me');
            return $me;
        }
        return FALSE;
    }
}