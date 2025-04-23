<?php

namespace Trawind\VerifyPermission\Scopes;

use Trawind\Helpers\UserHelper;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Eloquent\Builder;
use Trawind\VerifyPermission\VerifyPermission;
use Trawind\Remotes\Repositories\User\UserRepositoryEloquent;
use Trawind\Remotes\Repositories\User\RolesRepositoryEloquent;
use Trawind\Remotes\Repositories\Account\Account\EmployeeRepositoryEloquent;
use Trawind\Remotes\Repositories\Account\Account\DepartmentRepositoryEloquent;

class DataPermissionScope implements Scope
{
    /**
     * Specifies the relationship or relationships used for data permission filtering.
     * Can be a string representing a single relationship or an array of multiple relationships.
     *
     * @var string|array
     */
    public $dataPermissionsRelation = '';

    /**
     * The model instance.
     *
     * @var \Illuminate\Database\Eloquent\Model
     */
    protected $model;
    /**
     * Holds custom field mappings for specific models.
     * This is used to define additional fields for data permission checks.
     *
     * @var array
     */
    public $customizeField = [];
    /**
     * Stores the role's business operation ranges, which define the scope of data access
     * based on the business type and the role's permissions. This is used to filter data
     * according to the specific operational boundaries assigned to the role.
     *
     * @var array
     */
    public $role_business_operation_ranges = [];


    /**
     * Holds cached builder data.
     *
     * @var mixed
     */
    protected $dataBuider;

    /**
     * Indicates whether the required fields do not exist in the model.
     *
     * @var bool
     */
    protected $fieldNotExists;

    /**
     * Shares type property
     *
     * @var string
     */
    public $sharesType;

    /**
     * Holds the organizations data for data permissions.
     *
     * @var array
     */
    protected $organizations = [];

    /**
     * Holds the current model field for customization.
     *
     * @var mixed
     */
    protected $currentModelField;

    /**
     * Person field property
     *
     * @var string|null
     */
    protected $personfield;

    /**
     * Role property
     *
     * @var mixed
     */
    protected $role;

    /**
     * Constructor for the DataPermissionScope class.
     *
     * @param string $dataPermissionsRelation Specifies the relationship(s) used for data permission filtering.
     * @param array $customizeField Holds custom field mappings for specific models.
     * @param string $sharesType Shares type property.
     * @param array $role_business_operation_ranges Stores the role's business operation ranges for data access.
     */
    public function __construct($dataPermissionsRelation = '', $customizeField = [], $sharesType = '', $role_business_operation_ranges = [])
    {
        $this->dataPermissionsRelation = $dataPermissionsRelation;
        $this->customizeField = $customizeField;
        $this->sharesType = $sharesType;
        $this->role_business_operation_ranges = $role_business_operation_ranges;
    }

