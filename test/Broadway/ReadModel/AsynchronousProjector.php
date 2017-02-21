<?php
/**
 * Date: 21/02/17
 * Time: 11:50
 */

namespace Broadway\ReadModel;


use Broadway\Serializer\RabbitMQDomainMessageSerializer;
use Broadway\Serializer\SerializerInterface;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;

abstract class AsynchronousProjector extends Projector implements ConsumerInterface
{
    /**
     * @var RabbitMQDomainMessageSerializer
     */
    protected $domainMessageSerializer;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var bool
     */
    protected $processing = false;

    /**
     * AbstractAsynchronousProjector constructor.
     * @param RabbitMQDomainMessageSerializer $domainMessageSerializer
     * @param LoggerInterface $logger
     */
    public function __construct(RabbitMQDomainMessageSerializer $domainMessageSerializer,LoggerInterface $logger) {
        $this->domainMessageSerializer = $domainMessageSerializer;
        $this->logger = $logger;

        pcntl_signal(SIGALRM, array($this, 'manageSigAlarm'));
    }

    /**
     * Return worker name, needed to be able to identify workers from parent (e.g. in logging)
     * @return string
     */
    abstract protected function getWorkerName();

    /**
     * Handle sig alarm set after finishing message handling
     * @param $signal
     */
    public function manageSigAlarm($signal)
    {
        return;
    }

    /**
     * Pick up message from rabbitmq and execute on projector
     * @param AMQPMessage $msg
     * @param null $retrying
     * @return bool
     */
    public function execute(AMQPMessage $msg, $retrying = null)
    {
        $this->processing = true;

        $message = $this->domainMessageSerializer->deserialize($msg);
        if($message == null){//unable to deserialize message
            $this->processing = false;
            return true;//ack message
        }

        $this->logger->debug(
            $this->getWorkerName()." is handling event. id: ".$message->getId()." playhead: ".$message->getPlayhead()
        );

        try {
            parent::handle($message);
        } catch (\Exception $e) {
            $this->logger->error(
                get_class($e).' Exception while handling event: '.$message->getId().'.'.$message->getPlayhead(
                ).' worker: '.$this->getWorkerName().' message: '.$e->getMessage().' file: '.$e->getFile(
                ).' line: '.$e->getLine(),
                ['exception' => $e]
            );
        }

        $this->processing = false;
        pcntl_alarm(1);

        return true;//ack message
    }
}