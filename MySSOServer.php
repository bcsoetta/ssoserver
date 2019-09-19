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
        $result = $this->db->select("SELECT apps.app_id, apps.secret FROM users_apps INNER JOIN apps ON users_apps.app_id = apps.app_id WHERE apps.app_id = '$brokerId'");
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
        $result = $this->db->select("SELECT users.user_id, users.username, users.name, users.nip, apps.app_id, apps.app_url, apps.app_name, apps.app_desc, apps.app_style FROM users_apps RIGHT JOIN users ON users_apps.user_id = users.user_id LEFT JOIN apps ON users_apps.app_id = apps.app_id WHERE users.username = '$username'");
        $userInfo = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $userInfo['username'] = $row['username'];
            $userInfo['user_id'] = $row['user_id'];
            $userInfo['name'] = $row['name'];
            $userInfo['nip'] = $row['nip'];

            if ($row['app_id'] !== null) {
                $userInfo['appsid'] = $row['app_id'];
            }
            
            $userInfo['apps'][] = $row;
        }
        return $userInfo;
    }
}