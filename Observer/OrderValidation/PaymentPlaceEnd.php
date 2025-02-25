<?php

namespace Forter\Forter\Observer\OrderValidation;

use Forter\Forter\Helper\EntityHelper;
use Forter\Forter\Model\AbstractApi;
use Forter\Forter\Model\ActionsHandler\Approve;
use Forter\Forter\Model\ActionsHandler\Decline;
use Forter\Forter\Model\Config;
use Forter\Forter\Model\Entity as ForterEntity;
use Forter\Forter\Model\EntityFactory as ForterEntityFactory;
use Forter\Forter\Model\ForterLogger;
use Forter\Forter\Model\ForterLoggerMessage;
use Forter\Forter\Model\QueueFactory as ForterQueueFactory;
use Forter\Forter\Model\RequestBuilder\Order;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Registry;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Class PaymentPlaceEnd
 * @package Forter\Forter\Observer\OrderValidation
 */
class PaymentPlaceEnd implements ObserverInterface
{
    public const VALIDATION_API_ENDPOINT = 'https://api.forter-secure.com/v2/orders/';

    const FORTER_STATUS_WAITING = "waiting_for_data";
    const FORTER_STATUS_PRE_POST_VALIDATION = "pre_post_validation";
    const FORTER_STATUS_COMPLETE = "complete";
    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var ManagerInterface
     */
    private $messageManager;

    /**
     * @var Decline
     */
    private $decline;

    /**
     * @var ForterQueueFactory
     */
    private $queue;

    /**
     * @var Approve
     */
    private $approve;

    /**
     * @var AbstractApi
     */
    private $abstractApi;

    /**
     * @var Config
     */
    private $forterConfig;

    /**
     * @var Order
     */
    private $requestBuilderOrder;

    /**
     * @var OrderManagementInterface
     */
    private $orderManagement;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var DateTime
     */
    private $dateTime;

    /**
     * @var CustomerSession
     */
    private $customerSession;

    /**
     * @var Registry
     */
    private $registry;
    /**
     * @var Emulation
     */
    private $emulate;

    /**
     * @var ForterLogger
     */
    private $forterLogger;

    /**
     * @var ForterEntity
     */
    protected $forterEntity;
    /**
     * @var ForterEntityFactory
     */
    protected $forterEntityFactory;
    /**
     * @var EntityHelper
     */
    protected $entityHelper;

