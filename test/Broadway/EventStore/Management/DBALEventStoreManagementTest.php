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

use Broadway\EventStore\DBALEventStore;
use Broadway\EventStore\TestUpcaster;
use Broadway\Serializer\SimpleInterfaceSerializer;
use Broadway\Upcasting\SequentialUpcasterChain;
use Doctrine\DBAL\DriverManager;

/**
 * @requires extension pdo_sqlite
 */
class DBALEventStoreManagementTest extends EventStoreManagementTest
{
    public function createEventStore()
    {
        $connection = DriverManager::getConnection(array('driver' => 'pdo_sqlite', 'memory' => true));
        $schemaManager = $connection->getSchemaManager();
        $schema = $schemaManager->createSchema();
        $eventStore = new DBALEventStore(
            $connection,
            new SimpleInterfaceSerializer(),
            new SimpleInterfaceSerializer(),
            'events',
            new SequentialUpcasterChain([new TestUpcaster()])
        );

        $table = $eventStore->configureSchema($schema);
        $schemaManager->createTable($table);

        return $eventStore;
    }
}
