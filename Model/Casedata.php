<?php
/**
 * Copyright 2015 SIGNIFYD Inc. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Signifyd\Connect\Model;

use Signifyd\Connect\Helper\ConfigHelper;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\Order as OrderResourceModel;
use Signifyd\Connect\Logger\Logger;
use Magento\Framework\Serialize\SerializerInterface;
use Signifyd\Connect\Helper\OrderHelper;
use Magento\Sales\Model\ResourceModel\Order\Invoice as InvoiceResourceModel;

/**
 * ORM model declaration for case data
 */
class Casedata extends AbstractModel
{
    /* The status when a case is created */
    const WAITING_SUBMISSION_STATUS     = "waiting_submission";

    /* The status for a case when the first response from Signifyd is received */
    const IN_REVIEW_STATUS              = "in_review";

    /* The status for a case when the case is processing the response */
    const PROCESSING_RESPONSE_STATUS    = "processing_response";

    /* The status for a case that is completed */
    const COMPLETED_STATUS              = "completed";

    /* The status for a case that is awiting async payment methods to finish */
    const ASYNC_WAIT                    = "async_wait";

    /**
     * @var ConfigHelper
     */
    protected $configHelper;

    /**
     * @var InvoiceService
     */
    private $invoiceService;

    /**
     * @var InvoiceSender
     */
    protected $invoiceSender;

    /**
     * @var OrderFactory
     */
    protected $orderFactory;

    /**
     * @var OrderResourceModel
     */
    protected $orderResourceModel;

    /**
     * @var \Magento\Sales\Model\Order
     */
    protected $order;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var SerializerInterface
     */
    protected $serializer;

    /**
     * @var OrderHelper
     */
    protected $orderHelper;

    /**
     * @var InvoiceResourceModel
     */
    protected $invoiceResourceModel;

    /**
     * Casedata constructor.
     * @param Context $context
     * @param Registry $registry
     * @param ConfigHelper $configHelper
     * @param InvoiceService $invoiceService
     * @param InvoiceSender $invoiceSender
     * @param ObjectManagerInterface $objectManager
     * @param OrderFactory $orderFactory
     * @param OrderResourceModel $orderResourceModel
     * @param Logger $logger
     * @param SerializerInterface $serializer
     * @param OrderHelper $orderHelper
     * @param InvoiceResourceModel $invoiceResourceModel
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ConfigHelper $configHelper,
        InvoiceService $invoiceService,
        InvoiceSender $invoiceSender,
        OrderFactory $orderFactory,
        OrderResourceModel $orderResourceModel,
        Logger $logger,
        SerializerInterface $serializer,
        OrderHelper $orderHelper,
        InvoiceResourceModel $invoiceResourceModel
    ) {
        $this->configHelper = $configHelper;
        $this->invoiceService = $invoiceService;
        $this->invoiceSender = $invoiceSender;
        $this->orderFactory = $orderFactory;
        $this->orderResourceModel = $orderResourceModel;
        $this->logger = $logger;
        $this->serializer = $serializer;
        $this->orderHelper = $orderHelper;
        $this->invoiceResourceModel = $invoiceResourceModel;

        parent::__construct($context, $registry);
    }

    /**
     * Constructor
     *
     * @return void
     */
    protected function _construct()
    {
        parent::_construct();
        $this->_init(\Signifyd\Connect\Model\ResourceModel\Casedata::class);
    }

    public function getOrder()
    {
        if (isset($this->order) == false) {
            $orderId = $this->getData('order_id');

            if (empty($orderId) == false) {
                $this->order = $this->orderFactory->create();
                $this->orderResourceModel->load($this->order, $orderId);
            }
        }

        return $this->order;
    }

    /**
     * @param $caseData
     * @return bool
     */
    public function updateCase($response)
    {
        try {
            $order = $this->getOrder();

            if (isset($response->score) && $this->getScore() != $response->score) {
                $this->setScore(floor($response->score));
                $order->setSignifydScore(floor($response->score));
            }

            if (isset($response->status) && $this->getSignifydStatus() != $response->status) {
                $this->setSignifydStatus($response->status);
            }

            if (isset($response->guaranteeDisposition) && $this->getGuarantee() != $response->guaranteeDisposition) {
                $this->setGuarantee($response->guaranteeDisposition);
                $order->setSignifydGuarantee($response->guaranteeDisposition);
            }

            if (isset($response->caseId) && empty($response->caseId) == false) {
                $this->setCode($response->caseId);
                $order->setSignifydCode($response->caseId);
            }

            if (isset($response->testInvestigation)) {
                $this->setEntries('testInvestigation', $response->testInvestigation);
            }
        } catch (\Exception $e) {
            $this->logger->critical($e->__toString(), ['entity' => $this]);
            return false;
        }

        return true;
    }

