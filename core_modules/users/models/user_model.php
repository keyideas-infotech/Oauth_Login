<?php

if (!defined('BASEPATH'))
    exit('No direct script access allowed');
/**
 * Bonfire
 *
 * An open source project to allow developers get a jumpstart their development of CodeIgniter applications
 *
 * @package   Bonfire
 * @author    Bonfire Dev Team
 * @copyright Copyright (c) 2011 - 2012, Bonfire Dev Team
 * @license   http://guides.cibonfire.com/license.html
 * @link      http://cibonfire.com
 * @since     Version 1.0
 * @filesource
 */
// ------------------------------------------------------------------------

/**
 * User Model
 *
 * The central way to access and perform CRUD on users.
 *
 * @package    Bonfire
 * @subpackage Modules_Users
 * @category   Models
 * @author     Bonfire Dev Team
 * @link       http://cibonfire.com
 */
class User_model extends BF_Model {

    /**
     * Name of the table
     *
     * @access protected
     *
     * @var string
     */
    protected $table = 'users';

    /**
     * Use soft deletes or not
     *
     * @access protected
     *
     * @var bool
     */
    protected $soft_deletes = TRUE;

    /**
     * The date format to use
     *
     * @access protected
     *
     * @var string
     */
    protected $date_format = 'datetime';

    /**
     * Set the created time automatically on a new record
     *
     * @access protected
     *
     * @var bool
     */
    protected $set_modified = FALSE;

    //--------------------------------------------------------------------

    /**
     * Constructor
     *
     * @return void
     */
    public function __construct() {
        parent::__construct();
        $fb_config['appId'] = $this->config->item('fb_appId');
        $fb_config['secret'] = $this->config->item('fb_secret');
        $this->load->library('social_media_auth/fb/facebook', $fb_config, 'fb');
        $this->load->library("social_media_auth/twitter/twitter_auth", NULL, "twitt");
        $this->load->library("social_media_auth/google/google_authentication", NULL, "gp");
        $this->load->helper("thumbnail");
        

        $vk_config['appId'] = $this->config->item('vk_appId');
        $vk_config['secret'] = $this->config->item('vk_secret');
        $this->load->library('social_media_auth/vk/VK', $vk_config, 'vk');
    }

//end __construct()
    //--------------------------------------------------------------------

    /**
     * Helper Method for Generating Password Hints based on Settings library.
     *
     * Call this method in your controller and echo $password_hints in your view.
     *
     * @access public
     *
     * @return void
     */
    public function password_hints() {
        $min_length = (string) $this->settings_lib->item('auth.password_min_length');

        $message = sprintf(lang('bf_password_min_length_help'), $min_length);


        if ($this->settings_lib->item('auth.password_force_numbers') == 1) {
            $message .= '<br />' . lang('bf_password_number_required_help');
        }

        if ($this->settings_lib->item('auth.password_force_symbols') == 1) {
            $message .= '<br />' . lang('bf_password_symbols_required_help');
        }

        if ($this->settings_lib->item('auth.password_force_mixed_case') == 1) {
            $message .= '<br />' . lang('bf_password_caps_required_help');
        }

        Template::set('password_hints', $message);

        unset($min_length, $message);
    }

//end password_hints()
    //--------------------------------------------------------------------

    /**
     * Creates a new user in the database.
     *
     * Required parameters sent in the $data array:
     * * password
     * * A unique email address
     *
     * If no _role_id_ is passed in the $data array, it will assign the default role from <Roles> model.
     *
     * @access public
     *
     * @param array $data An array of user information.
     *
     * @return bool|int The ID of the new user.
     */
    public function insert($data = array()) {
        if (!$this->_function_check(FALSE, $data)) {
            return FALSE;
        }

        if (!isset($data['password']) || empty($data['password'])) {
            $this->error = lang('us_no_password');
            return FALSE;
        }

        if (!isset($data['email']) || empty($data['email'])) {
            $this->error = lang('us_no_email');
            return FALSE;
        }

        // Is this a unique email?
        if ($this->is_unique('email', $data['email']) == FALSE) {
            $this->error = lang('us_email_taken');
            return FALSE;
        }

        if (empty($data['username'])) {
            unset($data['username']);
        }

        // Display Name
        if (!isset($data['display_name']) || (isset($data['display_name']) && empty($data['display_name']))) {
            if ($this->settings_lib->item('auth.use_usernames') == 1 && !empty($data['username'])) {
                $data['display_name'] = $data['username'];
            } else {
                $data['display_name'] = $data['email'];
            }
        }

        list($password, $salt) = $this->hash_password($data['password']);

        unset($data['password'], $data['pass_confirm'], $data['submit']);

        $data['password_hash'] = $password;
        $data['salt'] = $salt;

        // What's the default role?
        if (!isset($data['role_id'])) {
            // We better have a guardian here
            if (!class_exists('Role_model')) {
                $this->load->model('roles/Role_model', 'role_model');
            }

            $data['role_id'] = $this->role_model->default_role_id();
        }

        $id = parent::insert($data);

        Events::trigger('after_create_user', $id);

        return $id;
    }

//end insert()
    //--------------------------------------------------------------------