    /**
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $builder
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function apply(Builder $builder, Model $model)
    {
        $this->model = $model;
        if ($this->meetExecuteCondition($model))
            return $builder;
        $this->dataBuider = $this->cacheBuilderData($builder);
        // make sql
        $this->builderSql($builder, $this->dataBuider);
        return $builder;
    }

    public function builderSql($builder, $dataBuider)
    {
        $builder->where(function ($builder) use ($dataBuider) {
            if ($this->dataPermissionsRelation) {
                if (is_array($this->dataPermissionsRelation)) {
                    foreach ($this->dataPermissionsRelation ?? [] as $reation) {
                        $this->buildRelationSql($builder, $reation);
                    }
                } else if (is_string($this->dataPermissionsRelation)) {
                    $this->buildRelationSql($builder, $this->dataPermissionsRelation);
                }
            }
            if (method_exists($this->model, 'shares'))
                $this->buildShare($builder, $dataBuider);
            // if shares return
            if ($this->sharesType)
                return $this->makeSharePermissionsSql($dataBuider, $builder);
            
            $builder->orWhere(function ($builder) use ($dataBuider) {
                $builder->where(function ($builder) use ($dataBuider) {
                    if (!$this->fieldNotExists)
                        $builder = $this->makeDataPermissionsSql($dataBuider['subsidiaryPermissions'], $builder);
                });
                if ($this->personfield)
                    $this->personPermissionSql($dataBuider['userIdList'], $builder, $this->personfield);
            });
            if ($this->customizeField) {
                $this->currentModelField = $this->customizeField[get_class($this->model)] ?? null;
                if ($this->currentModelField)
                    $this->customizeField($builder, $dataBuider);
            }
        });
    }

    public function buildShare($builder): void
    {
        $relation = 'shares';
        $relationModel = $this->model->$relation()->getModel();
        $relationModel::addGlobalScope(new \Trawind\VerifyPermission\Scopes\DataPermissionScope(null, [], true, $this->role_business_operation_ranges));
        $builder->orWhere(function ($builder) use ($relation) {
            $builder->whereHas($relation);
        });
        $relationModel::clearBootedModels();
    }

    public function makeSharePermissionsSql($dataBuider, $query):void
    {
        $query->orWhere(function ($query) use ($dataBuider){
            foreach ($dataBuider['subsidiaryPermissions'] ?? [] as $data) {
                $query->orWhere(function ($query) use ($data) {
                    $query->where(['organization_type_id' => 13])
                        ->where(['parent_subsidiary_id' => $data['subsidiary_id']])
                        ->where(['department_id' => $data['organization_id']]);
                });
            }
        });

        $query->orWhere(function ($query) {
            if (! ($this->dataBuider['userIdList'] ?? []))
                return $query;
            $eachField['field'] = 'user_id';
            $query->where(['organization_type_id' => 11]);
            $this->customizeUserField($query, $eachField);
        });

        // $query->orWhere(function ($query) use ($dataBuider){
        //     foreach ($dataBuider['company'] ?? [] as $company_id) {
        //         $query->orWhere(function ($query) use ($company_id) {
        //             $query->where(['organization_type_id' => 12])
        //             ->where(['organization_id' => $company_id]);
        //         });
        //     }
        // });
    }

    public function buildRelationSql($builder, $relation)
    {
        // deep relation handle
        $strpos = strpos($relation, '.');
        $currentRelation = $relation;
        $extsionRelation = null;
        if ($strpos) {
            $currentRelation = substr($relation, 0, $strpos);
            $extsionRelation = substr($relation, $strpos + 1);
        }
        $relationModel = $this->model->$currentRelation()->getModel();
        $relationModel::addGlobalScope(new \Trawind\VerifyPermission\Scopes\DataPermissionScope($extsionRelation, $this->customizeField, '', $this->role_business_operation_ranges));
        $builder->orWhere(function ($builder) use ($relation) {
            $builder->whereHas($relation);
        });
        $relationModel::clearBootedModels();
    }

    public function multiplOrganizations()
    {
        // multiple organizations info.
        $verifyPermission = new VerifyPermission();
        $dataPermissions = $verifyPermission->dataPermissions() ?? [];
        $organizations = $dataPermissions['organization']['data'] ?? [];

        // merge base organizations info,  multiple organizations info
        array_push($organizations, ['subsidiary_id' => $dataPermissions['subsidiary_id'], 'organization_id' => $dataPermissions['department_id']]); 
        return $this->dataPermissionsFormate($organizations, $this->role);
    }
    public function meetExecuteCondition($model)
    {
        // global controller variables
        if (!getNeedDataPermission())
            return true;
        // specific Action
        if ($this->specificAction())
            return true;
        // run in console return all data.
        if (runningInConsole())
            return true;
        $verifyPermission = new VerifyPermission();
        // root user return all data.
        if ($verifyPermission->isRootUser())
            return true;
        // micro services internal Request.
        if ($verifyPermission->internalRequest())
            return true;
        // fields is exists.
        $this->fieldNotExists = $this->fieldNotExists($model);
        $this->personfield = $this->personPermissionField($model);
        // $this->customizeFieldExists = $this->customizeFieldExists($model);
        // if ($this->fieldNotExists && !$this->dataPermissionsRelation && !$this->personfield)
        //     return true;
        return false;
    }

    public function cacheBuilderData($builder)
    {
        $userId = UserHelper::userId();
        $dataBuider = unserialize(RedisManager()->get("dataPermissions:dataBuider_user_id:{$userId}"));
        if ($dataBuider)
            $this->role_business_operation_ranges($dataBuider['role']);
        if ($this->role_business_operation_ranges)
            if (RedisManager()->exists("dataPermissions:role:{$dataBuider['role']['id']}:business:{$this->model->businessType}")) {
                $dataBuider = unserialize(RedisManager()->get("dataPermissions:role:{$dataBuider['role']['id']}:business:{$this->model->businessType}"));
            } else 
                $dataBuider = null;
        if (!$dataBuider) {
            $rolesRepositoryEloquent = new RolesRepositoryEloquent();
            $this->role = $rolesRepositoryEloquent->getListBySearch([
                'search' => 'id:' . UserHelper::roleId(),
                'searchJoin' => 'and',
                'searchFields' => 'id:=',
                'with' => 'RoleOperationRanges',
            ])['data'][0] ?? null;
            // not set department_data_range,employee_data_range_type_id return all data.
            if (!$this->role['department_data_range_type_id'] && !$this->role['employee_data_range_type_id'] && !$this->role['role_business_operation_ranges']) {
                return $builder;
            }
            $this->role_business_operation_ranges($this->role);
            $subsidiaryPermissions = $this->multiplOrganizations();
            $userIdList = $this->personPermission($this->role);
            $employeeList = $this->personPermission($this->role, 'employee');
            $dataBuider = ['subsidiaryPermissions' => $subsidiaryPermissions[0] ?? [], 'company' => $subsidiaryPermissions[1] ?? [], 'userIdList' => $userIdList, 'employeeList' => $employeeList, 'role' => $this->role];
            if ($this->role_business_operation_ranges)
                RedisManager()->setex("dataPermissions:role:{$dataBuider['role']['id']}:business:{$this->role_business_operation_ranges['business_type_id']}", 60 * 60 * 24, serialize($dataBuider));
            else 
                RedisManager()->setex("dataPermissions:dataBuider_user_id:{$userId}", 60 * 60 * 24, serialize($dataBuider));
        }
        return $dataBuider;
    }
    /**
     * Checks if the role has business operation ranges.
     * If not, it retrieves the role's operation ranges based on the business type.
     *
     * @param array $role The role data.
     * @return void
     */
    public function role_business_operation_ranges($role)
    {
        if (!$this->role_business_operation_ranges) {
            if ($role['role_operation_ranges'] ?? null) {
                if($this->model->businessType) {
                    foreach ($role['role_operation_ranges'] ?? [] as $role_operation_range) {
                        if ($role_operation_range["business_type_id"] == $this->model->businessType) {
                            $this->role_business_operation_ranges = $role_operation_range;
                            break;
                        }
                    }
                }
            }
        }
    }

