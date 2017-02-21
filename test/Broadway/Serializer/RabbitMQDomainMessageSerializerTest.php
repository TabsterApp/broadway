<?php
/**
 * Date: 21/02/17
 * Time: 11:36
 */

namespace Broadway\Serializer;

use Broadway\Domain\DomainMessage;
use Broadway\Domain\Metadata;
use PhpAmqpLib\Message\AMQPMessage;
use Symfony\Component\HttpKernel\Tests\Logger;
use Broadway\TestCase;


class RabbitMqDomainMessageSerializerTest extends TestCase
{
    /**
     * @var RabbitMQDomainMessageSerializer
     */
    protected $domainMessageSerializer;

    /**
     * @var SimpleInterfaceSerializer
     */
    protected $serializer;

    public function setUp()
    {
        parent::setUp();

        $this->domainMessageSerializer = new RabbitMQDomainMessageSerializer(
            new SimpleInterfaceSerializer(),
            new SimpleInterfaceSerializer(),
            new Logger()
        );

        $this->serializer = new SimpleInterfaceSerializer();
    }

    public function testItSerializesDomainMessage()
    {
        $id = 'Hi thur';
        $payload = new SomeEvent("123");
        $playhead = 15;
        $metadata = new Metadata([]);
        $type = 'Broadway.Serializer.SomeEvent';

        $domainMessage = DomainMessage::recordNow($id, $playhead, $metadata, $payload);

        $serialized = json_encode([
            'id' => $id,
            'playhead' => $playhead,
            'metadata' => $this->serializer->serialize($metadata),
            'payload' => $this->serializer->serialize($payload),
            'recorded_on' => $domainMessage->getRecordedOn()->toString(),
            'type' => $type
        ]);

        $this->assertEquals($serialized, $this->domainMessageSerializer->serialize($domainMessage));
    }

    public function testItReturnsNullOnInvalidDomainMessage()
    {
        $message = new AMQPMessage("Not a domain message");

        $this->assertNull($this->domainMessageSerializer->deserialize($message));
    }

    public function testItCanDeserializeValidMessage()
    {
        $id = 'Hi thur';
        $payload = new SomeEvent("123abc");
        $playhead = 15;
        $metadata = new Metadata([]);
        $type = 'Broadway.Serializer.SomeEvent';

        $domainMessage = DomainMessage::recordNow($id, $playhead, $metadata, $payload);

        $serialized = [
            'id' => $id,
            'playhead' => $playhead,
            'metadata' => $this->serializer->serialize($metadata),
            'payload' => $this->serializer->serialize($payload),
            'recorded_on' => $domainMessage->getRecordedOn()->toString(),
            'type' => $type
        ];

        $message = new AMQPMessage(json_encode($serialized));

        $this->assertEquals($domainMessage, $this->domainMessageSerializer->deserialize($message));
    }

    public function testItSerializesAndDeserializesIntoTheSameObj()
    {
        $id = 'Hi thur';
        $payload = new SomeEvent("123abc");
        $playhead = 15;
        $metadata = new Metadata([]);

        $domainMessage = DomainMessage::recordNow($id, $playhead, $metadata, $payload);

        $serialized = $this->domainMessageSerializer->serialize($domainMessage);
        $deserialized = $this->domainMessageSerializer->deserialize(new AMQPMessage($serialized));

        $this->assertEquals($domainMessage, $deserialized);
    }
}

class SomeEvent implements SerializableInterface
{
    private $foo;

    public function __construct($foo)
    {
        $this->foo = $foo;
    }

    /**
     * @return $this
     */
    public static function deserialize(array $data)
    {
        return new self($data['foo']);
    }

    /**
     * @return array
     */
    public function serialize()
    {
        return array('foo' => $this->foo);
    }
}