    /**
     * Updates an existing user. Before saving, it will:
     * * generate a new password/salt combo if both password and pass_confirm are passed in.
     * * store the country code
     *
     * @access public
     *
     * @param int   $id   An INT with the user's ID.
     * @param array $data An array of key/value pairs to update for the user.
     *
     * @return bool TRUE/FALSE
     */
    public function update($id = null, $data = array()) {
        if ($id) {
            $trigger_data = array('user_id' => $id, 'data' => $data);
            Events::trigger('before_user_update', $trigger_data);
        }

        if (empty($data['pass_confirm']) && isset($data['password'])) {
            unset($data['pass_confirm'], $data['password']);
        } else if (!empty($data['password']) && !empty($data['pass_confirm']) && $data['password'] == $data['pass_confirm']) {
            list($password, $salt) = $this->hash_password($data['password']);

            unset($data['password'], $data['pass_confirm']);

            $data['password_hash'] = $password;
            $data['salt'] = $salt;
        }

        // Handle the country
        if (isset($data['iso'])) {
            $data['country_iso'] = $data['iso'];
            unset($data['iso']);
        }

        $return = parent::update($id, $data);

        if ($return) {
            $trigger_data = array('user_id' => $id, 'data' => $data);
            Events::trigger('after_user_update', $trigger_data);
        }

        return $return;
    }

//end update()

    /**
     * Returns the number of users that belong to each role.
     *
     * @access public
     *
     * @return bool|array An array of objects representing the number in each role.
     */
    public function set_to_default_role($current_role) {
        $prefix = $this->db->dbprefix;

        if (!is_int($current_role)) {
            return FALSE;
        }

        // We better have a guardian here
        if (!class_exists('Role_model')) {
            $this->load->model('roles/Role_model', 'role_model');
        }

        $data = array();
        $data['role_id'] = $this->role_model->default_role_id();

        $query = $this->db->where('role_id', $current_role)
                ->update($this->table, $data);

        if ($query) {
            return TRUE;
        }

        return FALSE;
    }

//end set_to_default_role()
    //--------------------------------------------------------------------

    /**
     * Finds an individual user record. Also returns role information for the user.
     *
     * @access public
     *
     * @param int $id An INT with the user's ID.
     *
     * @return bool|object An object with the user's information.
     */
    public function find($id = null) {
        if (empty($this->selects)) {
            $this->select($this->table . '.*, role_name');
        }

        $this->db->join('roles', 'roles.role_id = users.role_id', 'left');

        return parent::find($id);
    }

//end find()
    //--------------------------------------------------------------------

    /**
     * Returns all user records, and their associated role information.
     *
     * @access public
     *
     * @param bool $show_deleted If FALSE, will only return non-deleted users. If TRUE, will return both deleted and non-deleted users.
     *
     * @return bool An array of objects with each user's information.
     */
    public function find_all($show_deleted = FALSE) {
        if (empty($this->selects)) {
            $this->select($this->table . '.*, role_name');
        }

        if ($show_deleted === FALSE) {
            $this->db->where('users.deleted', 0);
        }

        $this->db->join('roles', 'roles.role_id = users.role_id', 'left');

        return parent::find_all();
    }

//end find_all()
    //--------------------------------------------------------------------

    /**
     * Locates a single user based on a field/value match, with their role information.
     * If the $field string is 'both', then it will attempt to find the user
     * where their $value field matches either the username or email on record.
     *
     * @access public
     *
     * @param string $field A string with the field to match.
     * @param string $value A string with the value to search for.
     *
     * @return bool|object An object with the user's info, or FALSE on failure.
     */
    public function find_by($field = null, $value = null) {
        $this->db->join('roles', 'roles.role_id = users.role_id', 'left');

        if (empty($this->selects)) {
            $this->select($this->table . '.*, role_name');
        }

        if ($field == 'both') {
            $field = array(
                'username' => $value,
                'email' => $value
            );

            return parent::find_by($field, null, 'or');
        }

        return parent::find_by($field, $value);
    }

//end find_by()
    //--------------------------------------------------------------------

