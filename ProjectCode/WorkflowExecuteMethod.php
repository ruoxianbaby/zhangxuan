<?php

namespace Trawind\Workflows;

use Trawind\Helpers\UserHelper;
use Trawind\Remotes\Repositories\Account\Account\DepartmentRepositoryEloquent;
use Trawind\Remotes\Repositories\Account\Account\EmployeeRepositoryEloquent;

trait WorkflowExecuteMethod
{
    private function executeState($workflowCurrentExecuteState, $conditions): void
    {
        $conditions = $this->conversion($conditions);
        $workflowActions = $workflowCurrentExecuteState['workflow_state']['workflow_actions'] ?? [];
        $executed = $this->workflowCurrentExecuteState['current_action_id'] ? true : false;

        foreach ($workflowActions as $workflowAction) {
            $workflowActionDetail = $workflowAction['action'];
            if ($executed) {
                if ($this->workflowCurrentExecuteState['current_action_id'] == ($workflowAction['id'] ?? null))
                    $executed = false;
                continue;
            }
            $checkEventTypeAndTrigger = $this->checkEventTypeAndTrigger($workflowActionDetail['event_type'], $workflowActionDetail['trigger_type_id']);
            if ($checkEventTypeAndTrigger)
                if ($this->condition($conditions[$workflowAction['id']] ?? null, $workflowAction))
                    $this->executeAction($workflowAction);
                else
                    $this->translationEnable = false;
        }
    }

    protected function conversion($conditions): array
    {
        $workflow_state = $conditions['data']['data'][0] ?? null;
        $workflow_actions = $workflow_state['workflow_state']['workflow_actions'] ?? null;
        $conditions = [];
        if (!$workflow_actions)
            return $conditions;
        foreach ($workflow_actions as $workflow_action) {
            $conditions[$workflow_action['id']] = $workflow_action['conditions'];
        }
        return $conditions;
    }

    protected function condition($conditions, $workflowAction = null): bool
    {
        if (!$conditions) return true;
        foreach ($conditions as $condition) {
            if ($condition['resource_id'] && $condition['resource_id'] != $this->model->id)
                continue;
            if (!$condition['type_type_id']) {
                if (!$this->normalFieldCondition($condition))
                    return false;
            } else if ($condition['type_type_id'] == 10) {
                if (!$this->currentRoleCondition($condition))
                    return false;
            } else if ($condition['type_type_id'] == 11) {
                if (!$this->currentUserCondition($condition))
                    return false;
            } else if ($condition['type_type_id'] == 20) {
                if (!$this->currentLeaderCondition($condition))
                    return false;
            } else if ($condition['type_type_id'] == 30) {
                if (!$this->currentDepartmentCondition($condition))
                    return false;
            }
        }
        return true;
    }

    protected function currentUserCondition($condition): bool
    {
        $current_user_id = UserHelper::userId();
        $list = explode(',', $condition['value'] ?? '');
        if (in_array($current_user_id, $list))
            return true;
        return false;
    }

    protected function currentDepartmentCondition($condition): bool
    {

        if (!$this->model->department_id ?? null)
            return true;
        $DepartmentRepositoryEloquent = (new DepartmentRepositoryEloquent)->getListBySearch([
            'search' => 'id:' . $this->model->department_id,
            'searchJoin' => 'and',
            'searchFields' => 'id:in',
            'include' => 'approve',
            'with'  => 'approve',
        ]);;
        $approves = $DepartmentRepositoryEloquent['data'][0]['approve']['data'] ?? null;
        if (!$approves)
            return false;
        if ($condition['compare'] == 40) {
            foreach ($approves as $approve)
                if ($approve['tier'] == $condition['value'] && $this->model->businessType == $approve['approve_business_type_id'])
                    if ($condition['translation_id'])
                        return true;
                    else
                        return $this->currentUserCondition(['value' => $approve['approve_id']]);
            return false;
        } else if ($condition['compare'] == 41) {
            foreach ($approves as $approve) {
                if ($approve['tier'] == $condition['value'] && $this->model->businessType == $approve['approve_business_type_id'])
                    return false;
            }
            return true;
        }
        return false;
    }

    protected function currentLeaderCondition($condition): bool
    {
        $employeeRepositoryEloquent = (new EmployeeRepositoryEloquent)->find(UserHelper::userInfo()['userPerson']['person_id']);
        if (!($employeeRepositoryEloquent['data']['director_id'] ?? null)) {
            if ($condition['compare'] == 40)
                return false;
            else if ($condition['compare'] == 41)
                return true;
        }
        $this->updateNextStateConditionRequest($employeeRepositoryEloquent['data']['director_id']);
        return true;
    }