    public function specificAction()
    {
        $controller = 'TrawindCloud\Http\Controllers\Product\PublicitysController';
        $action = 'store';
        $name = Route::currentRouteAction();
        if ($name == ($controller . '@' . $action))
            return true;
        return false;
    }

    public function personPermission($role, $type = 'user')
    {
        $persionList = [];
        $userIdList = [];
        $employee_data_range_type_id = $this->role_business_operation_ranges['employee_data_range_type_id'] ?? $role['employee_data_range_type_id'];
        if ($employee_data_range_type_id == 10) {
            return $userIdList;
        }
        // 11 onlyself
        else if ($employee_data_range_type_id == 11) {
            array_push($userIdList, UserHelper::userId());
            array_push($persionList, UserHelper::userInfo()['person_id']);
        } else if ($employee_data_range_type_id == 12) {
            $employeeRepositoryEloquent = new EmployeeRepositoryEloquent();
            $subordinates = $employeeRepositoryEloquent->subordinate(UserHelper::userInfo()['person_id']);

            $this->subordinatesRecursion($subordinates, $persionList, false);
            // without subordinates;
            if (count($persionList) <= 1) {
                array_push($userIdList, UserHelper::userId());
            }

            $userRepositoryEloquent = new UserRepositoryEloquent();
            //userPerson.
            $users = $userRepositoryEloquent->getListBySearch([
                'search' => 'person_type_id:10;person_id:' . implode(',', $persionList),
                'searchJoin' => 'and',
                'searchFields' => 'person_id:in',
            ]);
            if ($users && ($users['data'] ?? null)) {
                array_push($userIdList, ...array_column($users['data'], 'id'));
            }
        }
        if ($type == 'user')
            return $userIdList;
        else if ($type == 'employee')
            return $persionList;
    }

    public function subordinatesRecursion($subordinate, &$persionList, $selfExists = false)
    {
        if (!$selfExists) {
            if (UserHelper::userInfo()['person_id'] == $subordinate['id'])
                $selfExists = true;
        }
        if ($selfExists)
            array_push($persionList, $subordinate['id']);
        foreach ($subordinate['child'] ?? [] as $subordinateChild) {
            $this->subordinatesRecursion($subordinateChild, $persionList, $selfExists);
        }
    }