    /**
     * Returns the number of users that belong to each role.
     *
     * @access public
     *
     * @return bool|array An array of objects representing the number in each role.
     */
    public function count_by_roles() {
        $prefix = $this->db->dbprefix;

        $sql = "SELECT role_name, COUNT(1) as count
				FROM {$prefix}users, {$prefix}roles
				WHERE {$prefix}users.role_id = {$prefix}roles.role_id
				GROUP BY {$prefix}users.role_id";

        $query = $this->db->query($sql);

        if ($query->num_rows()) {
            return $query->result();
        }

        return FALSE;
    }

//end count_by_roles()
    //--------------------------------------------------------------------

    /**
     * Counts all users in the system.
     *
     * @access public
     *
     * @param bool $get_deleted If FALSE, will only return active users. If TRUE, will return both deleted and active users.
     *
     * @return int An INT with the number of users found.
     */
    public function count_all($get_deleted = FALSE) {
        if ($get_deleted) {
            // Get only the deleted users
            $this->db->where('users.deleted !=', 0);
        } else {
            $this->db->where('users.deleted', 0);
        }

        return $this->db->count_all_results('users');
    }

//end count_all()
    //--------------------------------------------------------------------

    /**
     * Performs a standard delete, but also allows for purging of a record.
     *
     * @access public
     *
     * @param int  $id    An INT with the record ID to delete.
     * @param bool $purge If FALSE, will perform a soft-delete. If TRUE, will permanently delete the record.
     *
     * @return bool TRUE/FALSE
     */
    public function delete($id = 0, $purge = FALSE) {
        if ($purge === TRUE) {
            // temporarily set the soft_deletes to TRUE.
            $this->soft_deletes = FALSE;
        }

        return parent::delete($id);
    }

//end delete()
    //--------------------------------------------------------------------
    //--------------------------------------------------------------------
    // !AUTH HELPER METHODS
    //--------------------------------------------------------------------

    /**
     * Generates a new salt and password hash for the given password.
     *
     * @access public
     *
     * @param string $old The password to hash.
     *
     * @return array An array with the hashed password and new salt.
     */
    public function hash_password($old = '') {
        if (!function_exists('do_hash')) {
            $this->load->helper('security');
        }

        $salt = $this->generate_salt();
        $pass = do_hash($salt . $old);

        return array($pass, $salt);
    }

//end hash_password()
    //--------------------------------------------------------------------

    /**
     * Create a salt to be used for the passwords
     *
     * @access private
     *
     * @return string A random string of 7 characters
     */
    private function generate_salt() {
        if (!function_exists('random_string')) {
            $this->load->helper('string');
        }

        return random_string('alnum', 7);
    }

//end generate_salt()
    //--------------------------------------------------------------------
    //--------------------------------------------------------------------
    // !HMVC METHOD HELPERS
    //--------------------------------------------------------------------

    /**
     * Returns the most recent login attempts and their description.
     *
     * @access public
     *
     * @param int $limit An INT which is the number of results to return.
     *
     * @return bool|array An array of objects with the login information.
     */
    public function get_login_attempts($limit = 15) {
        $this->db->limit($limit);
        $this->db->order_by('login', 'desc');
        $query = $this->db->get('login_attempts');

        if ($query->num_rows()) {
            return $query->result();
        }

        return FALSE;
    }

//end get_login_attempts()
    //--------------------------------------------------------------------
    //--------------------------------------------------------------------
    // !META METHODS
    //--------------------------------------------------------------------

    /**
     * Saves one or more key/value pairs of additional meta information for a user.
     *
     * @access public
     * @example
     * $data = array(
     *    'location'	=> 'That City, Katmandu',
     *    'interests'	=> 'My interests'
     *    );
     * $this->user_model->save_meta_for($user_id, $data);
     *
     * @param int   $user_id The ID of the user to save the meta for.
     * @param array $data    An array of key/value pairs to save.
     *
     * @return void
     */
    public function save_meta_for($user_id = null, $data = array()) {
        if (!is_numeric($user_id)) {
            $this->error = lang('us_invalid_user_id');
        }

        $this->table = 'user_meta';
        $this->key = 'meta_id';

        foreach ($data as $key => $value) {
            $this->db->where('user_id', $user_id);
            $this->db->where('meta_key', $key);
            $query = $this->db->get('user_meta');

            $obj = array(
                'user_id' => $user_id,
                'meta_key' => $key,
                'meta_value' => $value
            );

            if ($query->num_rows() == 0 && !empty($value)) {
                // Insert
                $this->db->insert('user_meta', $obj);
            }
            // Update
            else if ($query->num_rows() > 0) {
                $row = $query->row();
                $meta_id = $row->meta_id;

                $this->db->where('user_id', $user_id);
                $this->db->where('meta_key', $key);
                $this->db->set('meta_value', $value);
                $this->db->update('user_meta', $obj);
            }//end if
        }//end foreach
        // Reset our table info
        $this->table = 'users';
        $this->key = 'id';
    }

//end save_meta_for()
    //--------------------------------------------------------------------

