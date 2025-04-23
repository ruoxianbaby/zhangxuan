<?php

namespace Trawind\VerifyPermission;

use Exception;
use GuzzleHttp\Client;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;
use Trawind\Helpers\HttpClientHelper;
use Illuminate\Support\Facades\Auth;
use Trawind\Helpers\UserHelper;
use Trawind\Basics\Exceptions\ForbiddenException;
use Trawind\Workflows\Models\Microservices;

/**
 * Verify Permission
 */
class VerifyPermission
{
    const PERMISSION_SERVICE_NAME = 'user';

    const ACCOUNT_SERVICE_NAME = 'account';

    const GET_PERMISSION_PATH = 'permissions/permissions';

    const GET_ROLES_PATH = 'roles/';

    const GET_USER_ACCOUNT_INFO_PATH = 'accounts/get-user-account-info';

    const DATA_PERMISSIONS_PATH = 'organization/employees/';
    /**
     * Access control lists.
     * 
     * @var array
     */
    public $acls = [];

    /**
     * current resource permission Policy
     *
     * @var array
     */
    public $permissionPolicy = [];
    /**
     * Current login role.
     *
     * @var string
     */
    public $role = '';

    /**
     * All resource lists.
     *
     * @var array
     */
    public $resources = [];

    /**
     * All operation permissions.
     *
     * @var array
     */
    public $operations = [];

    /**
     * microservicesId
     *
     * @var integer
     */
    public $microservicesId = 0;

    public function __construct(int $microservicesId = 0)
    {
        $this->microservicesId = $microservicesId;
        $this->user();
    }

    public function ignoreVerify()
    {
        if ($this->isRootUser())
            return true;
        if ($this->internalRequest())
            return true;
        // if runningInConsole don't check permissions
        if (runningInConsole())
            return true;
        // if route prefix eq workflow-execute don't check permissions
        try {
            if (app("route_prefix") == 'workflow-execute') {
                return true;
            }
        } catch (\Throwable $th) {
        }
    }

    public function internalRequest()
    {
        $RequestServiceName = Request::header('MS-Request-ServiceName', null);
        if ($RequestServiceName)
            return true;
        return false;
    }

    public function isRootUser()
    {
        if (($this->user()['data']['is_root_user_type_id'] ?? null) == 11)
            return true;
        return false;
    }

    public function test()
    {
    }

    /**
     * make Domain
     */
    protected function makeDomain($service = self::PERMISSION_SERVICE_NAME)
    {
        $microservice = Microservices::where(['name' => $service])->first();
        $domain = $microservice->domain;
        $appEnv = config('app.env');
        $port = Request::getPort();
        $tenantId = UserHelper::tenantId();
        $tenantStr = $tenantId . '.';
        return "{$tenantStr}{$appEnv}." . $domain . ($port ? ':' . $port : '') . '/';
    }

    public function permissions()
    {
        // $permissions = $this->getConst('permissions');
        $permissions = $this->getPermissions();
        // dd($permissions);
        if ($permissions) return $permissions;
        $permissions = $this->requestPermissions();
        // $this->setConst('permissions', $permissions);
        $this->setPermissions($permissions);
        return $permissions;
    }

    public function getPermissions()
    {
        if (! $this->userExists())
            return null;
        $user_id = UserHelper::userId();
        // dd($user_id, 111);
        return unserialize(RedisManager()->get("operationPermissions:{$user_id}"));
    }

    public function setPermissions($permissions)
    {
        $user_id = UserHelper::userId();
        RedisManager()->setex("operationPermissions:{$user_id}", 60 * 60 * 24, serialize($permissions));
    }

    public function verifyDataPermission(Model $model)
    {
        if (!$model->subsidiary_id && !$model->department_id && !$model->warehouse_id)
            return true;
        $dataPermissions = $this->dataPermissions()['organization']['data'] ?? [];
        if (!$dataPermissions) return false;
        foreach ($dataPermissions as $dataPermission) {
            if ($dataPermission['type_id'] == 10 && $dataPermission['id'] == $model->subsidiary_id)
                return true;
            if ($dataPermission['type_id'] == 11 && $dataPermission['id'] == $model->department_id)
                return true;
            if ($dataPermission['type_id'] == 12 && $dataPermission['id'] == $model->warehouse_id)
                return true;
        }
        throw new ForbiddenException(__('Invalid permission'));
        $role = $this->requestRole(UserHelper::userInfo()['last_signin_role_id']);
        // return $dataPermissions;
    }

    public function dataPermissions()
    {
        $dataPermissions = $this->getConst('dataPermissions');
        if ($dataPermissions) return $dataPermissions;
        $dataPermissions = $this->requestDataPermissions();
        $this->setConst('dataPermissions', $dataPermissions);
        return $dataPermissions;
    }

    public function userExists()
    {
        $user = $this->getConst('user');
        if ($user)
            return $user;
        $RequestUserInfo = json_decode(((Request::header('MS-Request-UserInfo', null))), true);
        if ($RequestUserInfo)
            $user = ['data' => $RequestUserInfo];
        return $user;
    }

    public function user()
    {
        $user = $this->userExists();
        if ($user)
            return $user;
        else
            $user = $this->requestUser();
        $this->setConst('user', $user);
        if(gettype($user) == 'array')
            $user = $user[0] ?? null;
        return $user;
    }

