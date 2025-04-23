
# Permission

## Installation

```composer require trawind/trawind```

## Usage

### get user info

```php
use Trawind\VerifyPermission\VerifyPermission;

$verifyPermission = new VerifyPermission();
$user = $verifyPermission->user();
```

### check operation permission

Open the `TrawindCloud\Http\Controllers\Controller` class, and in the `__construct` method, add the following middleware:

```php
$this->middleware(\Trawind\VerifyPermission\Middleware\CheckOperationPermission::class);
```

you can add middleware to each controller.

### add ignore actions

```php
$ignore = [
    'TrawindCloud\Http\Controllers\PermissionsController' => ['getPermissions1'],
];
$data = base64_encode(json_encode($ignore, true));
$this->middleware(\Trawind\VerifyPermission\Middleware\CheckOperactionPermission::class . ":{$data}");
```

### automatically check data permission

- Configure in repository boot method for automatically, like follow code:

```php
public $dataPermissionScope = true;
public function boot()
{
    parent::boot();
    if ($this->dataPermissionScope)
        static::addGlobalScope(new \Trawind\VerifyPermission\Scopes\DataPermissionScope());
    $this->pushCriteria(app(RequestCriteria::class));
}
```

- Filter by subtable

```php
public function boot()
{
    parent::boot();
    $relations = 'relations';
    static::addGlobalScope(new \Trawind\VerifyPermission\Scopes\DataPermissionScope($relations));
}
```

- Filter by deep and multiple subtables

```php
public function boot()
{
    parent::boot();
    $relations = ['relations1.relations2', 'relations3'];
    static::addGlobalScope(new \Trawind\VerifyPermission\Scopes\DataPermissionScope($relations));
}
```

- Filter by custom data permission fields

```php
public function boot()
{
    use App\Models\MyModel;
    parent::boot();
    $fields = [
        MyModel::class => [
            [
                'type' => 'user',
                'field' => 'user_id'
            ]
        ],
        get_class($this->getModel()) => [
            [
                'type' => 'employee',
                'field' => 'employee_id'
            ]
        ]
    ];
    static::addGlobalScope(new \Trawind\VerifyPermission\Scopes\DataPermissionScope('myModel', $fields));
}
```

- Tips  
      - Ensure your database table includes the `subsidiary_id`, `department_id`, and `warehouse_id` fields.  
      - Remove scope `$model->withoutGlobalScopes(\Trawind\VerifyPermission\Scopes\DataPermissionScope())`  
