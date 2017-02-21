<?php
/**
 * Date: 21/02/17
 * Time: 11:24
 */

namespace Broadway\Serializer;


use Broadway\Domain\DateTime;
use Broadway\Domain\DomainMessage;
use Monolog\Logger;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;

class RabbitMQDomainMessageSerializer
{
    /**
     * @var SerializerInterface
     */
    protected $metadataSerializer;

    /**
     * @var SerializerInterface
     */
    protected $payloadSerializer;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * AsynchronousDomainMessageSerializer constructor.
     * @param SerializerInterface $metadataSerializer
     * @param SerializerInterface $payloadSerializer
     * @param LoggerInterface $logger
     */
    public function __construct(
        SerializerInterface $metadataSerializer,
        SerializerInterface $payloadSerializer,
        LoggerInterface $logger
    ) {
        $this->metadataSerializer = $metadataSerializer;
        $this->payloadSerializer = $payloadSerializer;
        $this->logger = $logger;
    }

    /**
     * @param AMQPMessage $msg
     * @return DomainMessage|null
     */
    public function deserialize(AMQPMessage $msg)
    {
        try {
            $message = json_decode($msg->body, true);

            return new DomainMessage(
                $message['id'],
                $message['playhead'],
                $this->metadataSerializer->deserialize($message['metadata']),
                $this->payloadSerializer->deserialize($message['payload']),
                DateTime::fromString($message['recorded_on']),
                $message['type']
            );
        } catch (\Exception $e) {
            $this->logger->log(
                Logger::ERROR,
                "Error while deserializing rabbitMQMessage to domainMessage",
                ['messageBody' => $msg->body, "exception" => $e->getMessage()]
            );
        }

        return null;
    }

    /**
     * @param DomainMessage $domainMessage
     * @return array
     */
    public function serialize(DomainMessage $domainMessage)
    {
        return json_encode(
            [
                'id' => $domainMessage->getId(),
                'playhead' => $domainMessage->getPlayhead(),
                'metadata' => $this->metadataSerializer->serialize($domainMessage->getMetadata()),
                'payload' => $this->payloadSerializer->serialize($domainMessage->getPayload()),
                'recorded_on' => $domainMessage->getRecordedOn()->toString(),
                'type' => $domainMessage->getType()
            ]
        );
    }
}