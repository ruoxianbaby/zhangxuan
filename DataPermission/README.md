# 数据权限说明书

## 业务逻辑

- 第一种情况，如果数据表中存在`subsidiary_id`,`department_id`字段，会激活第一种验证方式,***子公司+部门***组合验证方式去验证用户关联到员工的，员工所在组织的上下级关系。
- 第二种情况，如果存在`created_id`,则会去判断用户关联到的员工，员工的上下级关系的权限。
- 第三种情况，同时存在`subsidiary_id`,`department_id`,以及`craeted_id`则组合判断，取`and`关系。  

## 各权限的相互关系

```plaintext
关联单据 or ((子公司 and 部门) and 创建者) or 自定义权限字段
```

> 自定义权限字段具有两个属性`type`和`field`，`type`可以为`user`以及`employee`, `field`为对应id字段  
> 员工关联用户，用户关联角色，角色上有组织权限范围（仅自己、自己和下属）和 员工数据权限范围（仅自己、自己和下属）

## 当前数据权限支持的特性

- 无限级关联单据验证

- 多类别关联单据验证

- 多个自定义权限字段验证

## 使用注意

自己开发的功能需要自己分清楚是否需要加数据权限。这有三重含义：

1. 主表单是否需要加数据权限控制

2. 子表单与主表单的相互转化

3. 哪些方法需要加数据权限控制（增删改查、启用、禁用）

> 一般而言，一张表如果没有 `subsidiary_id`,`department_id`字段，那么99.9%是不需要数据权限控制的，特殊情况需要自己判断，反之亦然，即使有`subsidiary_id`,`department_id`字段，也不一定百分百需要数据权限，需要自己结合实际业务去判断需不需要加。

## 接管权限是否执行

三个层面：

1. 判断表单是否需要权限控制，如果不需要则不加数据权限控制。

2. 通过`setNeedPermisson`助手函数，从全局层面控制是否需要加数据权限控制

3. 通过`withoutGlobalScopes`,`addGlobalScopes`控制在一个实例中是否需要权限控制。

## Tips

- 数据权限控制方法应该加在主单据`respository`层，子表数据权限无需传递
- 具体使用详情参考dataPermission的[readme文件](https://codeup.aliyun.com/60128cba2a8cae58be1e6ff8/trawind/trawind/blob/master/doc/verify-permission/readme.md)  