    /**
     * @param $orderAction
     * @return bool
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function updateOrder()
    {
        $orderAction = $this->handleGuaranteeChange();

        $this->logger->debug(
            $message = "Updating order with action: " . $this->serializer->serialize($orderAction),
            ['entity' => $this]
        );

        $order = $this->getOrder();
        $completeCase = false;

        if (in_array($order->getState(), [Order::STATE_CANCELED, Order::STATE_COMPLETE, Order::STATE_CLOSED])) {
            $orderAction["action"] = 'nothing';
        }

        switch ($orderAction["action"]) {
            case "hold":
                if ($order->canHold()) {
                    try {
                        $order->hold();

                        $completeCase = true;

                        $order->addCommentToStatusHistory("Signifyd: {$orderAction["reason"]}");
                    } catch (\Exception $e) {
                        $this->logger->debug($e->__toString(), ['entity' => $order]);

                        $orderAction['action'] = false;

                        $message = "Signifyd: order cannot be updated to on hold, {$e->getMessage()}";
                        $order->addCommentToStatusHistory($message);
                    }
                } else {
                    $reason = $this->orderHelper->getCannotHoldReason($order);
                    $message = "Order {$order->getIncrementId()} can not be held because {$reason}";
                    $this->logger->debug($message, ['entity' => $order]);
                    $orderAction['action'] = false;
                    $order->addStatusHistoryComment("Signifyd: order cannot be updated to on hold, {$reason}");

                    if ($order->getState() == Order::STATE_HOLDED) {
                        $completeCase = true;
                    }
                }
                break;

            case "unhold":
                if ($order->canUnhold()) {
                    $this->logger->debug('Unhold order action', ['entity' => $order]);
                    try {
                        $order->unhold();

                        $completeCase = true;

                        $order->addStatusHistoryComment("Signifyd: order status updated, {$orderAction["reason"]}");
                    } catch (\Exception $e) {
                        $this->logger->debug($e->__toString(), ['entity' => $order]);

                        $orderAction['action'] = false;

                        $order->addStatusHistoryComment("Signifyd: order status cannot be updated, {$e->getMessage()}");
                    }
                } else {
                    $reason = $this->orderHelper->getCannotUnholdReason($order);

                    $message = "Order {$order->getIncrementId()} ({$order->getState()} > {$order->getStatus()}) " .
                        "can not be removed from hold because {$reason}. " .
                        "Case status: {$this->getSignifydStatus()}";
                    $this->logger->debug($message, ['entity' => $order]);
                    $order->addStatusHistoryComment("Signifyd: order status cannot be updated, {$reason}");
                    $orderAction['action'] = false;

                    if ($reason == "order is not holded") {
                        $completeCase = true;
                    }
                }
                break;

            case "cancel":
                if ($order->canUnhold()) {
                    $order = $order->unhold();
                }

                if ($order->canCancel()) {
                    try {
                        $order->cancel();

                        $completeCase = true;

                        $order->addStatusHistoryComment("Signifyd: order canceled, {$orderAction["reason"]}");
                    } catch (\Exception $e) {
                        $this->logger->debug($e->__toString(), ['entity' => $order]);

                        $orderAction['action'] = false;

                        $order->addStatusHistoryComment("Signifyd: order cannot be canceled, {$e->getMessage()}");
                    }
                } else {
                    $reason = $this->orderHelper->getCannotCancelReason($order);
                    $message = "Order {$order->getIncrementId()} cannot be canceled because {$reason}";
                    $this->logger->debug($message, ['entity' => $order]);
                    $orderAction['action'] = false;
                    $order->addStatusHistoryComment("Signifyd: order cannot be canceled, {$reason}");

                    if ($reason == "all order items are invoiced") {
                        $completeCase = true;
                    }
                }

                if ($orderAction['action'] == false && $order->canHold()) {
                    $order->hold();
                }
                break;

            case "capture":
                try {
                    if ($order->canUnhold()) {
                        $order->unhold();
                    }

                    if ($order->canInvoice()) {
                        /** @var \Magento\Sales\Model\Order\Invoice $invoice */
                        $invoice = $this->invoiceService->prepareInvoice($order);

                        $this->orderHelper->isInvoiceValid($invoice);

                        $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
                        $invoice->addComment('Signifyd: Automatic invoice');
                        $invoice->register();

                        $order->setCustomerNoteNotify(true);
                        $order->setIsInProcess(true);

                        $this->invoiceResourceModel->save($invoice);

