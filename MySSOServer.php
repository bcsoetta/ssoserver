<?php

use Jasny\ValidationResult;
use Jasny\SSO;

class MySSOServer extends SSO\Server {

    private $db;

    public function __construct(array $options = []) {
        parent::__construct($options);
        $this->db = new Database();
    }

    protected function getBrokerInfo($brokerId) {
        // $result = $this->db->select("SELECT apps.app_id, apps.secret FROM users_roles INNER JOIN apps ON users_apps.app_id = apps.app_id WHERE apps.app_id = '$brokerId'");
        $result = $this->db->select("SELECT app_id, secret FROM apps WHERE app_id = '$brokerId'");
        
        $brokers = mysqli_fetch_array($result, MYSQLI_ASSOC);

        if ($brokers > 0) {
            return $brokers;
        } else {
            return null;
        }
    }

    protected function authenticate($username, $password) {
        if (!isset($username)) {
            return ValidationResult::error("username isn't set");
        }
        if (!isset($password)) {
            return ValidationResult::error("password isn't set");
        }

        $result = $this->db->select("SELECT * FROM users WHERE username = '$username'");
        $user = mysqli_fetch_array($result, MYSQLI_ASSOC);

        if ($user['username'] !== $username) {
            return ValidationResult::error("Invalid username!");
        }

        // generate pass
        // password_hash('123456', PASSWORD_DEFAULT);

        if (!password_verify($password, $user['password'])) {
            return ValidationResult::error('Invalid password');
        }

        return ValidationResult::success();
    }

    protected function getUserInfo($username) {
        // $result = $this->db->select("SELECT users.user_id, users.username, users.name, users.nip, apps.app_id, apps.app_url, apps.app_name, apps.app_desc, apps.app_style FROM users_roles RIGHT JOIN users ON users_roles.user_id = users.user_id LEFT JOIN apps ON users_roles.app_id = apps.app_id WHERE users.username = '$username'");
        // grab user data first
        $qUserInfo = $this->db->select("SELECT user_id, username, name, nip, pangkat, `status` FROM users WHERE users.username = '$username'");
        $r = mysqli_fetch_assoc($qUserInfo);

        // store in userInfo
        $userInfo = $r;
        // convert some data
        $userInfo['status'] = $userInfo['status'] == "TRUE";
        $userInfo['apps_data'] = [];

        // grab user's roles in this
        $result = $this->db->select("SELECT users_roles.role_id, apps.app_id, apps.app_name, roles.role_name
                                    FROM users_roles
                                    RIGHT JOIN users ON users_roles.user_id = users.user_id
                                    LEFT JOIN roles ON users_roles.role_id = roles.role_id
                                    LEFT JOIN apps ON roles.app_id = apps.app_id
                                    WHERE users.username = '$username'");

        // append role data into userInfo
        while ($row = mysqli_fetch_assoc($result)) {
            $userInfo['apps_data'][$row['app_id']] = $userInfo['apps_data'][$row['app_id']] ?? [
                'app_name'  => $row['app_name'],
                'roles'     => []
            ];
            $userInfo['apps_data'][$row['app_id']]['roles'][] = $row['role_name'];
        }
        return $userInfo;
    }
}