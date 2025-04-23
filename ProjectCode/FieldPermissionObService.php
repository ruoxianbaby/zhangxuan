<?php

namespace Trawind\VerifyPermission\Observer;

use Illuminate\Database\Eloquent\Model;
use Trawind\VerifyPermission\FieldPermission\FieldPermission;

class FieldPermissionObService
{
    public function retrieved(Model $model)
    {
        $retrievedField = null;
        try {
            $retrievedField = app()->make($model->getTable() . 'retrieved');
        } catch (\Exception $e) {
            $retrievedField = (new FieldPermission($model))->retrievedField();
            app()->bind($model->getTable() . 'retrieved', function() use ($retrievedField) {
                return $retrievedField;
            });
        }
        if (! $retrievedField)
            return $model;
        $model->makeHidden($retrievedField);
        return $model;
    }

    public function saving(Model $model)
    {
        $savingField = null;
        try {
            $savingField = app()->make($model->getTable() . 'saving');
        } catch (\Exception $e) {
            $savingField = (new FieldPermission($model))->savingField();
            app()->bind($model->getTable() . 'saving', function() use ($savingField) {
                return $savingField;
            });
        }
        if (! $savingField)
            return $model;
        array_map(function($item) use ($model) {
            if ($model->getOriginal())
                $model->{$item} = $model->getOriginal()[$item];
            else
                unset($model->{$item});
        }, $savingField);
        return $model;
    }
}