                        $order->addCommentToStatusHistory(
                            "Signifyd: create order invoice: {$invoice->getIncrementId()}"
                        );

                        $this->logger->debug(
                            'Invoice was created for order: ' . $order->getIncrementId(),
                            ['entity' => $order]
                        );

                        // Send invoice email
                        try {
                            $this->invoiceSender->send($invoice);
                        } catch (\Exception $e) {
                            $message = 'Failed to send the invoice email: ' . $e->getMessage();
                            $this->logger->debug($message, ['entity' => $order]);
                        }

                        $completeCase = true;
                    } else {
                        $reason = $this->orderHelper->getCannotInvoiceReason($order);
                        $message = "Order {$order->getIncrementId()} can not be invoiced because {$reason}";
                        $this->logger->debug($message, ['entity' => $order]);
                        $orderAction['action'] = false;
                        $order->addCommentToStatusHistory("Signifyd: unable to create invoice: {$reason}");

                        if ($reason == "no items can be invoiced") {
                            $completeCase = true;
                        }

                        if ($order->canHold()) {
                            $order->hold();
                        }
                    }
                } catch (\Exception $e) {
                    $this->logger->debug('Exception creating invoice: ' . $e->__toString(), ['entity' => $order]);

                    if ($order->canHold()) {
                        $order->hold();
                    }

                    $order->addCommentToStatusHistory("Signifyd: unable to create invoice: {$e->getMessage()}");

                    $orderAction['action'] = false;
                }

                break;

            // Do nothing, but do not complete the case on Magento side
            // This action should be used when something is processing on Signifyd end and extension should wait
            // E.g.: Signifyd returns guarantee disposition PENDING because case it is on manual review
            case 'wait':
                $orderAction['action'] = false;
                break;

            // Nothing is an action from Signifyd workflow, different from when no action is given (null or empty)
            // If workflow is set to do nothing, so complete the case
            case 'nothing':
                $orderAction['action'] = false;
                $completeCase = true;
                break;
        }

        if ($completeCase) {
            $this->setMagentoStatus(Casedata::COMPLETED_STATUS)
                ->setUpdated();
        }

        return true;
    }

    /**
     * @param $guaranteeDisposition
     * @return array|string[]
     */
    public function handleGuaranteeChange()
    {
        switch ($this->getGuarantee()) {
            case "DECLINED":
                $result = ["action" => $this->getNegativeAction(), "reason" => "guarantee declined"];
                break;

            case "APPROVED":
                $result = ["action" => $this->getPositiveAction(), "reason" => "guarantee approved"];
                break;

            case 'PENDING':
                $result = ["action" => 'wait', "reason" => 'case in manual review'];
                break;

            default:
                $result = ["action" => '', "reason" => ''];
        }

        $this->logger->debug("Action for {$this->getOrderIncrement()}: {$result['action']}", ['entity' => $this]);

        return $result;
    }

    /**
     * @param null $index
     * @return array|mixed|null
     */
    public function getEntries($index = null)
    {
        $entries = $this->getData('entries_text');

        if (!empty($entries)) {
            try {
                $entries = $this->serializer->unserialize($entries);
            } catch (\InvalidArgumentException $e) {
                $entries = [];
            }
        }

        if (!is_array($entries)) {
            $entries = [];
        }

        if (!empty($index)) {
            return isset($entries[$index]) ? $entries[$index] : null;
        }

        return $entries;
    }

    public function setEntries($index, $value = null)
    {
        if (is_array($index)) {
            $entries = $index;
        } elseif (is_string($index)) {
            $entries = $this->getEntries();
            $entries[$index] = $value;
        }

        $entries = $this->serializer->serialize($entries);
        $this->setData('entries_text', $entries);

        return $this;
    }

    public function isHoldReleased()
    {
        $holdReleased = $this->getEntries('hold_released');
        return (($holdReleased == 1) ? true : false);
    }

    public function getPositiveAction()
    {
        if ($this->isHoldReleased()) {
            return 'nothing';
        } else {
            return $this->configHelper->getConfigData('signifyd/advanced/guarantee_positive_action', $this);
        }
    }

    public function getNegativeAction()
    {
        if ($this->isHoldReleased()) {
            return 'nothing';
        } else {
            return $this->configHelper->getConfigData('signifyd/advanced/guarantee_negative_action', $this);
        }
    }

    /**
     * Everytime a update is triggered reset retries
     *
     * @param $updated
     * @return mixed
     */
    public function setUpdated($updated = null)
    {
        if (empty($updated)) {
            $updated = strftime('%Y-%m-%d %H:%M:%S', time());
        }

        $this->setRetries(0);

        return parent::setUpdated($updated);
    }
}
