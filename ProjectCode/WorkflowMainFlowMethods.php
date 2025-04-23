<?php

namespace Trawind\Workflows;

trait WorkflowMainFlowMethods
{
    private $buttonClickTranslation = false;

    protected function workflow(): ?array
    {
        $result = $this->checkWorkflow();
        $workflow = $result['data']['data'][0] ?? null;
        if ($workflow == null) return null;
        $workflows = $result['data']['data'] ?? null;
        $workflowExecutable = [];
        // dump($workflows);
        foreach ($workflows as $workflow) {
            if ($workflow['release_type_id'] != 12 || !$this->checkBeginWorkflow($workflow))
                continue;
            if ($this->checkWorkflowConditions($this->workflowConditions($workflow))) {
                array_push($workflowExecutable, $workflow);
            }
        }
        return $workflowExecutable;
    }

    protected function stateCondition($workflowCurrentExecuteState): ?bool
    {
        if (!$workflowCurrentExecuteState['current_state_id'])
            return true;
        $stateCondition = $this->workflowStateConditionsRequest($workflowCurrentExecuteState['current_state_id'], $this->model->id ?? null)['data']['data'] ?? null;
        return $this->condition($stateCondition);
    }

    protected function workflowConditions($workflow): ?array
    {
        return $this->workflowConditionsRequest($workflow['id'], $this->model->id ?? null);
    }

    protected function checkWorkflowConditions($workflowCondition): bool
    {
        $workflowCondition = $workflowCondition['data']['data'] ?? [];
        return $this->condition($workflowCondition);
    }

    protected function checkEventTypeAndTrigger($eventType = null, $triggerType = null): bool
    {
        if (!$this->checkEventType($eventType)) return false;
        if (!$this->checkEventTrigger($triggerType)) return false;
        return true;
    }

    private function checkEventType($eventType = null): bool
    {
        if (!$eventType || ($eventType && $eventType == $this->eventType))
            return true;
        return false;
    }

    private function checkEventTrigger($triggerType = null): bool
    {
        if (!$triggerType || ($triggerType && $triggerType == $this->triggerType))
            return true;
        return false;
    }

    protected function checkFinish($workflowCurrentExecuteState): bool
    {
        if ($workflowCurrentExecuteState['is_finished_type_id'] == 10)
            return true;
        return false;
    }

    protected function checkBegin($workflowCurrentExecuteState): bool
    {
        if ($workflowCurrentExecuteState['is_finished_type_id'] == 11)
            return true;
        return false;
    }

    protected function checkBeginWorkflow($workflow): bool
    {
        if (!$this->checkEventTypeAndTrigger($workflow['event_type'], $workflow['trigger_type_id']))
            return false;
        return true;
    }

    private function workflowActionFinish() {}

    private function translationConditions($translation_id): ?bool
    {
        $translationCondition = $this->workflowTranslationConditionsRequest($translation_id)['data']['data'] ?? null;
        return $this->condition($translationCondition);
    }

    private function translation()
    {
        foreach ($this->translations ?? [] as $translation) {
            $this->translation = $translation;
            $checkEventTypeAndTrigger = $this->checkEventTypeAndTrigger($translation['event_type'], $translation['trigger_type_id']);
            if (!$checkEventTypeAndTrigger)
                continue;
            if (!$this->translationConditions($translation['id']))
                continue;
            if (!$this->translationButtonClickd($translation['id']))
                continue;
            $this->translationCurrentExecuteStateRequest($translation['to_state_id'], $this->workflowCurrentExecuteState['id']);
            $this->removeButtonRequest();
            $this->writeWorkflowLog(10, 0, 0, $translation['to_state_id'] ?? 0);
            // $this->clearCurrentExecuteStateConitionsRequest();
            return null;
        }
    }

    private function translationButtonClickd($id): bool
    {
        $translationButtonClickdRequest = $this->translationButtonClickdRequest($id)['data']['data'] ?? null;

        if (!$translationButtonClickdRequest) {
            $this->buttonClickTranslation = true;
            return true;
        }
        $workflow_translation_button_condition = $translationButtonClickdRequest['workflow_translation_button_condition'] ?? null;
        if (!$workflow_translation_button_condition) {
            $this->buttonClickTranslation = true;
            return true;
        }
        $workflow_execute_buttons = $workflow_translation_button_condition['workflow_execute_buttons'] ?? null;
        if (!$workflow_execute_buttons) {
            $this->buttonClickTranslation ?? false;
            return false;
        }

        if ($workflow_execute_buttons['is_executed'] == 11) {
            $this->buttonClickTranslation = true;
            $this->checkConfirmButton($workflow_execute_buttons);
            return true;
        }
        $this->buttonClickTranslation ?? false;
        return false;
    }

    protected function checkConfirmButton($workflow_execute_buttons)
    {
        if (! $workflow_execute_buttons['confirm_type_id'])
            return null;
        if ($workflow_execute_buttons['confirm_type_id'] == 11) {
            $confirm_value = json_decode($this->workflowCurrentExecuteState['confirm_value']);
            if ($confirm_value) {
                foreach ($confirm_value as $k => $v) {
                    $this->model->$k = $v;
                }
            }
        }
        $this->updateConfirmValue(null);
    }

    protected function workflowClientDisplay(): void
    {
        // clear workflowActionResults
        $this->workflowActionResults = [];
        $id = $this->workflowCurrentExecuteState['id'] ?? null;

        $workflowExecuteButtons = $this->workflowExecuteButtons($id);
        $this->setWorkflow('add_buttons', $workflowExecuteButtons['data']['data'] ?? null);

        $workflowExecuteFields = $this->workflowExecuteFields($id);
        $this->setWorkflow('add_fields', $workflowExecuteFields['data']['data'] ?? null);

        $workflowExecuteLogs = $this->workflowExecuteLogs();
        $this->setWorkflow('execute_logs', $workflowExecuteLogs['data']['data'] ?? null);

        // record multiple workflows result
        $this->workflowActionResultsArray[] = $this->workflowActionResults;
        $this->model->workflow = $this->workflowActionResultsArray;
    }

    protected function setWorkflow($key, $value): void
    {
        $this->workflowActionResults[$key] = $this->workflowActionResults[$key] ?? [];
        array_push($this->workflowActionResults[$key], $value);
    }
}