    /**
     * Retrieves all meta values defined for a user.
     *
     * @access public
     *
     * @param int   $user_id An INT with the user's ID to find the meta for.
     * @param array $fields  An array of meta_key names to retrieve.
     *
     * @return null A stdObject with the key/value pairs, or NULL.
     */
    public function find_meta_for($user_id = null, $fields = null) {
        if (!is_numeric($user_id)) {
            $this->error = lang('us_invalid_user_id');
        }

        $this->table = 'user_meta';
        $this->key = 'meta_id';

        // Limiting to certain fields?
        if (is_array($fields)) {
            $this->db->where_in('meta_key', $fields);
        }

        $this->db->where('user_id', $user_id);
        $query = $this->db->get('user_meta');

        if ($query->num_rows()) {
            $rows = $query->result();

            $result = new stdClass();
            foreach ($rows as $row) {
                $key = $row->meta_key;
                $result->$key = $row->meta_value;
            }
        } else {
            $result = null;
        }

        // Reset our table info
        $this->table = 'users';
        $this->key = 'id';

        return $result;
    }

//end find_meta_for()
    //--------------------------------------------------------------------

    /**
     * Locates a single user and joins there meta information based on a the user id match.
     *
     * @access public
     *
     * @param int $user_id Integer of User ID to fetch
     *
     * @return bool|object An object with the user's info and meta information, or FALSE on failure.
     */
    public function find_user_and_meta($user_id = null) {
        if (!is_numeric($user_id)) {
            $this->error = lang('us_invalid_user_id');
        }

        $result = $this->find($user_id);

        $this->db->where('user_id', $user_id);
        $query = $this->db->get('user_meta');

        if ($query->num_rows()) {
            $rows = $query->result();

            foreach ($rows as $row) {
                $key = $row->meta_key;
                $result->$key = $row->meta_value;
            }
        }

        $query->free_result();
        return $result;
    }

//end find_user_and_meta()
    //--------------------------------------------------------------------
    //--------------------------------------------------------------------
    // !ACTIVATION
    //--------------------------------------------------------------------

    /**
     * Count Inactive users.
     *
     * @access public
     *
     * @return int Inactive user count.
     */
    public function count_inactive_users() {
        $this->db->where('active', -1);
        return $this->count_all(FALSE);
    }

//end count_inactive_users()

    /**
     * Accepts an activation code and validates is against a matching entry int eh database.
     *
     * There are some instances where we want to remove the activation hash yet leave the user
     * inactive (Admin Activation scenario), so leave_inactive handles this use case.
     *
     * @access public
     *
     * @param string $email          The email address to be verified
     * @param string $code           The activation code to be verified
     * @param bool   $leave_inactive Flag whether to remove the activate hash value, but leave active = 0
     *
     * @return int User Id on success, FALSE on error
     */
    public function activate($email = FALSE, $code = FALSE, $leave_inactive = FALSE) {

        if ($code === FALSE) {
            $this->error = lang('us_err_no_activate_code');
            return FALSE;
        }

        if (!empty($email)) {
            $this->db->where('email', $email);
        }

        $query = $this->db->select('id')
                ->where('activate_hash', $code)
                ->limit(1)
                ->get($this->table);

        if ($query->num_rows() !== 1) {
            $this->error = lang('us_err_no_matching_code');
            return FALSE;
        }

        $result = $query->row();
        $active = ($leave_inactive === FALSE) ? 1 : 0;
        if ($this->update($result->id, array('activate_hash' => '', 'active' => $active))) {
            return $result->id;
        }
    }

//end activate()

