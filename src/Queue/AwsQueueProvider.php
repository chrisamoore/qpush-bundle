<?php

namespace Uecode\Bundle\QPushBundle\Queue;

use Aws\Common\Aws;
use Aws\Sqs\SqsClient;

use Doctrine\Common\Cache\Cache;

use Uecode\Bundle\QPushBundle\Queue\QueueProvider;

use Uecode\Bundle\QPushBundle\Event\Events;
use Uecode\Bundle\QPushBundle\Event\MessageEvent;
use Uecode\Bundle\QPushBundle\Event\NotificationEvent;
use Uecode\Bundle\QPushBundle\Event\SubscriptionEvent;

class AwsQueueProvider extends QueueProvider
{
    /**
     * Aws SQS Client
     *
     * @var SqsClient
     */
    private $sqs;

    /**
     * Aws SQS Client
     *
     * @var SqsClient
     */
    private $sns;

    /**
     * SQS Queue URL
     *
     * @var string
     */
    private $queueUrl;

    /**
     * SNS Topic ARN
     *
     * @var string
     */
    private $topicArn;

    public function getProvider()
    {
        return "Aws";
    }

    /**
     * Sets the Aws Services, SQS & SNS
     *
     * @param Aws $service An instance of Aws\Common\Aws;
     */
    public function setService($service)
    {
        if (!$service instanceof Aws) {
            throw new \InvalidArgumentException(
                'Service must be an instance of Aws\Common\Aws'
            );
        }

        $this->sqs = $service->get('Sqs');
        $this->sns = $service->get('Sns');
    }

    public function createMessageEvent($message)
    {
        $body   = $message['Body'];
        $msg    = json_decode($message['Body'], true);

        if (!empty($msg) && isset($msg['Message'])) {
            $msg = json_decode($msg['Message'], true);
        } else {
            $msg = json_decode($body, true);
        }

        return new MessageEvent($this->name, $msg, $message);
    }

    /**
     * Pushes a message to the Queue
     *
     * This method will either use a SNS Topic to publish a queued message or
     * straight to SQS depending on the application configuration.
     *
     * @param array $message The message to queue
     *
     * @return string
     */
    public function publish(array $message)
    {
        // ensures that the SQS Queue and SNS Topic exist
        if(!$this->queueExists()) {
            $this->create();
        }

        if ($this->options['push_notifications'] && $this->topicExists()) {
            $message    = [
                'default'   => $this->getNameWithPrefix(),
                'sqs'       => json_encode($message),
                'http'      => $this->getNameWithPrefix(),
                'https'     => $this->getNameWithPrefix(),
            ];

            $result = $this->sns->publish([
                'TopicArn'          => $this->topicArn,
                'Subject'           => $this->getNameWithPrefix(),
                'Message'           => json_encode($message),
                'MessageStructure'  => 'json'
            ]);

            return $result->get('MessageId');
        }

        $result = $this->sqs->sendMessage([
            'QueueUrl'      => $this->queueUrl,
            'MessageBody'   => json_encode($message)
        ]);

        return $result->get('MessageId');
    }

    /**
     * Builds the configured queues
     *
     * If a Queue name is passed and configured, this method will build only that
     * Queue.
     *
     * All Create methods are idempotent, if the resource exists, the current ARN
     * will be returned
     *
     */
    public function create()
    {
        // Create the SQS Queue
        if (!$this->queueExists()) {
            $this->createQueue();
        }

        if ($this->options['push_notifications'] && !$this->topicExists()) {
           // Create the SNS Topic
           $this->createTopic();

           // Add the SQS Queue as a Subscriber to the SNS Topic
           $this->subscribeToTopic(
               $this->topicArn,
               'sqs',
               $this->sqs->getQueueArn($this->queueUrl)
           );

           // Add configured Subscribers to the SNS Topic
           foreach ($this->options['subscribers'] as $subscriber) {
                $this->subscribeToTopic(
                    $this->topicArn,
                    $subscriber['protocol'],
                    $subscriber['endpoint']
                );
            }
        }

        return true;
    }

    /**
     * Polls the Queue for Messages
     *
     * The `receiveMessage` method will remain open for the configured
     * `received_message_wait_time_seconds` value on this queue, to allow for
     * long polling.
     *
     * @return array
     */
    public function receive()
    {
        if (!$this->queueExists()) {
            $this->create();
        }

        $result = $this->sqs->receiveMessage([
            'QueueUrl'              => $this->queueUrl,
            'MaxNumberOfMessages'   => 10,
            'WaitTimeSeconds'       => 3
        ]);

        return $result->get('Messages') ?: [];
    }

    public function delete($message)
    {
        if (!$this->queueExists()) {
            $this->create();
        }

        $result = $this->sqs->deleteMessage([
            'QueueUrl'      => $this->queueUrl,
            'ReceiptHandle' => $message
        ]);
    }
    /**
     * Return the Queue Url
     *
     * This method relies on in-memory cache and the Cache provider
     * to reduce the need to needlessly call the create method on an existing
     * Queue.
     *
     * @return string
     */
    public function queueExists()
    {
        if (isset($this->queueUrl)) {
            return true;
        }

        $key = $this->getNameWithPrefix() . '_url';
        if ($this->cache->contains($key)) {
            $this->queueUrl = $this->cache->fetch($key);

            return true;
        }

        return false;
    }

