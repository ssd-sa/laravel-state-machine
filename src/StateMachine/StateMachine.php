<?php

/*
 * This file is part of the StateMachine package.
 *
 * (c) Alexandre Bacco
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace troojaan\SM\StateMachine;

use SM\Callback\CallbackFactory;
use SM\Callback\CallbackFactoryInterface;
use SM\Callback\CallbackInterface;
use SM\Event\SMEvents;
use SM\Event\TransitionEvent;
use SM\SMException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\PropertyAccess\Exception\NoSuchPropertyException;
use Symfony\Component\PropertyAccess\PropertyAccessor;
use SM\StateMachine\StateMachineInterface;

class StateMachine implements StateMachineInterface
{
    /**
     * @var object
     */
    protected $object;

    /**
     * @var array
     */
    protected $config;

    /**
     * @var EventDispatcherInterface
     */
    protected $dispatcher;

    /**
     * @var CallbackFactoryInterface
     */
    protected $callbackFactory;

    /**
     * @param object                   $object          Underlying object for the state machine
     * @param array                    $config          Config array of the graph
     * @param EventDispatcherInterface $dispatcher      EventDispatcher or null not to dispatch events
     * @param CallbackFactoryInterface $callbackFactory CallbackFactory or null to use the default one
     *
     * @throws SMException If object doesn't have configured property path for state
     */
    public function __construct(
        $object,
        array $config,
        EventDispatcherInterface $dispatcher      = null,
        CallbackFactoryInterface $callbackFactory = null
    ) {
        $this->object          = $object;
        $this->dispatcher      = $dispatcher;
        $this->callbackFactory = $callbackFactory ?: new CallbackFactory('SM\Callback\Callback');

        if (!isset($config['property_path'])) {
            $config['property_path'] = 'state';
        }

        $this->config = $config;

        // Test if the given object has the given state property path
        try {
            $this->getState();
        } catch (NoSuchPropertyException $e) {
            throw new SMException(sprintf(
                'Cannot access to configured property path "%s" on object %s with graph "%s"',
                $config['property_path'],
                get_class($object),
                $config['graph']
            ));
        }
    }

    /**
     * {@inheritDoc}
     */
    public function can($transition)
    {
        if (!isset($this->config['transitions'][$transition])) {
            throw new SMException(sprintf(
                'Transition "%s" does not exist on object "%s" with graph "%s"',
                $transition,
                get_class($this->object),
                $this->config['graph']
            ));
        }
        $availabilities = [];
        foreach ($this->getState() as $state) {
            if (!in_array($state, $this->config['transitions'][$transition]['from'])) {
                array_push($availabilities, false);
            } else {
                $can = true;
                $event = new TransitionEvent($transition, $this->getState(), $this->config['transitions'][$transition], $this);
                if (null !== $this->dispatcher) {
                    $this->dispatcher->dispatch(SMEvents::TEST_TRANSITION, $event);

                    $can = !$event->isRejected();
                }

                array_push($availabilities, $can && $this->callCallbacks($event, 'guard'));
            }
        }

        if (count($this->config['transitions'][$transition]['from']) > 1) {
            if (in_array(false, $availabilities)) {
                return false;
            }
            return true;
        }

        if (in_array(true, $availabilities)) {
            return true;
        }

        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function apply($transition, $soft = false)
    {
        if (!$this->can($transition)) {
            if ($soft) {
                return false;
            }

            throw new SMException(sprintf(
                'Transition "%s" cannot be applied on state "%s" of object "%s" with graph "%s"',
                $transition,
                $this->getState(),
                get_class($this->object),
                $this->config['graph']
            ));
        }

        $event = new TransitionEvent($transition, $this->getState(), $this->config['transitions'][$transition], $this);

        if (null !== $this->dispatcher) {
            $this->dispatcher->dispatch(SMEvents::PRE_TRANSITION, $event);

            if ($event->isRejected()) {
                return false;
            }
        }

        $this->callCallbacks($event, 'before');

        $this->setState($this->config['transitions'][$transition]['to'], $this->config['transitions'][$transition]['from']);

        $this->callCallbacks($event, 'after');

        if (null !== $this->dispatcher) {
            $this->dispatcher->dispatch(SMEvents::POST_TRANSITION, $event);
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function getState()
    {
        $accessor = new PropertyAccessor();
        return $accessor->getValue($this->object, $this->config['property_path']);
    }

    /**
     * {@inheritDoc}
     */
    public function getObject()
    {
        return $this->object;
    }

    /**
     * {@inheritDoc}
     */
    public function getGraph()
    {
        return $this->config['graph'];
    }

    /**
     * {@inheritDoc}
     */
    public function getPossibleTransitions()
    {
        return array_filter(
            array_keys($this->config['transitions']),
            array($this, 'can')
        );
    }

    /**
     * Set a new state to the underlying object
     *
     * @param string $state
     *
     * @throws SMException
     */
    protected function setState($toStates, $fromStates)
    {
        foreach ($toStates as $toState) {
            if (!in_array($toState, $this->config['states'])) {
                throw new SMException(sprintf(
                    'Cannot set the state to "%s" to object "%s" with graph %s because it is not pre-defined.',
                    $toState,
                    get_class($this->object),
                    $this->config['graph']
                ));
            }
        }
        // the final new states that need to be saved in DB
        $newStates = [];

        // the current states which returns from DB
        $currentStates = $this->object[$this->config['property_path']];

        // removes the current transition  states-from from current states (not all current states)
        $newStates = array_diff($currentStates, $fromStates);

        // add the current transition states-to to current states
        $newStates = array_merge($newStates, $toStates);

        // set the new states
        $accessor = new PropertyAccessor();
        $accessor->setValue($this->object, $this->config['property_path'], $newStates);
    }

    /**
     * Builds and calls the defined callbacks
     *
     * @param TransitionEvent $event
     * @param string $position
     * @return bool
     */
    protected function callCallbacks(TransitionEvent $event, $position)
    {
        if (!isset($this->config['callbacks'][$position])) {
            return true;
        }

        $result = true;
        foreach ($this->config['callbacks'][$position] as &$callback) {
            if (!$callback instanceof CallbackInterface) {
                $callback = $this->callbackFactory->get($callback);
            }

            $result = call_user_func($callback, $event) && $result;
        }
        return $result;
    }
}