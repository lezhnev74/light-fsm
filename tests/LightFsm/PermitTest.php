<?php

namespace LightFsm\Tests;

use LightFsm\Exception\StateEventDuplication;
use LightFsm\StateMachine;

class PermitTest extends \PHPUnit_Framework_TestCase
{
    
    public function test_state_cannot_have_multiple_transitions_with_the_same_trigger_name()
    {
        //
        // Note: each state actually can have multiple events to react on
        // But each event must have different callback
        //
        
        $this->setExpectedException(StateEventDuplication::class);
        $stateMachine = new StateMachine("a");
        $stateMachine->configure('a')
                     ->permit('ev1', 'b')
                     ->permit('ev1', 'a');
    }
    
    
}
