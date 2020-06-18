<?php

use Jasny\ValidationResult;
use Jasny\SSO\Server;
use Desarrolla2\Cache\Cache;
use Desarrolla2\Cache\Adapter;

class NotFoundException extends \Exception {}
class BadRequestException extends \Exception {}
class InternalServerException extends \Exception {}

class MySSOServer extends Server {

    private $db;

    public function __construct(array $options = []) {
        parent::__construct($options);
        $this->db = new Database();
    }

    protected function createCacheAdapter()
    {
        $adapter = new Adapter\Memcached();
        return new Cache($adapter);
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

        $result = $this->db->select("SELECT * FROM users WHERE username = '$username' AND users.`status` = 'enabled'");
        $user = mysqli_fetch_array($result, MYSQLI_ASSOC);

        if ($user['username'] !== $username) {
            return ValidationResult::error("invalid username");
        }

        // generate pass
        // password_hash('123456', PASSWORD_DEFAULT);

        if (!password_verify($password, $user['password'])) {
            return ValidationResult::error('invalid password');
        }

        return ValidationResult::success();
    }

    protected function getUserInfo($username) {
        // $result = $this->db->select("SELECT users.user_id, users.username, users.name, users.nip, apps.app_id, apps.app_url, apps.app_name, apps.app_desc, apps.app_style FROM users_roles RIGHT JOIN users ON users_roles.user_id = users.user_id LEFT JOIN apps ON users_roles.app_id = apps.app_id WHERE users.username = '$username'");
        // grab user data first
        $qUserInfo = $this->db->select("SELECT a.user_id, a.username, a.name, a.nip, a.pangkat, b.kode, b.posisi, b.tempat, a.`status` FROM users a INNER JOIN ref_posisi b ON a.penempatan = b.kode WHERE a.username = '$username' AND a.`status` = 'enabled'");
        $r = mysqli_fetch_assoc($qUserInfo);

        // store in userInfo
        $userInfo = $r;
        // convert some data
        $userInfo['status'] = $userInfo['status'] == "enabled";
        $userInfo['apps_data'] = [];

        // grab user's roles in this
        $result = $this->db->select("SELECT users_roles.role_id, apps.app_id, apps.app_name, apps.app_style, apps.app_desc, apps.app_url, roles.role_name, roles.role
                                    FROM users_roles
                                    RIGHT JOIN users ON users_roles.user_id = users.user_id
                                    LEFT JOIN roles ON users_roles.role_id = roles.role_id
                                    LEFT JOIN apps ON roles.app_id = apps.app_id
                                    WHERE users.username = '$username' AND roles.`status` = 'enabled'");

        // append role data into userInfo
        while ($row = mysqli_fetch_assoc($result)) {
            $userInfo['apps_data'][$row['app_id']] = $userInfo['apps_data'][$row['app_id']] ?? [
                'app_name'  => $row['app_name'],
                'app_style' => $row['app_style'],
                'app_desc' => $row['app_desc'],
                'app_url' => $row['app_url'],
                'roles'     => []
                
            ];
            $userInfo['apps_data'][$row['app_id']]['roles'][] = $row['role_name'];
            $userInfo['apps_data'][$row['app_id']]['rolex'][] = $row['role'];
        }
        return $userInfo;
    }

    // ===============================================================================================================
    // CUSTOM COMMAND
    // ===============================================================================================================
    // grab user data by role
    public function userByRole() {
        try {
            $data = $this->queryUserByRole($_REQUEST[0], $_REQUEST[1]);

            header('Content-type: application/json');
            echo json_encode([
                'data' => $data
            ]);
            exit();
        } catch (\Exception $e) {
            return $this->fail($e->getMessage(), $e->getCode());
        }
    }

    // grab user by specific id
    public function userById() {
        try {
            // strip parameter from request data
            $uid = $_REQUEST[0];
            $activeOnly = $_REQUEST[1] ?? false;

            $data = $this->queryUserById($uid, $activeOnly);

            header('Content-type: application/json');
            echo json_encode([
                'data' => $data
            ]);
            exit();
        } catch (\Exception $e) {
            return $this->fail($e->getMessage(), $e->getCode());
        }
    }

    // ===============================================================================================================
    // PROTECTED MEMBERS
    // ===============================================================================================================
    protected function queryUserByRole($roles, $strict = false) {
        // user must be sober to use this
        if (!is_array($roles)) {
            throw new BadRequestException("Bad request bitch!", 400);
            return null;
        }

        // flatten the array to quoted list of shiets
        $role_flat = implode(",", array_map(function ($e) { return "'{$e}'"; }, $roles));

        // build query string
        $qString = "
        SELECT
            c.user_id,
            c.username,
            c.name,
            c.nip,
            c.pangkat,
            c.penempatan,
            c.status
        FROM
            users_roles a
            JOIN
            users c 
            ON
                a.user_id = c.user_id
        WHERE
            c.`status` <> 0
            AND
            a.`status` <> 0
            AND
            a.role_id IN (
                SELECT
                    b.role_id
                FROM
                    roles b
                WHERE
                    b.role IN ({$role_flat})
            )
        ";

        $result = $this->db->select($qString);

        if ($result === false) {
            throw new NotFoundException("No data was found in the query", 404);
            return null;
        }

        // must have succeed, go for it
        // $data = $this->db->fetch()
        $data = [];

        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = $row;
        }

        return $data;
    }

    protected function queryUserById($id, $activeOnly = false) {
        if (!isset($id)) {
            throw new BadRequestException("Bad request bitch");
            return null;
        }
        // escape parameter
        $uid = mysqli_escape_string($this->db->link, $id);

        // return $uid;

        // build query
        $qString = "
        SELECT
            c.user_id,
            c.username,
            c.name,
            c.nip,
            c.pangkat,
            c.penempatan,
            c.status
        FROM
            users c
        WHERE
            c.user_id = $uid
        ";

        if ($activeOnly) {
            $qString .= " AND c.status = 'enabled'";
        }

        // return $qString;

        // execute query
        $result = $this->db->select($qString);

        if ($result === false) {
            throw new NotFoundException("No user with id #{$id}", 404);
            return null;
        }

        $data = [];

        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = $row;
        }

        return $data;
    }
}