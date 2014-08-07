<?php

require_once ('twitteroauth/twitteroauth.php');

class Twitter_auth{
    
    private $ci;
    private $twitter_consumer_key;
    private $twitter_consumer_secret_key;
    private $callback_url;


    public function __construct() {
        $this->ci = & get_instance();
        $this->twitter_consumer_key = $this->ci->config->item("twitter_consumer_key");
        $this->twitter_consumer_secret_key = $this->ci->config->item("twitter_consumer_secret_key");
        $this->callback_url = $this->ci->config->item("callback_url");
    }
    
    public function twitter_login(){
        // The TwitterOAuth instance 
        $twitteroauth = new TwitterOAuth($this->twitter_consumer_key, $this->twitter_consumer_secret_key);
        //var_dump($twitteroauth);
        
       // Requesting authentication tokens, the parameter is the URL we will be redirected to  
       $request_token = $twitteroauth->getRequestToken($this->callback_url);
       //var_dump($request_token);
       //var_dump($twitteroauth);
       
       // Saving them into the session
        $this->ci->session->set_userdata("twitter_oauth_token",$request_token['oauth_token']);
        $this->ci->session->set_userdata("twitter_oauth_token_secret",$request_token['oauth_token_secret']);
        //var_dump($this->ci->session->all_userdata());
        
        // If everything goes well..  
        if ($twitteroauth->http_code == 200) {
            // Let's generate the URL and redirect  
            $url = $twitteroauth->getAuthorizeURL($this->ci->session->userdata("twitter_oauth_token"));
            //var_dump($url);
            Template::redirect($url);
        } else {
            // It's a bad idea to kill the script, but we've got to know when there's an error.  
            die('Something wrong happened.');
        }
    }
    
    public function twitter_profile($oauth_verifier){
        // We've got everything we need
        // TwitterOAuth instance, with two new parameters we got in twitter_login.php
        
        if(!empty($oauth_verifier)){
            $twitteroauth = new TwitterOAuth($this->twitter_consumer_key, $this->twitter_consumer_secret_key, $this->ci->session->userdata("twitter_oauth_token"), $this->ci->session->userdata("twitter_oauth_token_secret"));
            // Let's request the access token  
            $access_token = $twitteroauth->getAccessToken($oauth_verifier);
            // Save it in a session var 
            $this->ci->session->set_userdata("access_token",$access_token);
            // Let's get the user's info 
            $user_info = $twitteroauth->get('account/verify_credentials');
            // Print user's info  
            return $user_info;
        } else {
            return FALSE;
        }
    }
}