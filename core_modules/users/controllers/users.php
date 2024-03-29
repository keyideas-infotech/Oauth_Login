<?php

if (!defined('BASEPATH'))
    exit('No direct script access allowed');

class Users extends Front_Controller {

    public function __construct() {
        parent::__construct();

        $this->load->helper('form');
        $this->load->helper('thumbnail');

        $this->load->library('form_validation');
        $this->form_validation->CI = & $this;

        if (!class_exists('User_model')) {
            $this->load->model('users/User_model', 'user_model');
        }

        $this->load->database();

        $this->load->library('users/auth');

        $this->lang->load('users');
    }

    /*
     * User Login with email & password
     */

    public function login() {
// if the user is not logged in continue to show the login page
        if ($this->auth->is_logged_in() === FALSE) {
            if ($this->input->post() && $this->input->is_ajax_request()) {
                $remember = $this->input->post('remember_me') == '1' ? TRUE : FALSE;

// Try to login
                if ($this->auth->login($this->input->post('login'), $this->input->post('password'), $remember) === TRUE) {

// Log the Activity
                    $this->activity_model->log_activity($this->auth->user_id(), lang('us_log_logged') . ': ' . $this->input->ip_address(), 'users');

                    /*
                      In many cases, we will have set a destination for a
                      particular user-role to redirect to. This is helpful for
                      cases where we are presenting different information to different
                      roles that might cause the base destination to be not available.
                     */
                    /*
                      if ($this->settings_lib->item('auth.do_login_redirect') && !empty($this->auth->login_destination)) {
                      Template::redirect($this->auth->login_destination);
                      } */
                    if ($this->input->post('is_share_page') == "true") {
                        Template::redirect('share_your_exp');
                    } else {
                        if (!empty($this->previous_page)) {
                            $temp = explode("/", $this->previous_page);
                            $temp = end($temp);
                            if ($this->session->userdata("search_request") && $temp == "search") {
                                $club_name = $this->session->userdata("search_club_name");
                                $club_loc = $this->session->userdata("search_club_location");
                                $this->session->unset_userdata("search_request");
                                $this->session->unset_userdata("search_club_name");
                                $this->session->unset_userdata("search_club_location");
                                Template::redirect("search?search_club_name={$club_name}&search_club_location={$club_loc}");
                            }
                            Template::redirect($this->previous_page);
                        } else {
                            Template::redirect('/');
                        }
                    }
                }//end if
                else {
                    echo 'Invalid Email/Password';
                }
            }//end if                                   
            else {
                Template::redirect('/');
            }
        } else {
            Template::redirect('/');
        }//end if        
    }

//end login()

