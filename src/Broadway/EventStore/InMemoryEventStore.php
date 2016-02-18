<?php

/*
 * This file is part of the broadway/broadway package.
 *
 * (c) Qandidate.com <opensource@qandidate.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Broadway\EventStore;

use Broadway\Domain\DomainEventStream;
use Broadway\Domain\DomainEventStreamInterface;
use Broadway\EventStore\Management\Criteria;
use Broadway\EventStore\Management\EventStoreManagementInterface;
use Broadway\Domain\DomainMessage;
use Broadway\Serializer\SerializerInterface;
use Broadway\Upcasting\UpcasterChain;


/**
 * In-memory implementation of an event store.
 *
 * Useful for testing code that uses an event store.
 */
class InMemoryEventStore implements EventStoreInterface, EventStoreManagementInterface
{
    private $events = array();

    /**
     * @var UpcasterChain
     */
    private $upcasterChain;

    /**
     * @var SerializerInterface
     */
    private $serializer;

    public function __construct(SerializerInterface $serializer, UpcasterChain $upcasterChain)
    {
        $this->upcasterChain = $upcasterChain;
        $this->serializer = $serializer;
    }

    /**
     * {@inheritDoc}
     */
    public function load($id, $playhead = 0)
    {
        $id = (string) $id;

        if (isset($this->events[$id])) {
            $events = [];

            foreach ($this->events[$id] as $playhead => $event) {
                if($playhead <= $event->getPlayhead()){
                    $payload = $this->upcasterChain->upcast($event['payload']);

                    $events[] = new DomainMessage(
                        $id,
                        $playhead,
                        $event['metadata'],
                        $this->serializer->deserialize($payload),
                        $event['recorded_on']
                    );
                }


            }

            return new DomainEventStream($events);
        }

        throw new EventStreamNotFoundException(sprintf('EventStream not found for aggregate with id %s', $id));
    }

    /**
     * {@inheritDoc}
     */
    public function loadLast($id)
    {
        $id = (string) $id;

        if (isset($this->events[$id])) {
            return end($this->events[$id]);
        }

        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function append($id, DomainEventStreamInterface $eventStream)
    {
        $id = (string) $id;

        if (! isset($this->events[$id])) {
            $this->events[$id] = array();
        }

        foreach ($eventStream as $domainMessage) {
            $playhead = $domainMessage->getPlayhead();
            $this->assertPlayhead($this->events[$id], $playhead);

            $this->events[$id][$playhead] = array(
                'metadata'    => $domainMessage->getMetadata(),
                'payload'     => $this->serializer->serialize($domainMessage->getPayload()),
                'recorded_on' => $domainMessage->getRecordedOn(),
            );
        }
    }

    private function assertPlayhead($events, $playhead)
    {
        if (isset($events[$playhead])) {
            throw new InMemoryEventStoreException(
                sprintf("An event with playhead '%d' is already committed.", $playhead)
            );
        }
    }

    public function visitEvents(Criteria $criteria, EventVisitorInterface $eventVisitor)
    {
        foreach ($this->events as $id => $events) {
            foreach ($events as $event) {
                if (! $criteria->isMatchedBy($event)) {
                    continue;
                }

                $eventVisitor->doWithEvent($event);
            }
        }
    }
}