    public function getConst($type = null)
    {
        try {
            return app("verifyPermission_{$type}");
        } catch (\Throwable $th) {
            return null;
        }
    }

    public function setConst($type = null, $data)
    {
        app()->bind("verifyPermission_{$type}", function () use ($data) {
            return $data;
        });
    }

    public function requestUser()
    {
        $options = [
            'headers' => $this->requestHeaders(),

        ];
        $method = 'GET';
        $uri = $this->makeDomain() . self::GET_USER_ACCOUNT_INFO_PATH;
        $response = HttpClientHelper::factory()->client('user')->request($method, $uri, $options);
        return json_decode($response->getBody(), true);
    }

    protected function requestHeaders()
    {
        return [
            'Accept' => 'application/json',
            'Authorization' => UserHelper::token()
        ];
    }

    public function requestRole($id)
    {
        $options = [
            'headers' => $this->requestHeaders()
        ];
        $method = 'GET';
        $path = self::GET_ROLES_PATH . $id;
        $uri = $this->makeDomain() . $path;
        $response = HttpClientHelper::factory()->client()->request($method, $uri, $options);
        return json_decode($response->getBody(), true)['data']['data'] ?? null;
    }

    public function requestDataPermissions()
    {
        $options = [
            'headers' => $this->requestHeaders()
        ];
        $method = 'GET';
        $path = self::DATA_PERMISSIONS_PATH . (UserHelper::userInfo()['person_id'] ?? 0) . '?include=organization';
        $uri = $this->makeDomain(self::ACCOUNT_SERVICE_NAME) . $path;
        $response = HttpClientHelper::factory()->client()->request($method, $uri, $options);
        return json_decode($response->getBody(), true)['data']['data'] ?? null;
    }

    public function requestPermissions()
    {
        $options = [
            'headers' => $this->requestHeaders()
        ];
        $method = 'GET';
        $uri = $this->makeDomain() . self::GET_PERMISSION_PATH;
        $response = HttpClientHelper::factory()->client('user')->request($method, $uri, $options);
        return json_decode($response->getBody(), true)['data']["resource_permissions"] ?? null;
    }

    /**
     * set ACL Via Header
     *
     * @return boolean
     */
    protected function setACLViaHeader()
    {
        $permissions = Request::header('Permissions');
        $this->acls = json_decode(urldecode($permissions), true);
        return $this->acls;
    }

    /**
     * set All Resource Permissions
     *
     * @return array
     */
    public function setAllResourcePermissions()
    {
        foreach ($this->acls as $acl) {
            array_push($this->resources, $acl['resource'] ?? null);
        }
        return $this->resources;
    }

    /**
     * get All Resource Permissions
     *
     * @return array
     */
    public function getAllResourcePermissions()
    {
        return $this->resources;
    }

    protected function mergeMicroservicesId(int $microservicesId): int
    {
        return $microservicesId ? $microservicesId : $this->microservicesId;
    }

    /**
     * has Resouce Permission
     *
     * @param string $resource
     * @param integer $microservicesId
     * @return boolean
     */
    public function hasResoucePermission(string $resource, int $microservicesId = 0): bool
    {
        $microservicesId = $this->mergeMicroservicesId($microservicesId);
        foreach (($this->acls ?? []) as $acl) {
            if ($acl['resource']['controller_identifier'] == $resource && ($microservicesId ? $acl['resource']['microservices_id'] == $microservicesId : true)) {
                $this->permissionPolicy = $acl;
                return true;
            }
        }
        return false;
    }

    /**
     * has Operation Permission
     *
     * @param string $resource
     * @param string $operation
     * @param integer $microservicesId
     * @return boolean
     */
    public function hasOperationPermission(string $resource, string $operation, int $microservicesId = 0): bool
    {
        $hasResoucePermission = $this->hasResoucePermission($resource, $microservicesId);
        if (!$hasResoucePermission) return false;

        foreach ($this->permissionPolicy['operation_permissions'] as $operationItem) {
            if ($operationItem['resource_operation']['action_identifier'] == $operation) return true;
        }
        return false;
    }

    /**
     * get Data Permissions
     *
     * @param string $resource
     * @param integer $microservicesId
     * @return array|null
     */
    public function getDataPermissions($createId): ?array
    {
        $requestDataPermissions = $this->requestDataPermissions();

        return [];
    }



    /**
     * get Field Permissions
     *
     * @param string $resource
     * @param integer $microservicesId
     * @return array|null
     */
    public function getFieldPermissions(string $resource, int $microservicesId = 0)
    {
        $hasResoucePermission = $this->hasResoucePermission($resource, $microservicesId);
        if (!$hasResoucePermission) return false;
        return $this->permissionPolicy['fields'] ?? null;
    }

    /**
     * has Field Permission
     *
     * @param string $resource
     * @param string $field
     * @param integer $range 10 readonly,11 read and write, 12 unvistable.
     * @param integer $microservicesId
     * @return boolean
     */
    public function hasFieldPermission(string $resource, string $field, int $range, int $microservicesId = 0): bool
    {
        $fieldPermissions = $this->getFieldPermissions($resource, $microservicesId);
        if (!$fieldPermissions) return false;

        foreach ($fieldPermissions as $fieldPermission) {
            if ($fieldPermission['field_name'] == $field && $fieldPermission['permission_type_id'] == $range) return true;
        }
        return false;
    }
}
