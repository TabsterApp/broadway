<?php

/*
 * This file is part of the broadway/broadway package.
 *
 * (c) Qandidate.com <opensource@qandidate.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Broadway\EventStore\Management;

use Broadway\EventStore\InMemoryEventStore;
use Broadway\EventStore\TestUpcaster;
use Broadway\Serializer\SimpleInterfaceSerializer;
use Broadway\Upcasting\SequentialUpcasterChain;

class InMemoryEventStoreManagementTest extends EventStoreManagementTest
{
    public function createEventStore()
    {
        return new InMemoryEventStore(
            new SimpleInterfaceSerializer(), new SequentialUpcasterChain([new TestUpcaster()])
        );
    }
}