    /**
     * This function is triggered during account set up to assure user is not active and,
     * if not supressed, generate an activation hash code. This function can be used to
     * deactivate accounts based on public view events.
     *
     * @param int    $user_id    The username or email to match to deactivate
     * @param string $login_type Login Method
     * @param bool   $make_hash  Create a hash
     *
     * @return mixed $activate_hash on success, FALSE on error
     */
    public function deactivate($user_id = FALSE, $login_type = 'email', $make_hash = TRUE) {
        if ($user_id === FALSE) {
            return FALSE;
        }

        // create a temp activation code.
        $activate_hash = '';
        if ($make_hash === true) {
            $this->load->helpers(array('string', 'security'));
            $activate_hash = do_hash(random_string('alnum', 40) . time());
        }

        $this->db->update($this->table, array('active' => 0, 'activate_hash' => $activate_hash), array($login_type => $user_id));

        return ($this->db->affected_rows() == 1) ? $activate_hash : FALSE;
    }

//end deactivate()

    /**
     * Admin specific activation function for admin approvals or re-activation.
     *
     * @access public
     *
     * @param int $user_id The user ID to activate
     *
     * @return bool TRUE on success, FALSE on error
     */
    public function admin_activation($user_id = FALSE) {

        if ($user_id === FALSE) {
            $this->error = lang('us_err_no_id');
            return FALSE;
        }

        $query = $this->db->select('id')
                ->where('id', $user_id)
                ->limit(1)
                ->get($this->table);

        if ($query->num_rows() !== 1) {
            $this->error = lang('us_err_no_matching_id');
            return FALSE;
        }

        $result = $query->row();
        $this->update($result->id, array('activate_hash' => '', 'active' => 1));

        if ($this->db->affected_rows() > 0) {
            return $result->id;
        } else {
            $this->error = lang('us_err_user_is_active');
            return FALSE;
        }
    }

//end admin_activation()

    /**
     * Admin only deactivation function.
     *
     * @access public
     *
     * @param int $user_id The user ID to deactivate
     *
     * @return bool TRUE on success, FALSE on error
     */
    public function admin_deactivation($user_id = FALSE) {
        if ($user_id === FALSE) {
            $this->error = lang('us_err_no_id');
            return FALSE;
        }

        if ($this->deactivate($user_id, 'id', FALSE)) {
            return $user_id;
        } else {
            $this->error = lang('us_err_user_is_inactive');
            return FALSE;
        }
    }

//end admin_deactivation()
    //--------------------------------------------------------------------
    //===========================for social media================================
    public function soc_get_user_id_by_meta_key_value($key, $value) {
        $this->db->select('user_id')
                ->from('sn_user_meta')
                ->where(array('meta_key' => $key, 'meta_value' => $value));
        $res = $this->db->get();
        if ($res->num_rows() == 0) {
            return FALSE;
        } else {
            return array_shift(array_shift($res->result_array()));
        }
    }

    public function soc_get_user_by_email($email) {
        $this->db->from('sn_users')
                ->where('email', $email);
        $res = $this->db->get();
        if ($res->num_rows() == 0) {
            return FALSE;
        } else {
            return array_shift($res->result_array());
        }
    }

    public function soc_insert($data = array()) {
        if (!is_array($data) || empty($data))
            return FALSE;

//        echo "<pre>";
//        print_r($data);
//        echo "</pre>";
//        die();
        
        if (!isset($data['meta_key']) || !isset($data['meta_value']))
            return FALSE;
        $meta_key = $data['meta_key'];
        $meta_val = $data['meta_value'];
        $pass = $data['password'];
        unset($data['meta_key']);
        unset($data['meta_value']);
        unset($data['password']);
        //check if given user is already registered
        $id = $this->soc_get_user_id_by_meta_key_value($meta_key, $meta_val);
        if ($id) {
            $user = $this->find_user_and_meta($id);
            $user_data = array();
            $user_data['id'] = $user->id;
            $user_data['name'] = $user->display_name;
            $user_data['email'] = "";
            if ($user->email) {
                $user_data['email'] = $user->email;
            }
            $user_data['image_path'] = base_url('assets/uploads/users_images/');
            $user_data['image'] = "user.png";
            if (isset($user->profile_picture) && !empty($user->profile_picture)) {
                $user_data['image'] = $user->profile_picture;
            }
            return $user_data;
        }

        list($password, $salt) = $this->hash_password($pass);

        $data['password_hash'] = $password;
        $data['salt'] = $salt;

        // What's the default role?
        if (!isset($data['role_id'])) {
            // We better have a guardian here
            if (!class_exists('Role_model')) {
                $this->load->model('roles/Role_model', 'role_model');
            }

            $data['role_id'] = $this->role_model->default_role_id();
        }

        if (isset($data['email'])) {
            $user_data = $this->soc_get_user_by_email($data['email']);
            if ($user_data) {
                $db_id = $user_data['id'];
                $meta = array();
                $meta[$meta_key] = $meta_val;
                //parent::update($db_id, $data);
                $this->save_meta_for($db_id, $meta);
                return $db_id;
            }
        }
        $data['active'] = 1;
        
        //inserting data to table..
        $meta = array();
        if (isset($data['meta_information']) && !empty($data['meta_information']) && is_array($data['meta_information'])) {
            foreach ($data['meta_information'] as $key => $value) {
                $meta[$key] = $value;
            }
            unset($data['meta_information']);
        }

        $id = parent::insert($data);

        if ($id) {
            
            $meta[$meta_key] = $meta_val;
            $this->save_meta_for($id, $meta);
        }

        Events::trigger('after_create_user', $id);
        return $id;
    }

