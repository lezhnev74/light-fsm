<?php

/*
 * This file is part of the light-fsm package.
 *
 * (c) Milos Tomic <tmilos@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace LightFsm;

class StateMachine
{
    /** @var callable */
    private $stateCallable;
    
    /** @var StateConfiguration[] */
    private $states = [];
    
    /** @var callable|null */
    private $changeCallback;
    
    private $allow_loops = false;
    
    /**
     * @param string|int|callable $initialState
     * @param callable|null       $changeCallback f($newState, $event, $oldState, $isSubState, $data)
     * @param bool                $allow_loops    will allow state to re-enter the same state with corresponding events
     */
    public function __construct($initialState, $changeCallback = null, $allow_loops = false)
    {
        $this->allow_loops = $allow_loops;
        
        if (is_callable($initialState) && is_callable($changeCallback)) {
            $this->stateCallable  = $initialState;
            $this->changeCallback = $changeCallback;
        } else {
            $state = $initialState;
            if (is_callable($initialState)) {
                $state = call_user_func($initialState);
            }
            
            $stateReference = new StateReference($state);
            
            $this->stateCallable  = function () use ($stateReference) {
                return $stateReference->getState();
            };
            $this->changeCallback = function ($state) use ($stateReference, $changeCallback) {
                $stateReference->setState($state);
                if (is_callable($changeCallback)) {
                    call_user_func($changeCallback, $state);
                }
            };
            if (false === is_callable($initialState) && is_callable($changeCallback)) {
                call_user_func($changeCallback, $state);
            }
        }
    }
    
    /**
     * @param string|int $state
     *
     * @return StateConfiguration
     */
    public function configure($state)
    {
        return $this->getState($state);
    }
    
    /**
     * @param string|int $event
     * @param mixed      $data
     */
    public function fire($event, $data = null)
    {
        $state = $this->_retrieveCurrentState();
        
        while ($state) {
            $transition = $state->getTransition($event, $data);
            if ($transition) {
                $nextState = $this->getState($transition->getNextState());
                $this->transition($state, $nextState, $data);
                
                break;
            }
            
            if ($state->getParentState()) {
                $state = $this->getState($state->getParentState());
            } else {
                $state = null;
                
                break;
            }
        }
    }
    
    /**
     * @return int|string
     */
    public function getCurrentState()
    {
        return $this->_retrieveCurrentState()->getState();
    }
    
    /**
     * @param string|int $state
     *
     * @return bool
     */
    public function isInState($state)
    {
        $parentState = $this->getState($state);
        
        return $this->isSubState($this->_retrieveCurrentState(), $parentState);
    }
    
    /**
     * @return array Array of permitted events
     */
    public function getPermittedEvents()
    {
        $result = [];
        $state  = $this->_retrieveCurrentState();
        while ($state) {
            foreach ($state->getAllTransitions() as $transition) {
                $result[$transition->getEvent()] = $transition->getEvent();
            }
            
            if ($state->getParentState()) {
                $state = $this->getState($state->getParentState());
            } else {
                $state = null;
            }
        }
        
        return array_keys($result);
    }
    
    /**
     * @return string
     */
    public function toDotGraph()
    {
        $result    = "digraph {\n";
        $listeners = '';
        foreach ($this->states as $state) {
            foreach ($state->getAllEntryCallbacks() as $name => $callback) {
                if (is_int($name)) {
                    $name = 'listener';
                }
                $listeners .= sprintf("    \"%s\" -> \"%s\" [label=\"On Entry\"];\n", $state->getState(), $name);
            }
            foreach ($state->getAllExitCallbacks() as $name => $callback) {
                if (is_int($name)) {
                    $name = 'listener';
                }
                $listeners .= sprintf("    \"%s\" -> \"%s\" [label=\"On Exit\"];\n", $state->getState(), $name);
            }
            
            foreach ($state->getAllTransitions() as $transition) {
                if ($transition->getGuardCallback()) {
                    $guardName = $transition->getGuardName() ?: 'condition';
                    $result .= sprintf("    \"%s\" -> \"%s\" [label=\"%s [%s]\"];\n", $state->getState(),
                        $transition->getNextState(), $transition->getEvent(), $guardName);
                } else {
                    $result .= sprintf("    \"%s\" -> \"%s\" [label=\"%s\"];\n", $state->getState(),
                        $transition->getNextState(), $transition->getEvent());
                }
            }
        }
        
        if ($listeners) {
            $result .= "    node [shape=box];\n";
            $result .= $listeners;
        }
        
        $result .= "}\n";
        
        return $result;
    }
    
    /**
     * @param StateConfiguration $child
     * @param StateConfiguration $parent
     *
     * @return bool
     */
    private function isSubState(StateConfiguration $child, StateConfiguration $parent)
    {
        $state = $child;
        
        while ($state) {
            if ($state->getState() === $parent->getState()) {
                return true;
            }
            
            if ($state->getParentState()) {
                $state = $this->getState($state->getParentState());
            } else {
                $state = null;
            }
        }
        
        return false;
    }
    
    /**
     * @param string|int $state
     *
     * @return StateConfiguration
     */
    private function getState($state)
    {
        if (false === isset($this->states[$state])) {
            $this->states[$state] = new StateConfiguration($state);
        }
        
        return $this->states[$state];
    }
    
    /**
     * @param StateConfiguration $previousState
     * @param StateConfiguration $nextState
     * @param mixed              $data
     */
    private function transition(StateConfiguration $previousState, StateConfiguration $nextState, $data)
    {
        if ($previousState->getState() == $nextState->getState() && !$this->allow_loops) {
            return;
        }
        
        $isSubState = $this->isSubState($this->_retrieveCurrentState(), $nextState);
        
        $previousState->triggerExit($isSubState, $data, $nextState);
        $this->_storeCurrentState($nextState->getState());
        $nextState->triggerEntry($isSubState, $data, $previousState);
    }
    
    /**
     * @return StateConfiguration
     */
    private function _retrieveCurrentState()
    {
        $state = call_user_func($this->stateCallable);
        
        return $this->getState($state);
    }
    
    /**
     * @param string|int $state
     */
    private function _storeCurrentState($state)
    {
        call_user_func($this->changeCallback, $state);
    }
}