    public function personPermissionSql($dataBuider, $builder, $personfield)
    {
        if (!$dataBuider)
            return;
        $builder->whereIn($personfield, $dataBuider);
    }

    public function personPermissionField($model)
    {
        $columns = array_values(Schema::getColumnListing($model->getTable()));
        if (in_array('created_id', $columns))
            return 'created_id';
        if (in_array('creater_id', $columns))
            return 'creater_id';
        return false;
    }
    public function customizeFieldExists()
    {
    }

    public function fieldNotExists($model)
    {
        $columns = array_values(Schema::getColumnListing($model->getTable()));
        if (!in_array('subsidiary_id', $columns) && !in_array('department_id', $columns) && !in_array('warehouse_id', $columns))
            return true;
        return false;
    }

    public function makeDataPermissionsSql($dataBuider, $query)
    {
        foreach ($dataBuider ?? [] as $data) {
            $query->orWhere(function ($query) use ($data) {
                $query->where(['subsidiary_id' => $data['subsidiary_id']])->where(['department_id' => $data['organization_id']]);
            });
        }
        return $query;
    }

    public function dataPermissionsFormate($organizations, $role)
    {
        $this->organizations = $organizations;
        $department_data_range_type_id = $this->role_business_operation_ranges['department_data_range_type_id'] ?? $role['department_data_range_type_id'];
        if ($department_data_range_type_id == 11)
            return [array_map(function($item){
                return [
                    'subsidiary_id' => $item['subsidiary_id'],
                    'organization_id' => $item['organization_id'],
                ];
            }, $organizations), array_column($organizations, 'subsidiary_id')];
        if ($department_data_range_type_id == 10 || $department_data_range_type_id == 0)
            return [[], []];
        $departmentRepositoryEloquent = new DepartmentRepositoryEloquent();
        $organizations = $departmentRepositoryEloquent->getOrganizations($organizations) ?? null;
        if ($department_data_range_type_id == 12) {
            $organizationRes = [];
            $subsidiaryIdLists = [];
            foreach ($organizations as $organization) {
                $subsidiary_id = $organization['company']['id'] ?? null;
                array_push($subsidiaryIdLists, $subsidiary_id);
                $this->organizationsRecursion($organization["department"] ?? [], $organizationRes, $subsidiary_id);
            }
            return [$organizationRes, $subsidiaryIdLists];
        }
        
    }

    public function organizationsRecursion($departments, &$organizationRes, $subsidiary_id, $existsDepartment = false)
    {
        // Base case: terminate recursion if no departments are provided
        if (empty($departments)) {
            return;
        }

        foreach ($departments ?? [] as $department) {
            if (!$existsDepartment)
                $existsDepartment = $this->exists($subsidiary_id, $department);
            if ($existsDepartment)
                array_push($organizationRes, ['subsidiary_id' => $subsidiary_id, 'organization_id' => $department['id']]);
            if (!empty($department['child']))
                $this->organizationsRecursion($department['child'], $organizationRes, $subsidiary_id, $existsDepartment);
        }
    }

    public function exists($subsidiary_id, $department)
    {
        foreach ($this->organizations ?? [] as $organization) {
            if ($organization['subsidiary_id'] == $subsidiary_id && $organization['organization_id'] == ($department['id'] ?? null))
                return true;
        }
        return false;
    }

    public function customizeField($builder, $dataBuider)
    {
        foreach ($this->currentModelField ?? [] as $eachField) {
            if ($eachField['type'] == 'user') {
                $this->customizeUserField($builder, $eachField);
            } else if ($eachField['type'] == 'employee') {
                $this->customizeEmployeeField($builder, $eachField);
            }
        }
    }

    public function customizeUserField($builder, $eachField)
    {
        $field = $eachField['field'];
        $this->role = $this->dataBuider['role'] ?? null;
        $lists = $this->dataBuider['userIdList'] ?? [];
        if ($lists && ! $this->sharesType)
            $builder->orWhereIn($field, $lists);
        else if ($lists && $this->sharesType)
            $builder->whereIn($field, $lists);
    }

    public function customizeEmployeeField($builder, $eachField)
    {
        $field = $eachField['field'];
        $this->role = $this->dataBuider['role'] ?? null;
        $lists = $this->dataBuider['employeeList'] ?? [];
        $builder->orWhereIn($field, $lists);
    }
}