    public function soc_set_session($id = NULL) {
        if (!$id)
            return FALSE;
        $user_data = $this->find($id);
        if (!$user_data)
            return FALSE;

        $data['user_id'] = $id;
        $data['auth_custom'] = $user_data->display_name;
        if (!function_exists('do_hash')) {
            $this->load->helper('security');
        }
        $data['user_token'] = do_hash($user_data->id . $user_data->password_hash);
        $data['identity'] = $user_data->display_name;
        $data['role_id'] = 4;
        $data['logged_in'] = TRUE;
        $data['social_media_login'] = TRUE;
        $data['name'] = $user_data->display_name;
        $data['role_name'] = $user_data->role_name;

        $this->session->set_userdata($data);
        return TRUE;
    }

    public function fb_set_profile($user_profile) {
        try {
            if (!$user_profile) {
                return FALSE;
            }
//            
//            echo "<pre>";
//            print_r($user_profile);
//            echo "</pre>";
//            die();
            
            //inserting data into database
            $data = array();
            $data['meta_key'] = 'fb_user_id';
            $data['meta_value'] = $user_profile['id'];
            $data['password'] = 'facebook';
            $data['display_name'] = $user_profile['name'];
            $data['role_id'] = 4;
            if (isset($user_profile['email'])) {
                $data['email'] = $user_profile['email'];
            }
            
            if(isset($user_profile['username'])){
                $data['username'] = $user_profile['username'];
            }
            
            //prepare  meta key value pairs...
            if(isset($user_profile['first_name'])){
                $data['meta_information']['first_name'] = $user_profile['first_name'];
            }
            
            if(isset($user_profile['last_name'])){
                $data['meta_information']['last_name'] = $user_profile['last_name'];
            }
            
            if(isset($user_profile['gender'])){
                $gender = "";
                if($user_profile['gender'] == "male"){
                    $gender = "M";
                } else if($user_profile['gender'] == "female"){
                    $gender = "F";
                }
                
                if(!empty($gender)){
                    $data['meta_information']['gender'] = $user_profile['gender'];
                }
            }
            
            if(isset($user_profile['birthday'])){
                $date = new DateTime($user_profile['birthday']);
                $data['meta_information']['dob'] = $date->format("Y-m-d");
            }
            
            if(isset($user_profile['fb_profile_image'])){
                $data['meta_information']['profile_picture'] = $user_profile['fb_profile_image'];
            }
            //end of preparing meta info.            
            

            $id = $this->user_model->soc_insert($data);
            if (!$id) {
                return FALSE;
            }
            if (is_array($id))
                $id = $id['id'];
            //setting data into session                 
            if (!$this->user_model->soc_set_session($id))
                return FALSE;

            return TRUE;
        } catch (FacebookApiException $e) {
            return FALSE;
        }
    }

    public function fb_login_url() {
        $callback_url = $this->config->item('fb_callback_url');
        return $this->fb->getLoginUrl(array(
                    'scope' => 'email, user_birthday, user_location, user_hometown',
                    'redirect_uri' => $callback_url,
        ));
    }

    public function fb_logout_url($callback_url) {
        return $this->fb->getLogoutUrl(array(
                    'next' => $callback_url
        ));
    }

    public function fb_check_user() {
        $user = $this->fb->getUser();
        if ($user) {
            return $user;
        }
        return FALSE;
    }

    public function fb_logout() {
        $this->fb->destroySession();
    }

