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
        $adapter->setOption('ttl', $this->options['files_cache_ttl']);
        return new Cache($adapter);
    }

    protected function getBrokerInfo($brokerId) {
        $result = $this->db->select("SELECT app_id, `secret` FROM aplikasi WHERE app_id = '$brokerId'");
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

        $result = $this->db->select("SELECT * FROM auth WHERE username = '$username' AND `status` = 'enabled'");
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

        $r = $this->db->select("SELECT `group` FROM auth WHERE username = '$username'");
        list($group) = mysqli_fetch_array($r);

        $userInfo = [];

        // this is for customer
        if ($group == 'customer') {
            $qUser = $this->db->select("SELECT a.user_id, a.username, a.`group`, a.`status`, b.nama name, b.email, b.phone, b.nik, b.nib, b.tanggal_nib, b.npwp, b.perusahaan, b.alamat FROM auth a INNER JOIN customer b ON a.user_id = b.user_id WHERE a.username = '$username' AND a.`status` = 'enabled'");

            $userInfo = mysqli_fetch_assoc($qUser);

            $userInfo['status'] = $userInfo['status'] == 'enabled';
            $userInfo['apps_data'] = [];

            $qApp = $this->db->select("SELECT a.role_id, d.app_id, d.name app_name, d.style app_style, d.description app_desc, d.url app_url, d.sso app_sso, c.role_name, c.role FROM user_role a INNER JOIN auth x ON a.user_id = x.user_id RIGHT JOIN customer b ON a.user_id = b.user_id LEFT JOIN role c ON a.role_id = c.role_id LEFT JOIN aplikasi d ON c.app_id = d.app_id WHERE x.username = '$username' AND c.`status` = 'enabled'");

            if ($qApp === false) {
                $userInfo['apps_data'] = null;
            } else {
                if (mysqli_num_rows($qApp) > 0) {
                    while ($d = mysqli_fetch_assoc($qApp)) {
                        $userInfo['apps_data'][$d['app_id']] = $userInfo['apps_data'][$d['app_id']] ?? [
                            'app_name' => $d['app_name'],
                            'app_style' => $d['app_style'],
                            'app_desc' => $d['app_desc'],
                            'app_url' => $d['app_url'],
                            'app_sso' => $d['app_sso'],
                            'roles' => []
                        ];
                        $userInfo['apps_data'][$d['app_id']]['roles'][] = $d['role_name'];
                        $userInfo['apps_data'][$d['app_id']]['rolex'][] = $d['role'];
                    }
                }
            }
        }

        // this is for beacukai
        if ($group == 'beacukai') {
            $qUser = $this->db->select("SELECT a.user_id, a.username, b.nama name, b.nip, b.pangkat, b.posisi kode, c.posisi, c.tempat, a.`group`, a.`status` FROM auth a INNER JOIN beacukai b ON a.user_id = b.user_id INNER JOIN ref_posisi c ON b.posisi = c.kode WHERE a.username = '$username' AND a.`status` = 'enabled'");

            $userInfo = mysqli_fetch_assoc($qUser);

            $userInfo['status'] = $userInfo['status'] == 'enabled';
            $userInfo['apps_data'] = [];

            $qApp = $this->db->select("SELECT a.role_id, d.app_id, d.name app_name, d.style app_style, d.description app_desc, d.url app_url, d.sso app_sso, c.role_name, c.role FROM user_role a INNER JOIN auth x ON a.user_id = x.user_id RIGHT JOIN beacukai b ON a.user_id = b.user_id LEFT JOIN role c ON a.role_id = c.role_id LEFT JOIN aplikasi d ON c.app_id = d.app_id WHERE x.username = '$username' AND c.status = 'enabled'");

            if ($qApp === false) {
                $userInfo['apps_data'] = null;
            } else {
                if (mysqli_num_rows($qApp) > 0) {
                    while ($d = mysqli_fetch_assoc($qApp)) {
                        $userInfo['apps_data'][$d['app_id']] = $userInfo['apps_data'][$d['app_id']] ?? [
                            'app_name' => $d['app_name'],
                            'app_style' => $d['app_style'],
                            'app_desc' => $d['app_desc'],
                            'app_url' => $d['app_url'],
                            'app_sso' => $d['app_sso'],
                            'roles' => []
                        ];
                        $userInfo['apps_data'][$d['app_id']]['roles'][] = $d['role_name'];
                        $userInfo['apps_data'][$d['app_id']]['rolex'][] = $d['role'];
                    }
                }
            }
        }
        
        return $userInfo;
    }

    // custom queries
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

    public function userById() {
        try {
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

    protected function queryUserByRole($roles, $strict = false) {
        if (!is_array($roles)) {
            throw new BadRequestException("Bad reqest!", 400);
            return null;
        }
        $role_flat = implode(",", array_map(function($e) {return "'{$e}'"; }, $roles));
        $qString = "
        SELECT
            c.user_id,
            b.username,
            c.nama name,
            c.nip,
            c.pangkat,
            c.posisi penempatan,
            b.status
        FROM
            user_role a
            INNER JOIN auth b
            ON
                a.user_id = b.user_id
            JOIN
            beacukai c
            ON
                a.user_id = c.user_id
        WHERE
            b.status <> 0
            AND
            a.status <> 0
            AND
            a.role_id IN (
                SELECT d.role_id FROM role d WHERE d.role IN({$role_flat})
            )
        ";
        $result = $this->db->select($qString);
        if ($result === false) {
            throw new NotFoundException("No data as found the the query", 404);
            return null;
        }
        $data = [];

        while ($row = mysqli_fetch_assoc($result)) {
            $row['user_id'] = (int) $row['user_id'];
            $data[] = $row;
        }
        return $data;
    }

    protected function queryUserById($id, $activeOnly = false) {
        if (!isset($id)) {
            throw new BadRequestException("Bad reqest!", 400);
            return null;
        }
        $uid = mysqli_escape_string($this->db->link, $id);
        $qString = "
            SELECT
                a.user_id,
                a.username,
                c.nama name,
                c.nip,
                c.pangkat,
                c.posisi penempatan,
                a.status
            FROM
                beacukai c
                INNER JOIN
                    auth a ON c.user_id = a.user_id
            WHERE a.user_id = $uid
        ";
        if ($activeOnly) {
            $qString .= " AND a.status = 'enabled'";
        }
        $result = $this->db->select($qString);
        if ($result === false) {
            throw new NotFoundException("No user with id #{$id}", 404);
            return null;
        }
        $data = [];

        while ($row = mysqli_fetch_assoc($result)) {
            $row['user_id'] = (int) $row['user_id'];
            $data[] = $row;
        }
        return $data;

    }
}