    protected function currentRoleCondition($condition): bool
    {
        $current_role_id = UserHelper::userInfo()['last_signin_role_id'] ?? null;
        $list = explode(',', $condition['value'] ?? '');
        if (in_array($current_role_id, $list))
            return true;
        return false;
    }

    protected function normalFieldCondition($condition): bool
    {
        $fieldValue = '';
        $fieldName = $condition['field'];
        if ($condition['is_extension'] == 10)
            $fieldValue = $this->model->$fieldName;
        else if ($condition['is_extension'] == 11)
            return false;
        else
            return false;
        $result = false;
        switch ($condition['compare']) {
            case 10:
                $result = ($fieldValue == $condition['value']);
                break;
            case 11:
                $result = ($fieldValue != $condition['value']);
                break;
            case 20:
                $result = ($fieldValue > $condition['value']);
                break;
            case 21:
                $result = ($fieldValue < $condition['value']);
                break;
            case 30:
                $result = in_array($fieldValue, explode(',', $condition['value']));
                break;
            default:
                $result = false;
        }
        if (!$result)
            return false;
        return true;
    }
    protected function executeAction($workflowAction)
    {
        switch ($workflowAction['action_type']['table_name']) {
            case 'add_buttons':
                $this->addButtonAction($workflowAction);
                break;
            case 'add_fields':
                $this->addFields($workflowAction);
                break;
            case 'set_field_values':
                $this->setFieldValue($workflowAction);
                break;
            case 'remove_fields':
                $this->removeField($workflowAction);
                break;
            case 'confirm_action':
                $this->confirmAction($workflowAction);
                break;
            default:;
        }
    }

    protected function confirmAction(array $workflowAction = null): void
    {
        $workflowActionDetail = $workflowAction['action'];
        $fields = $workflowActionDetail['fields'] ?? '';
        if ($fields)
            $fields = explode(',', $fields);
        $diffs = array_diff($this->model->getAttributes(), $this->model->getOriginal());
        if ($diffs) {
            foreach ($diffs as $key => $value) {
                if ($fields && !in_array($key, $fields)) {
                    unset($diffs[$key]);
                    continue;
                }
                $this->model->$key = $this->model->getOriginal()[$key];
            }
            $this->updateConfirmValue(json_encode($diffs));
        }
        $this->updateCurrentAction($workflowAction['id']);
        $this->writeWorkflowLog(20, $workflowAction['action_type']['id'], $workflowActionDetail['id']);
    }

    protected function addButtonAction($workflowAction): void
    {
        $workflowActionDetail = $workflowAction['action'];
        $label = $workflowActionDetail['label'];
        $name = $workflowActionDetail['name'];
        $id = $workflowActionDetail['id'];
        $confirm_type_id = $workflowActionDetail['confirm_type_id'];
        $this->addButtonActionRequest($id, $label, $name, $confirm_type_id);
        $this->updateCurrentAction($workflowAction['id']);
        $this->writeWorkflowLog(20, $workflowAction['action_type']['id'], $workflowActionDetail['id']);
    }

    protected function addFields($workflowAction): void
    {
        $workflowActionDetail = $workflowAction['action'];
        $this->addFieldsRequest($workflowActionDetail['name'], $workflowActionDetail['default_value'], $workflowActionDetail['label'], $workflowActionDetail['id']);
        $this->updateCurrentAction($workflowAction['id']);
        $this->writeWorkflowLog(20, $workflowAction['action_type']['id'], $workflowActionDetail['id']);
    }

    protected function removeField($workflowAction): void
    {
        $workflowActionDetail = $workflowAction['action'];
        $this->removeFieldRequest($workflowActionDetail['name']);
        $this->updateCurrentAction($workflowAction['id']);
        $this->writeWorkflowLog(20, $workflowAction['action_type']['id'], $workflowActionDetail['id']);
    }

    protected function setFieldValue($workflowAction): void
    {
        $workflowActionDetail = $workflowAction['action'];
        if ($workflowActionDetail['is_extenstion'] == 10) {
            $this->updateResourceFieldValue($workflowActionDetail);
        } else if ($workflowActionDetail['is_extenstion'] == 11) {
            $this->updateWorkflowFieldValue($workflowActionDetail);
        }
        $this->updateCurrentAction($workflowAction['id']);
        $this->writeWorkflowLog(20, $workflowAction['action_type']['id'], $workflowActionDetail['id']);
    }

    private function updateWorkflowFieldValue($workflowActionDetail): void
    {
        $this->updateWorkflowFieldValueRequest($this->workflowCurrentExecuteState['id'], $workflowActionDetail['name'], $workflowActionDetail['value']);
    }

    private function updateResourceFieldValue($workflowActionDetail): void
    {
        $name = $workflowActionDetail['name'];
        $this->model->$name = $workflowActionDetail['value'];
        unset($this->model['workflow']);
        $this->model->save();
    }
}