    /**
     * @method __construct
     * @param  ScopeConfigInterface     $scopeConfig
     * @param  CustomerSession          $customerSession
     * @param  ManagerInterface         $messageManager
     * @param  ForterQueueFactory       $queue
     * @param  Decline                  $decline
     * @param  Approve                  $approve
     * @param  DateTime                 $dateTime
     * @param  AbstractApi              $abstractApi
     * @param  Config                   $forterConfig
     * @param  Order                    $requestBuilderOrder
     * @param  OrderManagementInterface $orderManagement
     * @param  StoreManagerInterface    $storeManager
     * @param  Registry                 $registry
     * @param  ForterLogger             $forterLogger
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        CustomerSession $customerSession,
        ManagerInterface $messageManager,
        ForterQueueFactory $queue,
        Decline $decline,
        Approve $approve,
        DateTime $dateTime,
        AbstractApi $abstractApi,
        Config $forterConfig,
        Order $requestBuilderOrder,
        OrderManagementInterface $orderManagement,
        StoreManagerInterface $storeManager,
        Registry $registry,
        Emulation $emulate,
        ForterLogger $forterLogger,
        ForterEntity $forterEntity,
        ForterEntityFactory $forterEntityFactory,
        EntityHelper $entityHelper
    ) {
        $this->customerSession = $customerSession;
        $this->messageManager = $messageManager;
        $this->dateTime = $dateTime;
        $this->storeManager = $storeManager;
        $this->decline = $decline;
        $this->approve = $approve;
        $this->scopeConfig = $scopeConfig;
        $this->abstractApi = $abstractApi;
        $this->forterConfig = $forterConfig;
        $this->requestBuilderOrder = $requestBuilderOrder;
        $this->orderManagement = $orderManagement;
        $this->queue = $queue;
        $this->registry = $registry;
        $this->emulate = $emulate;
        $this->forterLogger = $forterLogger;
        $this->forterEntity = $forterEntity;
        $this->forterEntityFactory = $forterEntityFactory;
        $this->entityHelper = $entityHelper;
    }

    /**
     * @param \Magento\Framework\Event\Observer $observer
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        try {
            $order = $observer->getEvent()->getPayment()->getOrder();
            $paymentMethod = $order->getPayment()->getMethod();
            $subMethod = $order->getData('sub_payment_method') ? $order->getData('sub_payment_method') : $order->getPayment()->getCcType(); // This will return 'googlepay', 'applepay', etc.
            $methodSetting = $this->forterConfig->getMappedPrePos($paymentMethod, $subMethod);

            if (!$this->forterConfig->isEnabled(null, $order->getStoreId())) {
                if ($this->registry->registry('forter_pre_decision')) {
                    $this->logForterPreDecision($observer->getEvent()->getPayment()->getOrder());
                }
                return;
            }

            $forterEntity = $this->entityHelper->getForterEntityByIncrementId($order->getIncrementId());
            if (in_array($paymentMethod, $this->forterConfig->asyncPaymentMethods()) && !$forterEntity) {
                $validationType = 'post-authorization';
                $this->entityHelper->createForterEntity($order, $order->getStoreId(), $validationType);
                return;
            }

            if ($methodSetting && $methodSetting !== 'post' && $methodSetting !== 'prepost') {
                if ($this->registry->registry('forter_pre_decision')) {
                    $this->logForterPreDecision($observer->getEvent()->getPayment()->getOrder());
                }
                return;
            }

            if (!$methodSetting && !$this->forterConfig->getIsPost() && !$this->forterConfig->getIsPreAndPost()) {
                if ($this->registry->registry('forter_pre_decision')) {
                    $this->logForterPreDecision($observer->getEvent()->getPayment()->getOrder());
                }
                return;
            }

            $this->emulate->stopEnvironmentEmulation();
            $this->clearTempSessionParams();
            $order = $observer->getEvent()->getPayment()->getOrder();
            // let bind the relevent store in case of multi store settings
            $this->emulate->startEnvironmentEmulation(
                $order->getStoreId(),
                'frontend',
                true
            );

            if (!$forterEntity) {
                $validationType = 'post-authorization';
                $forterEntity = $this->entityHelper->createForterEntity($order, $order->getStoreId(), $validationType);
            }

            $payment = $order->getPayment();

            if ($this->forterConfig->getIsPaymentMethodAccepted($paymentMethod) && !$this->forterConfig->getIsReadyForForter($payment)) { //de adaugat aici metodele agreate cu cc_trans_id populat, in rest sa mearga mai departe
                $forterEntity->setStatus(self::FORTER_STATUS_WAITING)
                    ->save();
                if ($this->forterConfig->isHoldingOrdersEnabled()) {
                    $order->canHold() ?? $order->hold()->save();
                }
                return;
            }

            $data = $this->requestBuilderOrder->buildTransaction($order, 'AFTER_PAYMENT_ACTION');
            $url = self::VALIDATION_API_ENDPOINT . $order->getIncrementId();
            $forterResponse = $this->abstractApi->sendApiRequest($url, json_encode($data));

            $retries = $forterEntity->getRetries();
            $forterEntity->setRetries($retries++);
            $this->forterConfig->log(' Request Data for Order ' . $order->getIncrementId() . ': ' . json_encode($data));

            $this->abstractApi->sendOrderStatus($order);

            $this->forterConfig->log('Forter Response for Order ' . $order->getIncrementId() . ': ' . $forterResponse);

            $forterResponse = json_decode($forterResponse);
            if ($forterResponse->status != 'success' || !isset($forterResponse->action)) {
                $forterEntity->setForterStatus('error');
                $order->addStatusHistoryComment(__('Forter (post) Decision: %1', 'error'));
                $this->forterConfig->log('Response Error for Order ' . $order->getIncrementId() . ' - Payment Data: ' . json_encode($order->getPayment()->getData()));
                $order->save();
                $message = new ForterLoggerMessage($this->forterConfig->getSiteId(), $order->getIncrementId(), 'Post-Auth');
                $message->metaData->order = $order->getData();
                $message->metaData->payment = $order->getPayment()->getData();
                $message->metaData->decision = $forterResponse->action ?? null;
                $this->forterLogger->SendLog($message);
                $this->entityHelper->updateForterEntity($forterEntity, $order, $forterResponse, $message);
                $forterEntity->save();
                return;
            }

            $forterEntity->setSyncFlag(1);
            $order->addStatusHistoryComment(__('Forter (post) Decision: %1%2', $forterResponse->action, $this->forterConfig->getResponseRecommendationsNote($forterResponse)));
            $order->addStatusHistoryComment(__('Forter (post) Decision Reason: %1', $forterResponse->reasonCode));
            $this->handleResponse($forterResponse->action ?? '', $order, $forterEntity);

            $message = new ForterLoggerMessage($this->forterConfig->getSiteId(), $order->getIncrementId(), 'Post-Auth');
            $message->metaData->order = $order->getData();
            $message->metaData->payment = $order->getPayment()->getData();
            $message->metaData->decision = $forterResponse->action;
            $this->forterLogger->SendLog($message);
            $this->forterConfig->log('Order ' . $order->getIncrementId() . ' Payment Data: ' . json_encode($order->getPayment()->getData()));
            $this->entityHelper->updateForterEntity($forterEntity, $order, $forterResponse, $message, null);
            $forterEntity->save();
            $this->abstractApi->triggerRecommendationEvents($forterResponse, $order, 'post');
        } catch (\Exception $e) {
            $this->abstractApi->reportToForterOnCatch($e);
        }
    }

    public function handleResponse($forterDecision, $order, $forterEntity)
    {
        if ($forterDecision == "decline") {
            $this->handleDecline($order, $forterEntity);
        } elseif ($forterDecision == 'approve') {
            $this->handleApprove($order, $forterEntity);
        } elseif ($forterDecision == "not reviewed") {
            $this->handleNotReviewed($order, $forterEntity);
        } elseif ($forterDecision == "pending" && $this->forterConfig->isPendingOnHoldEnabled()) {
            if ($order->canHold()) {
                $this->decline->holdOrder($order);
            }
        }
        if ($this->forterConfig->isDebugEnabled()) {
            $message = new ForterLoggerMessage($order->getStore()->getWebsiteId(), $order->getIncrementId(), 'Handling Order With Forter');
            $message->metaData->order = $order->getData();
            $message->metaData->payment = $order->getPayment()->getData();
            $message->metaData->forterDecision = $forterDecision;
            $message->metaData->pendingOnHoldEnabled = $this->forterConfig->isPendingOnHoldEnabled();
            $this->forterLogger->SendLog($message);
        }

        $forterEntity->setEntityType('order');
        $forterEntity->save();
    }

    public function handleDecline($order, $forterEntity)
    {
        $this->decline->sendDeclineMail($order);
        $result = $this->forterConfig->getDeclinePost();
        if ($result == '1') {
            $this->customerSession->setForterMessage($this->forterConfig->getPostThanksMsg());

            // the order will be canceled only if the order is in hold state and the force holding orders is disabled
            if ($this->forterConfig->forceHoldingOrders() && !$order->canHold()) {
                $order->setState(\Magento\Sales\Model\Order::STATE_PROCESSING);
            }

            if ($order->canHold()) {
                $order->setCanSendNewEmailFlag(false);
                $this->decline->holdOrder($order);
            }

            $this->setMessage($order, 'decline', $forterEntity);

        } elseif ($result == '2') {
            $order->setCanSendNewEmailFlag(false);
            $this->decline->markOrderPaymentReview($order);
        }
    }

    public function handleApprove($order, $forterEntity)
    {
        $result = $this->forterConfig->getApprovePost();
        if ($result == '1') {
            $this->setMessage($order, 'approve', $forterEntity);
        }
    }

    public function handleNotReviewed($order, $forterEntity)
    {
        $result = $this->forterConfig->getNotReviewPost();
        if ($result == '1') {
            $this->setMessage($order, 'approve', $forterEntity);
        }
    }

    public function setMessage($order, $type, $forterEntity)
    {
        $storeId = $order->getStore()->getId();
        $currentTime = $this->dateTime->gmtDate();
        $this->forterConfig->log('Increment ID:' . $order->getIncrementId());
        if ($this->forterConfig->isDebugEnabled()) {
            $message = new ForterLoggerMessage($this->forterConfig->getSiteId(), $order->getIncrementId(), 'processing message to queue');
            $message->metaData->order = $order->getData();
            $message->metaData->payment = $order->getPayment();
            $message->metaData->currentTime = $currentTime;
            $this->forterLogger->SendLog($message);
        }
        $forterEntity->setEntityType('order');
        $forterEntity->setEntityBody($type);
        $forterEntity->save();
    }

    private function clearTempSessionParams()
    {
        $this->customerSession->unsForterBin();
        $this->customerSession->unsForterLast4cc();
    }

    protected function logForterPreDecision($order)
    {
        $order->addStatusHistoryComment(__('Forter (pre) Decision: %1', $this->registry->registry('forter_pre_decision')));
        $order->save();
        $message = new ForterLoggerMessage($this->forterConfig->getSiteId(), $order->getIncrementId(), 'Pre-Auth');
        $message->metaData->order = $order->getData();
        $message->metaData->payment = $order->getPayment()->getData();
        $this->forterConfig->log('Order ' . $order->getIncrementId() . ' Payment Data: ' . json_encode($order->getPayment()->getData()));
        $this->forterLogger->SendLog($message);
    }
}