    public function vk_set_profile() {
        $callback_url = $this->config->item('vk_callback_url');
        $access_token = $this->vk->getAccessToken($_GET['code'], $callback_url);

        $res = $this->vk->api('getProfiles', array(
            'uid' => $access_token['user_id'],
            'fields' => ' uid, first_name, last_name, nickname, domain, sex, bdate, city, country, timezone, photo, photo_medium, photo_big, has_mobile, rate, contacts, education'
        ));
        $user_profile = array_shift(array_shift($res));
        if (empty($user_profile)) {
            return FALSE;
        }
        
        if (isset($user_profile['photo_medium']) && !empty($user_profile['photo_medium']) && !empty($user_profile['uid'])) {
            $url = $user_profile['photo_medium'];
            $img = file_get_contents($url);

            if ($img) {
                $file_name = FCPATH . "assets/uploads/users_images/" . $user_profile['uid'] . '.jpg';
                if (file_put_contents($file_name, $img)) {
                    $user_profile['vk_profile_image'] = $user_profile['uid'] . '.jpg';
                    
                    //now create thumbnail.
                    $thumb_path = FCPATH . "assets" . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "users_images". DIRECTORY_SEPARATOR . "thumb";
                    create_thumbnail($file_name, $thumb_path);
                }
            }
        }
        
//        echo "<pre>";
//        print_r($user_profile);
//        echo "</pre>";
//        die();

        //inserting data into database
        $data = array();
        $data['meta_key'] = 'vk_user_id';
        $data['meta_value'] = $user_profile['uid'];
        $data['password'] = 'vk';
        $data['display_name'] = $user_profile['first_name'] . " " . $user_profile['last_name'];
        $data['role_id'] = 4;
        
        
        //prepare  meta key value pairs...
        if (isset($user_profile['first_name'])) {
            $data['meta_information']['first_name'] = $user_profile['first_name'];
        }

        if (isset($user_profile['last_name'])) {
            $data['meta_information']['last_name'] = $user_profile['last_name'];
        }

        if (isset($user_profile['sex']) && ($user_profile['sex'] != 0)) {
            $gender = "";
            if ($user_profile['sex'] == 1) {
                $gender = "F";
            } else if ($user_profile['sex'] == 2) {
                $gender = "M";
            }
            if (!empty($gender)) {
                $data['meta_information']['gender'] = $gender;
            }
        }

        if (isset($user_profile['bdate'])) {
            $date = new DateTime($user_profile['bdate']);
            $data['meta_information']['dob'] = $date->format("Y-m-d");
        }

        if (isset($user_profile['vk_profile_image'])) {
            $data['meta_information']['profile_picture'] = $user_profile['vk_profile_image'];
        }
        //end of preparing meta info.     
        
        
        
        $id = $this->soc_insert($data);
        if (!$id) {
            return FALSE;
        }
        if (is_array($id))
            $id = $id['id'];
        //setting data into session            
        if (!$this->soc_set_session($id))
            return FALSE;

        return TRUE;
    }

    public function vk_login_url() {
        $callback_url = $this->config->item('vk_callback_url');
        $authorize_url = $this->vk->getAuthorizeURL(
                'uid, first_name, last_name, nickname, sex, bdate, city, country, timezone', $callback_url);
        return $authorize_url;
    }

    public function tw_login() {
        $this->twitt->twitter_login();
    }

    public function gp_login() {
        $this->gp->google_login();
    }