    /**
     * Creates an SQS Queue and returns the Queue Url
     *
     * The create method for SQS Queues is idempotent - if the queue already
     * exists, this method will return the Queue Url of the existing Queue.
     *
     * @return string
     */
    public function createQueue()
    {
        $result = $this->sqs->createQueue([
            'QueueName' => $this->getNameWithPrefix()
        ]);

        $this->queueUrl = $result->get('QueueUrl');

        $key = $this->getNameWithPrefix() . '_url';
        $this->cache->save($key, $this->queueUrl);

        if ($this->options['push_notifications']) {
            $policy = $this->createPolicy();
            $this->sqs->setQueueAttributes([
                'QueueUrl'      => $this->queueUrl,
                'Attributes'    => [
                    'Policy'    => $policy
                ]
            ]);
        }
    }

    public function createPolicy()
    {
        $arn = $this->sqs->getQueueArn($this->queueUrl);
        return json_encode([
            'Version'   => '2008-10-17',
            'Id'        =>  sprintf('%s/SQSDefaultPolicy', $arn),
            'Statement' => [
                [
                    'Sid'       => 'SNSPermissions',
                    'Effect'    => 'Allow',
                    'Principal' => ['AWS' => '*'],
                    'Action'    => 'SQS:SendMessage',
                    'Resource'  => $arn
                ]
            ]
        ]);
    }

    /**
     * Checks to see if a Topic exists
     *
     * This method relies on in-memory cache and the Cache provider
     * to reduce the need to needlessly call the create method on an existing
     * Topic.
     *
     * @return string
     */
    public function topicExists()
    {
        if (isset($this->topicArn)) {
            return true;
        }

        $key = $this->getNameWithPrefix() . '_arn';
        if ($this->cache->contains($key)) {
            $this->topicArn = $this->cache->fetch($key);

            return true;
        }

        return false;
    }

    /**
     * Creates a SNS Topic and returns the ARN
     *
     * The create method for the SNS Topics is idempotent - if the topic already
     * exists, this method will return the Topic ARN of the existing Topic.
     *
     * @param string $name The name of the Queue to be used as a Topic Name
     *
     * @return string
     */
    public function createTopic()
    {
        if (!$this->options['push_notifications']) {
            return false;
        }

        $result = $this->sns->createTopic([
            'Name' => $this->getNameWithPrefix()
        ]);

        $this->topicArn = $result->get('TopicArn');

        $key = $this->getNameWithPrefix() . '_arn';
        $this->cache->save($key, $this->topicArn);
    }

    /**
     * Get a list of Subscriptions for the specified SNS Topic
     *
     * @param string $topicArn The SNS Topic Arn
     *
     * @return array
     */
    public function getTopicSubscriptions($topicArn)
    {
        $result = $this->sns->listSubscriptionsByTopic([
            'TopicArn' => $topicArn
        ]);

        return $result->get('Subscriptions');
    }

    /**
     * Subscribes an endpoint to a SNS Topic
     *
     * @param string $topicArn The ARN of the Topic
     * @param string $protocol The protocol of the Endpoint
     * @param string $endpoint The Endpoint of the Subscriber
     *
     * @return string
     */
    public function subscribeToTopic($topicArn, $protocol, $endpoint)
    {
        // Check against the current Topic Subscriptions
        $subscriptions = $this->getTopicSubscriptions($topicArn);
        foreach ($subscriptions as $subscription) {
            if ($endpoint === $subscription['Endpoint']) {
                return $subscription['SubscriptionArn'];
            }
        }

        $result = $this->sns->subscribe([
            'TopicArn' => $topicArn,
            'Protocol' => $protocol,
            'Endpoint' => $endpoint
        ]);

        return $result->get('SubscriptionArn');
    }

    /**
     * Unsubscribes an endpoint from a SNS Topic
     *
     * The method will return TRUE on success, or FALSE if the Endpoint did not
     * have a Subscription on the SNS Topic
     *
     * @param string $topicArn The ARN of the Topic
     * @param string $protocol The protocol of the Endpoint
     * @param string $endpoint The Endpoint of the Subscriber
     *
     * @return boolean
     */
    public function unsubscribeFromTopic($topicArn, $protocol, $endpoint)
    {
        // Check against the current Topic Subscriptions
        $subscriptions = $this->getTopicSubscriptions($topicArn);
        foreach ($subscriptions as $subscription) {
            if ($endpoint === $subscription['Endpoint']) {
                $result = $this->sns->unsubscribe([
                    'SubscriptionArn' => $subscription['SubscriptionArn']
                ]);

                return true;
            }
        }

        return false;
    }

    /**
     * Send Subscription Confirmation to SNS Topic
     *
     * SNS Topics require a confirmation to add or remove subscriptions. This
     * method will automatically confirm the subscription change.
     *
     * @param SubscriptionEvent $event The SNS Subscription Event
     */
    public function onSubscription(SubscriptionEvent $event)
    {
        $this->sns->confirmSubscription([
            'TopicArn'  => $event->getTopicArn(),
            'Token'     => $event->getToken()
        ]);
    }

    /**
     * Polls SQS Queue on Notificaiton from SNS
     *
     * Dispatches the `uecode_qpush.message_retrieved` event polling returns
     * SQS Messages
     *
     * @param NotificationEvent $event The SNS Notification Event
     */
    public function onNotify(NotificationEvent $event)
    {
        $messages = $this->receive();
        foreach ($messages as $message) {
            $messageEvent   = $this->createMessageEvent($message);
            $event->getDispatcher()->dispatch(Events::Message($this->name), $messageEvent);
        }
    }

    /**
     * Removes SQS Message from Queue after all other listeners have fired
     *
     * If an earlier listener has errored or stopped propigation, this method
     * will not fire and the Queued Message should become visible in SQS again.
     *
     * Stops Event Propagation after removing the Message
     *
     * @param MessageEvent $event The SQS Message Event
     */
    public function onMessage(MessageEvent $event)
    {
        $this->delete($event->getMetadata()['ReceiptHandle']);
        $event->stopPropagation();
    }
}