    /*
     * Login via Twitter, Google+ & vk
     */
    public function social_media_login() {
        if ($this->auth->is_logged_in() === FALSE) {
            if ($this->input->get("tw") == true) {
                $this->user_model->tw_set_profile();
            }
            if ($this->input->get("gp") == true) {
                $this->user_model->gp_set_profile();
            }
            if ($this->input->get('vk') == TRUE) {
                $this->user_model->vk_set_profile();
            }
        }
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

//--------------------------------------------------------------------

    /**
     * Calls the auth->logout method to destroy the session and cleanup,
     * then redirects to the home page.
     *
     * @access public
     *
     * @return void
     */
    public function logout() {
// Log the Activity
        $this->activity_model->log_activity($this->current_user->id, lang('us_log_logged_out') . ': ' . $this->input->ip_address(), 'users');

        $this->auth->logout();

        redirect('/');
    }

//end  logout()
//--------------------------------------------------------------------

    /**
     * Allows a user to start the process of resetting their password.
     * An email is allowed with a special temporary link that is only valid
     * for 24 hours. This link takes them to reset_password().
     *
     * @access public
     *
     * @return void
     */
    public function forgot_password() {

// if the user is not logged in continue to show the login page
        if ($this->auth->is_logged_in() === FALSE) {
            if (isset($_POST['submit'])) {
                $this->form_validation->set_rules('email', 'lang:bf_email', 'required|trim|strip_tags|valid_email|xss_clean');

                if ($this->form_validation->run() === FALSE) {
                    Template::set_message(lang('us_invalid_email'));
                } else {
// We validated. Does the user actually exist?
                    $user = $this->user_model->find_by('email', $_POST['email']);

                    if ($user !== FALSE) {
// User exists, so create a temp password.
                        $this->load->helpers(array('string', 'security'));

                        $pass_code = random_string('alnum', 40);

                        $hash = do_hash($pass_code . $user->salt . $_POST['email']);

// Save the hash to the db so we can confirm it later.
                        $this->user_model->update_where('email', $_POST['email'], array('reset_hash' => $hash, 'reset_by' => strtotime("+24 hours")));

// Create the link to reset the password
                        $pass_link = site_url('reset_password/' . str_replace('@', ':', $_POST['email']) . '/' . $hash);

// Now send the email
                        $this->load->library('emailer/emailer');

                        $data = array(
                            'to' => $_POST['email'],
                            'subject' => lang('us_reset_pass_subject'),
                            'message' => $this->load->view('_emails/forgot_password', array('link' => $pass_link), TRUE)
                        );
                        if ($this->emailer->send($data)) {
                            Template::set_message(lang('us_reset_pass_message'), 'success');
                        } else {
                            Template::set_message(lang('us_reset_pass_error') . $this->emailer->errors);
                        }
                    }//end if
                    else {
                        Template::set_message("User not exitst");
                    }
                }//end if
            }//end if

            Template::set_view('users/users/forgot_password');
            Template::set('page_title', 'Password Reset');
            Template::render();
        } else {

            Template::redirect('/');
        }//end if
    }

//end forgot_password()
//--------------------------------------------------------------------

    /**
     * Allows a user to edit their own profile information.
     *
     * @access public
     *
     * @return void
     */
    public function profile() {

        if ($this->auth->is_logged_in() === FALSE) {
            $this->auth->logout();
            redirect('login');
        }

        $this->load->helper('date');

        $this->load->config('address');
        $this->load->helper('address');

        $this->load->config('user_meta');
        $meta_fields = config_item('user_meta_fields');

        Template::set('meta_fields', $meta_fields);
        $user_id = $this->current_user->id;

        if ($this->input->post('submit')) {
            $error = FALSE;

            if ($this->save_user($user_id, $meta_fields)) {

                $meta_data = array();
                foreach ($meta_fields as $field) {
                    if ((!isset($field['admin_only']) || $field['admin_only'] === FALSE || (isset($field['admin_only']) && $field['admin_only'] === TRUE && isset($this->current_user) && $this->current_user->role_id == 1)) && (!isset($field['frontend']) || $field['frontend'] === TRUE)) {
                        $meta_data[$field['name']] = $this->input->post($field['name']);
                    }
                }

                if (( $pic = $this->input->post('profile_picture') ) && $pic['error'] == 0) {
                    /* for upload file */
                    $upload_path = FCPATH . "assets" . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "users_images";
                    $config['upload_path'] = $upload_path;
                    $config['allowed_types'] = 'gif|jpg|png|jpeg';
                    $config['max_size'] = '2048';

                    $this->load->library('upload', $config);

                    if ($this->upload->do_upload('profile_picture')) {
                        $up_data = $this->upload->data();
                        $meta_data['profile_picture'] = $up_data['file_name'];

                        //now create thumbnail.
                        $thumb_path = FCPATH . "assets" . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "users_images" . DIRECTORY_SEPARATOR . "thumb";
                        create_thumbnail($up_data['full_path'], $thumb_path);

                        $user_info = $this->user_model->find_user_and_meta($this->current_user->id);
                        if (isset($user_info->profile_picture) && !empty($user_info->profile_picture)) {
                            @unlink($upload_path . "/$user_info->profile_picture");
                            @unlink($thumb_path . "/$user_info->profile_picture");
                        }
                    } else {
                        Template::set_message($this->upload->display_errors());
                        Template::redirect('/users/profile');
                    }
                    /* end upload file */
                }

// now add the meta is there is meta data
                $this->user_model->save_meta_for($user_id, $meta_data);

// Log the Activity

                $user = $this->user_model->find($user_id);
                $log_name = (isset($user->display_name) && !empty($user->display_name)) ? $user->display_name : ($this->settings_lib->item('auth.use_usernames') ? $user->username : $user->email);
                $this->activity_model->log_activity($this->current_user->id, lang('us_log_edit_profile') . ': ' . $log_name, 'users');

                Template::set_message(lang('us_profile_updated_success'), 'success');

// redirect to make sure any language changes are picked up
                Template::redirect('/users/profile');
                //exit;
            } else {
                Template::set_message(lang('us_profile_updated_error'));
            }//end if
        }//end if
// get the current user information
        $user = $this->user_model->find_user_and_meta($this->current_user->id);

        $settings = $this->settings_lib->find_all();
        if ($settings['auth.password_show_labels'] == 1) {
            Assets::add_module_js('users', 'password_strength.js');
            Assets::add_module_js('users', 'jquery.strength.js');
            Assets::add_js($this->load->view('users_js', array('settings' => $settings), true), 'inline');
        }
// Generate password hint messages.
        $this->user_model->password_hints();

        Template::set('user', $user);
        Template::set('languages', unserialize($this->settings_lib->item('site.languages')));

        //for my reviews
        $this->load->model('share_your_exp/share_your_exp_model', 'exp');
        $reviews = $this->exp->reviews_by_me($user_id);
        Template::set('clubs', $reviews);

        Template::set_view('users/users/profile');
        Template::render();
    }

//end profile()
//--------------------------------------------------------------------

    /**
     * Allows the user to create a new password for their account. At the moment,
     * the only way to get here is to go through the forgot_password() process,
     * which creates a unique code that is only valid for 24 hours.
     *
     * @access public
     *
     * @param string $email The email address to check against.
     * @param string $code  A randomly generated alphanumeric code. (Generated by forgot_password() ).
     *
     * @return void
     */
    public function reset_password($email = '', $code = '') {
// if the user is not logged in continue to show the login page
        if ($this->auth->is_logged_in() === FALSE) {
// If there is no code, then it's not a valid request.
            if (empty($code) || empty($email)) {
                Template::set_message(lang('us_reset_invalid_email'), 'error');
                Template::redirect('/login');
            }

// Handle the form
            if ($this->input->post('submit')) {
                $this->form_validation->set_rules('password', 'lang:bf_password', 'required|trim|strip_tags|min_length[8]|max_length[120]|valid_password');
                $this->form_validation->set_rules('pass_confirm', 'lang:bf_password_confirm', 'required|trim|strip_tags|matches[password]');

                if ($this->form_validation->run() !== FALSE) {
// The user model will create the password hash for us.
                    $data = array('password' => $this->input->post('password'),
                        'pass_confirm' => $this->input->post('pass_confirm'),
                        'reset_by' => 0,
                        'reset_hash' => '');

                    if ($this->user_model->update($this->input->post('user_id'), $data)) {
// Log the Activity

                        $this->activity_model->log_activity($this->input->post('user_id'), lang('us_log_reset'), 'users');
                        Template::set_message(lang('us_reset_password_success'), 'success');
                        Template::redirect('/login');
                    } else {
                        Template::set_message(lang('us_reset_password_error') . $this->user_model->error, 'error');
                    }
                }
            }//end if
// Check the code against the database
            $email = str_replace(':', '@', $email);
            $user = $this->user_model->find_by(array(
                'email' => $email,
                'reset_hash' => $code,
                'reset_by >=' => time()
            ));

// It will be an Object if a single result was returned.
            if (!is_object($user)) {
                Template::set_message(lang('us_reset_invalid_email'), 'error');
                Template::redirect('/login');
            }

            $settings = $this->settings_lib->find_all();
            if ($settings['auth.password_show_labels'] == 1) {
                Assets::add_module_js('users', 'password_strength.js');
                Assets::add_module_js('users', 'jquery.strength.js');
                Assets::add_js($this->load->view('users_js', array('settings' => $settings), true), 'inline');
            }
// If we're here, then it is a valid request....
            Template::set('user', $user);

            Template::set_view('users/users/reset_password');
            Template::render();
        } else {
            Template::redirect('/');
        }//end if
    }

//end reset_password()
//--------------------------------------------------------------------

    public function register() {
        $this->load->model('roles/role_model');
        if ($this->input->post() && $this->input->is_ajax_request()) {
// Validate input            
            $this->form_validation->set_rules('email', 'lang:bf_email', 'required|trim|strip_tags|valid_email|max_length[120]|unique[users.email]|xss_clean');

            $this->form_validation->set_rules('password', 'lang:bf_password', 'required|trim|strip_tags|min_length[8]|max_length[120]|valid_password');
            $this->form_validation->set_rules('pass_confirm', 'lang:bf_password_confirm', 'required|trim|strip_tags|matches[password]');

            $this->form_validation->set_rules('display_name', 'lang:bf_display_name', 'trim|strip_tags|max_length[255]|xss_clean');

            if ($this->form_validation->run($this) !== FALSE) {
// Time to save the user...
                $data = array(
                    'display_name' => $_POST['display_name'],
                    'email' => $_POST['email'],
                    'password' => $_POST['password'],
                );

// User activation method
                $activation_method = $this->settings_lib->item('auth.user_activation_method');
// No activation method
                if ($activation_method == 0) {
// Activate the user automatically
                    $data['active'] = 1;
                }

                if ($user_id = $this->user_model->insert($data)) {
                    /*
                     * USER ACTIVATIONS ENHANCEMENT
                     */
// Prepare user messaging vars
                    $meta_data = array();
                    if ($this->input->post('email_update')) {
                        $meta_data['email_update'] = "1";
                    }
                    $this->user_model->save_meta_for($user_id, $meta_data);
                    $subject = '';
                    $email_mess = '';
                    $message = lang('us_email_thank_you');
                    $type = 'success';
                    $site_title = $this->settings_lib->item('site.title');
                    $error = false;

                    switch ($activation_method) {
                        case 0:
// No activation required. Activate the user and send confirmation email
                            $subject = str_replace('[SITE_TITLE]', $this->settings_lib->item('site.title'), lang('us_account_reg_complete'));
                            $email_mess = $this->load->view('_emails/activated', array('title' => $site_title, 'link' => site_url()), true);
                            $message .= lang('us_account_active_login');
                            break;
                        case 1:
// 	Email Activiation.
//	Create the link to activate membership
// Run the account deactivate to assure everything is set correctly
// Switch on the login type to test the correct field
                            $login_type = $this->settings_lib->item('auth.login_type');
                            switch ($login_type) {
                                case 'username':
                                    if ($this->settings_lib->item('auth.use_usernames')) {
                                        $id_val = $_POST['username'];
                                    } else {
                                        $id_val = $_POST['email'];
                                        $login_type = 'email';
                                    }
                                    break;
                                case 'email':
                                case 'both':
                                default:
                                    $id_val = $_POST['email'];
                                    $login_type = 'email';
                                    break;
                            } // END switch

                            $activation_code = $this->user_model->deactivate($id_val, $login_type);
                            $activate_link = site_url('activate/' . str_replace('@', ':', $_POST['email']) . '/' . $activation_code);
                            $subject = lang('us_email_subj_activate');

                            $email_message_data = array(
                                'title' => $site_title,
                                'code' => $activation_code,
                                'link' => $activate_link
                            );
                            $email_mess = $this->load->view('_emails/activate', $email_message_data, true);
                            $message .= lang('us_check_activate_email');
                            break;
                        case 2:
// Admin Activation
// Clear hash but leave user inactive
                            $subject = lang('us_email_subj_pending');
                            $email_mess = $this->load->view('_emails/pending', array('title' => $site_title), true);
                            $message .= lang('us_admin_approval_pending');
                            break;
                    }//end switch
// Now send the email
                    $this->load->library('emailer/emailer');
                    $data = array(
                        'to' => $_POST['email'],
                        'subject' => $subject,
                        'message' => $email_mess
                    );

                    if (!$this->emailer->send($data)) {
                        echo 'Send Email Error.';
                    }
// Log the Activity
                    $this->activity_model->log_activity($user_id, lang('us_log_register'), 'users');
//Template::redirect('/');
                    echo 'success';
                } else {
                    echo 'Registration failed.';
                }//end if
            }//end if
            else {
                $errors = $this->form_validation->error_array();
                $output = "";
                foreach ($errors as $key => $value) {
                    $output .= $value . '<br/>';
                }
                echo $output;
            }
        }//end if
        else {
            Template::redirect('/');
        }
    }

//end register()    
//--------------------------------------------------------------------

    /**
     * Save the user
     *
     * @access private
     *
     * @param int   $id          The id of the user in the case of an edit operation
     * @param array $meta_fields Array of meta fields fur the user
     *
     * @return bool
     */
    private function save_user($id = 0, $meta_fields = array()) {

        if ($id == 0) {
            $id = $this->current_user->id; /* ( $this->input->post('id') > 0 ) ? $this->input->post('id') :  */
        }

        $_POST['id'] = $id;

// Simple check to make the posted id is equal to the current user's id, minor security check
        if ($_POST['id'] != $this->current_user->id) {
            $this->form_validation->set_message('email', 'lang:us_invalid_userid');
            return FALSE;
        }

// Setting the payload for Events system.
        $payload = array('user_id' => $id, 'data' => $this->input->post());


        $this->form_validation->set_rules('email', 'lang:bf_email', 'required|trim|valid_email|max_length[120]|unique[users.email,users.id]|xss_clean');
        $this->form_validation->set_rules('password', 'lang:bf_password', 'trim|strip_tags|min_length[8]|max_length[120]|valid_password');

// check if a value has been entered for the password - if so then the pass_confirm is required
// if you don't set it as "required" the pass_confirm field could be left blank and the form validation would still pass
        $extra_rules = !empty($_POST['password']) ? 'required|' : '';
        $this->form_validation->set_rules('pass_confirm', 'lang:bf_password_confirm', 'trim|strip_tags|' . $extra_rules . 'matches[password]');

        if ($this->settings_lib->item('auth.use_usernames')) {
//            $this->form_validation->set_rules('username', 'lang:bf_username', 'required|trim|strip_tags|max_length[30]|unique[users.username,users.id]|xss_clean');
        }

        $this->form_validation->set_rules('language', 'lang:bf_language', 'required|trim|strip_tags|xss_clean');
//        $this->form_validation->set_rules('timezones', 'lang:bf_timezone', 'required|trim|strip_tags|max_length[4]|xss_clean');
        $this->form_validation->set_rules('display_name', 'lang:bf_display_name', 'trim|strip_tags|max_length[255]|xss_clean');

// Added Event "before_user_validation" to run before the form validation
        Events::trigger('before_user_validation', $payload);


        foreach ($meta_fields as $field) {
            if ((!isset($field['admin_only']) || $field['admin_only'] === FALSE || (isset($field['admin_only']) && $field['admin_only'] === TRUE && isset($this->current_user) && $this->current_user->role_id == 1)) && (!isset($field['frontend']) || $field['frontend'] === TRUE)) {
                $this->form_validation->set_rules($field['name'], $field['label'], $field['rules']);
            }
        }


        if ($this->form_validation->run($this) === FALSE) {
            return FALSE;
        }

// Compile our core user elements to save.
        $data = array(
            'email' => $this->input->post('email'),
            'language' => $this->input->post('language'),
            'timezone' => $this->input->post('timezones'),
        );

        if ($this->input->post('password')) {
            $data['password'] = $this->input->post('password');
        }

        if ($this->input->post('pass_confirm')) {
            $data['pass_confirm'] = $this->input->post('pass_confirm');
        }

        if ($this->input->post('display_name')) {
            $data['display_name'] = $this->input->post('display_name');
        }

        if ($this->settings_lib->item('auth.use_usernames')) {
            if ($this->input->post('username')) {
                $data['username'] = $this->input->post('username');
            }
        }

// Any modules needing to save data?
// Event to run after saving a user
        Events::trigger('save_user', $payload);

        return $this->user_model->update($id, $data);
    }

//end save_user()
//--------------------------------------------------------------------
//--------------------------------------------------------------------
// ACTIVATION METHODS
//--------------------------------------------------------------------
    /*
      Activate user.

      Checks a passed activation code and if verified, enables the user
      account. If the code fails, an error is generated and returned.

     */
    public function activate($email = FALSE, $code = FALSE) {

        if ($this->input->post('submit')) {
            $this->form_validation->set_rules('code', 'Verification Code', 'required|trim|xss_clean');
            if ($this->form_validation->run() == TRUE) {
                $code = $this->input->post('code');
            }
        } else {
            if ($email === FALSE) {
                $email = $this->uri->segment(2);
            }
            if ($code === FALSE) {
                $code = $this->uri->segment(3);
            }
        }

// fix up the email
        if (!empty($email)) {
            $email = str_replace(":", "@", $email);
        }


        if (!empty($code)) {
            $activated = $this->user_model->activate($email, $code);
            if ($activated) {
// Now send the email
                $this->load->library('emailer/emailer');

                $site_title = $this->settings_lib->item('site.title');

                $email_message_data = array(
                    'title' => $site_title,
                    'link' => site_url('login')
                );
                $data = array
                    (
                    'to' => $this->user_model->find($activated)->email,
                    'subject' => lang('us_account_active'),
                    'message' => $this->load->view('_emails/activated', $email_message_data, TRUE)
                );

                if ($this->emailer->send($data)) {
                    Template::set_message(lang('us_account_active'), 'success');
                } else {
                    Template::set_message(lang('us_err_no_email') . $this->emailer->errors, 'error');
                }
                Template::redirect('/');
            } else {
                Template::set_message(lang('us_activate_error_msg') . $this->user_model->error . '. ' . lang('us_err_activate_code'), 'error');
            }
        }
        Template::set_view('users/users/activate');
        Template::set('page_title', 'Account Activation');
        Template::render();
    }

//--------------------------------------------------------------------

    /*
      Method: resend_activation

      Allows a user to request that their activation code be resent to their
      account's email address. If a matching email is found, the code is resent.
     */
    public function resend_activation() {
        if (isset($_POST['submit'])) {
            $this->form_validation->set_rules('email', 'lang:bf_email', 'required|trim|strip_tags|valid_email|xss_clean');

            if ($this->form_validation->run() === FALSE) {
                Template::set_message('Cannot find that email in our records.', 'error');
            } else {
// We validated. Does the user actually exist?
                $user = $this->user_model->find_by('email', $_POST['email']);

                if ($user !== FALSE) {
// User exists, so create a temp password.
                    $this->load->helpers(array('string', 'security'));

                    $pass_code = random_string('alnum', 40);

                    $activation_code = do_hash($pass_code . $user->salt . $_POST['email']);

                    $site_title = $this->settings_lib->item('site.title');

// Save the hash to the db so we can confirm it later.
                    $this->user_model->update_where('email', $_POST['email'], array('activate_hash' => $activation_code));

// Create the link to reset the password
                    $activate_link = site_url('activate/' . str_replace('@', ':', $_POST['email']) . '/' . $activation_code);

// Now send the email
                    $this->load->library('emailer/emailer');

                    $email_message_data = array(
                        'title' => $site_title,
                        'code' => $activation_code,
                        'link' => $activate_link
                    );

                    $data = array
                        (
                        'to' => $_POST['email'],
                        'subject' => 'Activation Code',
                        'message' => $this->load->view('_emails/activate', $email_message_data, TRUE)
                    );
                    $this->emailer->enable_debug(true);
                    if ($this->emailer->send($data)) {
                        Template::set_message(lang('us_check_activate_email'), 'success');
                    } else {
                        if (isset($this->emailer->errors)) {
                            $errors = '';
                            if (is_array($this->emailer->errors)) {
                                foreach ($this->emailer->errors as $error) {
                                    $errors .= $error . "<br />";
                                }
                            } else {
                                $errors = $this->emailer->errors;
                            }
                            Template::set_message(lang('us_err_no_email') . $errors . ", " . $this->emailer->debug, 'error');
                        }
                    }
                }
            }
        }
        Template::set_view('users/users/resend_activation');
        Template::set('page_title', 'Activate Account');
        Template::render();
    }

    /*
     * Login url for Facebook
     */

    public function fb_login() {
        Template::redirect($this->user_model->fb_login_url());
    }

    /*
     * Login url for vk
     */

    public function vk_login() {
        if ($this->input->get('share') == "true") {
            $this->session->set_userdata('previous_page', base_url('share_your_exp'));
        }
        Template::redirect($this->user_model->vk_login_url());
    }

    /*
     * Login url for twitter
     */

    public function tw_login() {
        if ($this->input->get('share') == "true") {
            $this->session->set_userdata('previous_page', base_url('share_your_exp'));
        }
        $this->user_model->tw_login();
    }

    /*
     * Login url for google+
     */

    public function gp_login() {
        if ($this->input->get('share') == "true") {
            $this->session->set_userdata('previous_page', base_url('share_your_exp'));
        }
        $this->user_model->gp_login();
    }

    public function ws_register() {
        $this->form_validation->set_rules('email', 'lang:bf_email', 'required|trim|strip_tags|valid_email|max_length[120]|unique[users.email]|xss_clean');

        $this->form_validation->set_rules('password', 'lang:bf_password', 'required|trim|strip_tags|min_length[8]|max_length[120]|valid_password');
//$this->form_validation->set_rules('pass_confirm', 'lang:bf_password_confirm', 'required|trim|strip_tags|matches[password]');

        $this->form_validation->set_rules('full_name', 'Name', 'required|trim|max_length[255]|xss_clean');

        if ($this->form_validation->run($this) == FALSE)
            return $this->form_validation->error_array();

        $data = array(
            'email' => $_POST['email'],
            'password' => $_POST['password'],
            'display_name' => $_POST['full_name'],
        );
        /* $meta_data = array(
          'first_name' => $first_name,
          'last_name' => $last_name,
          'dob' => $_POST['dob'],
          'gender' => $_POST['gender'],
          ); */
        $data['active'] = 1;
        if ($user_id = $this->user_model->insert($data)) {
//$this->user_model->save_meta_for($user_id, $meta_data);
            /* for send email */
// User activation method
            $activation_method = $this->settings_lib->item('auth.user_activation_method');
// No activation method
// Activate the user automatically
// Prepare user messaging vars
            $subject = '';
            $email_mess = '';
            $message = lang('us_email_thank_you');
            $type = 'success';
            $site_title = $this->settings_lib->item('site.title');
            $error = false;

            switch ($activation_method) {
                case 0:
// No activation required. Activate the user and send confirmation email
                    $subject = str_replace('[SITE_TITLE]', $this->settings_lib->item('site.title'), lang('us_account_reg_complete'));
                    $email_mess = $this->load->view('_emails/activated', array('title' => $site_title, 'link' => site_url()), true);
                    $message .= lang('us_account_active_login');
                    break;
                case 1:
// 	Email Activiation.
//	Create the link to activate membership
// Run the account deactivate to assure everything is set correctly
// Switch on the login type to test the correct field
                    $login_type = $this->settings_lib->item('auth.login_type');
                    switch ($login_type) {
                        case 'username':
                            if ($this->settings_lib->item('auth.use_usernames')) {
                                $id_val = $_POST['username'];
                            } else {
                                $id_val = $_POST['email'];
                                $login_type = 'email';
                            }
                            break;
                        case 'email':
                        case 'both':
                        default:
                            $id_val = $_POST['email'];
                            $login_type = 'email';
                            break;
                    } // END switch

                    $activation_code = $this->user_model->deactivate($id_val, $login_type);
                    $activate_link = site_url('activate/' . str_replace('@', ':', $_POST['email']) . '/' . $activation_code);
                    $subject = lang('us_email_subj_activate');

                    $email_message_data = array(
                        'title' => $site_title,
                        'code' => $activation_code,
                        'link' => $activate_link
                    );
                    $email_mess = $this->load->view('_emails/activate', $email_message_data, true);
                    $message .= lang('us_check_activate_email');
                    break;
                case 2:
// Admin Activation
// Clear hash but leave user inactive
                    $subject = lang('us_email_subj_pending');
                    $email_mess = $this->load->view('_emails/pending', array('title' => $site_title), true);
                    $message .= lang('us_admin_approval_pending');
                    break;
            }//end switch
// Now send the email
            $this->load->library('emailer/emailer');
            $data = array(
                'to' => $_POST['email'],
                'subject' => $subject,
                'message' => $email_mess
            );

            if (!$this->emailer->send($data)) {
                return array('send_email_error' => 'Email not sent');
            }
            /* end send email */
            return $user_id;
        }
        return FALSE;
    }

    public function ws_login() {
        if ($this->input->post()) {
            $remember = $this->input->post('remember_me') == '1' ? TRUE : FALSE;

            if ($this->auth->login($this->input->post('email'), $this->input->post('password'), $remember) === TRUE) {

                $this->activity_model->log_activity($this->auth->user_id(), lang('us_log_logged') . ': ' . $this->input->ip_address(), 'users');

                $user = $this->user_model->find_by('email', $this->input->post('email'));

                $meta = $this->user_model->find_meta_for($user->id);

                $user_data = array();
                $user_data['id'] = $user->id;
                $user_data['name'] = $user->display_name;
                $user_data['email'] = "";
                if ($user->email) {
                    $user_data['email'] = $user->email;
                }
                $user_data['image_path'] = base_url('assets/uploads/users_images_thumb/');
                $user_data['image'] = "user.png";
                if (isset($meta->profile_picture) && !empty($meta->profile_picture)) {
                    $user_data['image'] = $meta->profile_picture;
                }
                return $user_data;
            } else {
                return FALSE;
            }
        } else {
            return FALSE;
        }
    }

    public function wb_forgot_password($email) {
// We validated. Does the user actually exist?
        $user = $this->user_model->find_by('email', $email);

        if (!$user) {
            return FALSE;
        }
        // User exists, so create a temp password.
        $this->load->helpers(array('string', 'security'));

        $pass_code = random_string('alnum', 40);

        $hash = do_hash($pass_code . $user->salt . $email);

// Save the hash to the db so we can confirm it later.
        $this->user_model->update_where('email', $email, array('reset_hash' => $hash, 'reset_by' => strtotime("+24 hours")));

// Create the link to reset the password
        $pass_link = site_url('reset_password/' . str_replace('@', ':', $email) . '/' . $hash);

// Now send the email
        $this->load->library('emailer/emailer');

        $data = array(
            'to' => $email,
            'subject' => lang('us_reset_pass_subject'),
            'message' => $this->load->view('_emails/forgot_password', array('link' => $pass_link), TRUE)
        );

        if ($this->emailer->send($data)) {
            return TRUE;
        } else {
            return FALSE;
        }
    }

    public function wb_me($user_id) {
        $user = $this->user_model->find($user_id);
        if (!$user) {
            return FALSE;
        }
        $meta = $this->user_model->find_meta_for($user_id);
        $data = array();

        $data['id'] = $user->id;
        $data['name'] = $user->display_name;
        $data['email'] = $user->email;
        $data['image_path'] = base_url('assets/uploads/users_images/thumb/');
        $data['image'] = "user.png";
        if (isset($meta->profile_picture) && !empty($meta->profile_picture)) {
            $data['image'] = $meta->profile_picture;
        }
        $this->load->model('share_your_exp/share_your_exp_model', 'exp');
        $counts = $this->exp->get_review_count_by_user_id($user_id);
        $data['review_count_approved'] = $counts['a'];
        $data['review_count_unapproved'] = $counts['u'];
        return $data;
    }

    public function ws_edit_profile($user_id) {
        $this->load->config('user_meta');
        //$meta_fields = config_item('user_meta_fields');

        $meta_data = array();
        /*
          foreach ($meta_fields as $field) {
          if ((!isset($field['admin_only']) || $field['admin_only'] === FALSE || (isset($field['admin_only']) && $field['admin_only'] === TRUE && isset($this->current_user) && $this->current_user->role_id == 1)) && (!isset($field['frontend']) || $field['frontend'] === TRUE)) {
          $meta_data[$field['name']] = $this->input->post($field['name']);
          }
          } */

        if (( $pic = $this->input->post('profile_picture') ) && $pic['error'] == 0) {
            /* for upload file */
            $upload_path = FCPATH . "assets" . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "users_images";
            $config['upload_path'] = $upload_path;
            $config['allowed_types'] = 'gif|jpg|png|jpeg';
            $config['max_size'] = '2048';

            $this->load->library('upload', $config);

            if ($this->upload->do_upload('profile_picture')) {
                $up_data = $this->upload->data();
                $meta_data['profile_picture'] = $up_data['file_name'];

                //now create thumbnail.
                $thumb_path = FCPATH . "assets" . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "users_images" . DIRECTORY_SEPARATOR . "thumb";
                create_thumbnail($up_data['full_path'], $thumb_path);

                $user_info = $this->user_model->find_user_and_meta($user_id);
                if (isset($user_info->profile_picture) && !empty($user_info->profile_picture)) {
                    @unlink($upload_path . "/$user_info->profile_picture");
                    @unlink($thumb_path . "/$user_info->profile_picture");
                }
            } else {
                return $this->upload->display_errors();
            }
            /* end upload file */
        }

// now add the meta is there is meta data
        $this->user_model->save_meta_for($user_id, $meta_data);

// Log the Activity
        /*
          $user = $this->user_model->find($user_id);
          $log_name = (isset($user->display_name) && !empty($user->display_name)) ? $user->display_name : ($this->settings_lib->item('auth.use_usernames') ? $user->username : $user->email);
          $this->activity_model->log_activity($user_id, lang('us_log_edit_profile') . ': ' . $log_name, 'users'); */
        return TRUE;
        exit;
    }

}