    public function gp_set_profile() {
        $code = $this->input->get("code");
        if ($code != FALSE) {
            $user_profile = $this->gp->google_profile($code);
            if (!empty($user_profile)) {
                //inserting data into database
//                
//                echo "<pre>";
//                print_r($user_profile);
//                echo "</pre>";
//                die();
                
                //getting image from google plus.
                
                
                if (isset($user_profile['image']['url']) && !empty($user_profile['image']['url']) && !empty($user_profile['id'])) {
                    $url = $user_profile['image']['url'];
                    $img = file_get_contents($url);

                    if ($img) {
                        $file_name = FCPATH . "assets/uploads/users_images" . "/" . $user_profile['id'] . '.jpg';
                        if (file_put_contents($file_name, $img)) {
                            $user_profile['gp_profile_image'] = $user_profile['id'] . '.jpg';
                            
                            //now create thumbnail.
                            $thumb_path = FCPATH . "assets" . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "users_images". DIRECTORY_SEPARATOR . "thumb";
                            create_thumbnail($file_name, $thumb_path);
                        }
                    }
                }
                
                
                
                
                
                $data = array();
                $data['meta_key'] = 'gp_user_id';
                $data['meta_value'] = $user_profile['id'];
                $data['password'] = 'tw';
                $data['display_name'] = $user_profile['name']['givenName'] . " " . $user_profile['name']['familyName'];
                $data['role_id'] = 4;
                
                
                //preparint meta key value pairs...
                if (isset($user_profile['name']['familyName'])) {
                    $data['meta_information']['last_name'] = $user_profile['name']['familyName'];
                }

                if (isset($user_profile['name']['givenName'])) {
                    $data['meta_information']['first_name'] = $user_profile['name']['givenName'];
                }
                
                if (isset($user_profile['birthday'])) {
                    $date = new DateTime($user_profile['birthday']);
                    $data['meta_information']['dob'] = $date->format("Y-m-d");
                }

                if (isset($user_profile['gender'])) {
                    $gender = "";
                    if ($user_profile['gender'] == "male") {
                        $gender = "M";
                    } else if ($user_profile['gender'] == "female") {
                        $gender = "F";
                    }

                    if (!empty($gender)) {
                        $data['meta_information']['gender'] = $user_profile['gender'];
                    }
                }

//                if (isset($user_profile['birthday'])) {
//                    $date = new DateTime($user_profile['birthday']);
//                    $data['meta_information']['dob'] = $date->format("Y-m-d");
//                }

                if (isset($user_profile['gp_profile_image'])) {
                    $data['meta_information']['profile_picture'] = $user_profile['gp_profile_image'];
                }
                //end of preparing meta info.
                
                
                
                $id = $this->soc_insert($data);
                if (!$id) {
                    return FALSE;
                }
                if (is_array($id))
                    $id = $id['id'];
                //setting data into session            
                if (!$this->soc_set_session($id)) {
                    return FALSE;
                }
                return TRUE;
            }
        }
        return FALSE;
    }

    public function tw_set_profile() {
        $oauth_verifier = $this->input->get("oauth_verifier");
        $oauth_token = $this->session->userdata("twitter_oauth_token");
        $oauth_token_secret = $this->session->userdata("twitter_oauth_token_secret");

        if (!empty($oauth_verifier) && !empty($oauth_token) && !empty($oauth_token_secret)) {
            $user_profile = $this->twitt->twitter_profile($oauth_verifier);
            if (!empty($user_profile)) {
                
                
//                echo "<pre>";
//                print_r($user_profile);
//                echo "</pre>";
//                die();
                
                if (isset($user_profile->profile_image_url) && !empty($user_profile->profile_image_url) && !empty($user_profile->id)) {
                    $url = $user_profile->profile_image_url;
                    $img = file_get_contents($url);

                    if ($img) {
                        $file_name = FCPATH . "assets/uploads/users_images/" . $user_profile->id . '.jpg';
                        if (file_put_contents($file_name, $img)) {
                            $profile_img = $user_profile->id . '.jpg';
                            
                            //now create thumbnail.
                            $thumb_path = FCPATH . "assets" . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "users_images". DIRECTORY_SEPARATOR . "thumb";
                            create_thumbnail($file_name, $thumb_path);
                        }
                    }
                }
                
                
                //inserting data into database
                $data = array();
                $data['meta_key'] = 'tw_user_id';
                $data['meta_value'] = $user_profile->id;
                $data['password'] = 'tw';
                $data['display_name'] = $user_profile->name;
                $data['role_id'] = 4;
                
                //Edited 
                if (isset($user_profile->screen_name) && !empty($user_profile->screen_name)) {
                    $data['username'] = $user_profile->screen_name;
                }
                
                //prepare  meta key value pairs...

                if (isset($profile_img)) {
                    $data['meta_information']['profile_picture'] = $profile_img;
                }
                
                if (isset($user_profile->name) && !empty($user_profile->name)) {
                    $name = explode(" ", trim($user_profile->name, " "));
                    if (count($name) > 0) {
                        $first_name = $name[0];
                        $last_name = "";
                        for ($j = 0; $j < count($name); $j++) {
                            if ($j != 0) {
                                $last_name .= $name[$j] . " ";
                            }
                        }
                        $data['meta_information']['first_name'] = $first_name;
                        $data['meta_information']['last_name'] = $last_name;
                    } else {
                        $data['meta_information']['first_name'] = $user_profile->name;
                    }
                }
                //end of preparing meta info.     



                $id = $this->soc_insert($data);
                if (!$id) {
                    return FALSE;
                }
                if (is_array($id))
                    $id = $id['id'];
                //setting data into session            
                if (!$this->soc_set_session($id)) {
                    return FALSE;
                }
                return TRUE;
            }
        }
        return FALSE;
    }

    //===========================end social media================================
}

//end User_model
