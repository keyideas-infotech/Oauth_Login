<?php

if (!defined('BASEPATH'))
    exit('No direct script access allowed');

class Home extends Front_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model('users/user_model');
        $this->load->model('share_your_exp/share_your_exp_model', "share");
        $this->load->model('article/article_model', "article");
        $this->load->model('banner/banner_model', "banner");
        $this->load->library("social_media_auth/fb/facebook");
        $this->load->helper("thumbnail");
    }

    /*
     * callback after successfull login with facebook 
     * & Home page
     */

    public function index() {


        $facebook = new Facebook(array(
            'appId' => '528370173920459',
            'secret' => '6c406c25332210cf6e705b599b48c074',
        ));

        if ($this->input->get("code")) {

            $FB_user = $facebook->getUser();


            if ($FB_user) {
                try {

                    //get user image from facebook.
                    $user_profile = $facebook->api('/me');
                    $url = "https://graph.facebook.com/{$FB_user}/picture?type=large";
                    $img = file_get_contents($url);

                    if ($img) {
                        $file_name = FCPATH . "assets/uploads/users_images" . "/" . $FB_user . '.jpg';
                        if (file_put_contents($file_name, $img)) {
                            $user_profile['fb_profile_image'] = $FB_user . '.jpg';

                            //Now create thumbnail..
                            $thumb_path = FCPATH . "assets" . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "users_images" . DIRECTORY_SEPARATOR . "thumb";
                            create_thumbnail($file_name, $thumb_path);
                        }
                    }

                    if ($this->user_model->fb_set_profile($user_profile)) {
                        if (!empty($this->previous_page)) {
                            $temp = explode("/", $this->previous_page);
                            $temp = end($temp);
                            if ($this->session->userdata("search_request") && $temp == "search") {
                                $t = $this->session->userdata("search_term");
                                $this->session->unset_userdata("search_request");
                                $this->session->unset_userdata("search_term");
                                Template::redirect("search?search_term=" . $t);
                            }
                            Template::redirect($this->previous_page);
                        } else {
                            Template::redirect('/');
                        }
                    }
                } catch (FacebookApiException $e) {
                    $FB_user = null;
                }
            }
        }

        $banner = $this->banner->get_active_banner();

        Template::set("latest_article", $this->article->get_latest_article());
        Template::set("latest_reviews", $this->share->get_latest_reviewed_club());
        Template::set("banners", $banner);
        Template::set('is_index_page', TRUE);
        Template::render();
    }

    public function ajax_get_club_hint() {
        if ($this->input->is_ajax_request()) {
            $data = array();
            $search = $this->input->post('search_term');
            if (!empty($search)) {
                $this->load->model('club_management/club_management_model');
                $clubs = $this->club_management_model->find_clubs_by_name_city_country($search);
                if ($clubs !== FALSE) {
                    foreach ($clubs as $club) {
                        $data[] = array(
                            "label" => $club->name . ", " . $club->city . "(" . $club->country . ")",
                            "value" => $club->name,
                            "id" => $club->id
                        );
                    }
                    $this->output->set_content_type('application/json')
                            ->set_output(json_encode($data));
                }
            }
        } else {
            Template::redirect('/');
        }
    }

    public function ajax_get_club_name_hint() {
        if ($this->input->is_ajax_request()) {
            $data = array();
            $club_name = $this->input->post('club_name');
            $name = "name_" . $this->config->item("language");
            if (!empty($club_name)) {
                $this->load->model('club_management/club_management_model');
                $clubs = $this->club_management_model->find_club_for_auto_suggest_by_name($club_name);
                if ($clubs !== FALSE) {
                    foreach ($clubs as $club) {
                        $data[] = array(
                            "label" => $club->$name,
                            "value" => $club->$name,
                            "id" => $club->id
                        );
                    }
                }
            }
            $this->output->set_content_type('application/json')->set_output(json_encode($data));
        } else {
            Template::redirect('/');
        }
    }

    public function ajax_get_city_country_hint() {
        if ($this->input->is_ajax_request()) {
            $data = array();
            $search_term = $this->input->post('search_term');
            $club_name = $this->input->post('club_name');

            if (!empty($search_term)) {
                $this->load->model('club_management/club_management_model');
                $cities = $this->club_management_model->get_city_country_by_kw($search_term, $club_name, $this->config->item('language'));
                if ($cities !== FALSE) {
                    foreach ($cities as $c) {
                        $data[] = array(
                            "label" => $c['city'] . ", " . $c['country'],
                            "value" => $c['city'] . ", " . $c['country'],
                        );
                    }
                }
            }
            $this->output->set_content_type('application/json')->set_output(json_encode($data));
        } else {
            Template::redirect('/');
        }
    }

    public function ajax_get_club_city() {
        if ($this->input->is_ajax_request()) {
            $data = array();
            $club_name = $this->input->post('club_name');
            if (!empty($club_name)) {
                $this->load->model('club_management/club_management_model');
                $cities = $this->club_management_model->get_city_for_club($club_name);
                if ($cities !== FALSE) {
                    foreach ($cities as $c) {
                        $data[] = array(
                            "label" => $c['city'] . ", " . $c['country'],
                            "value" => $c['city'] . ", " . $c['country'],
                        );
                    }
                }
            }
            $this->output->set_content_type('application/json')->set_output(json_encode($data));
        } else {
            Template::redirect('/');
        }
    }

    public function change_language() {
        if ($this->input->is_ajax_request()) {
            $language = $this->input->post("language");
            if (!empty($language)) {
                $this->session->set_userdata("language", $language);
                echo "success";
            } else {
                echo "error";
            }
            die();
        } else {
            Template::redirect('/');
        }
    }

    /*
     * Redirects to facebook login page
     */

    public function fb_login() {
        $facebook = new Facebook(array(
            'appId' => '528370173920459',
            'secret' => '6c406c25332210cf6e705b599b48c074',
        ));
        $FB_user = $facebook->getUser();

        $FBloginUrl = $facebook->getLoginUrl(array(
            'scope' => 'email,user_birthday,user_location',
            'redirect_uri' => base_url(),
        ));
        Template::redirect($FBloginUrl);
    }

}
