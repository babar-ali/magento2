<?php
/**
 * Copyright 2019 SIGNIFYD Inc. All rights reserved.
 * See LICENSE.txt for license details.
 */
namespace Signifyd\Connect\Controller\Webhooks;

use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Response\Http;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Model\ResourceModel\Order as OrderResourceModel;
use Signifyd\Connect\Logger\Logger;
use Signifyd\Connect\Helper\ConfigHelper;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Filesystem\Driver\File;
use Signifyd\Connect\Model\Casedata;
use Signifyd\Connect\Model\CasedataFactory;
use Signifyd\Connect\Model\ResourceModel\Casedata as CasedataResourceModel;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Framework\App\ResourceConnection;

/**
 * Controller action for handling webhook posts from Signifyd service
 */
class Index extends Action
{
    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var ConfigHelper
     */
    protected $configHelper;

    /**
     * @var File
     */
    protected $file;

    /**
     * @var CasedataFactory
     */
    protected $casedataFactory;

    /**
     * @var CasedataResourceModel
     */
    protected $casedataResourceModel;

    /**
     * @var OrderResourceModel
     */
    protected $orderResourceModel;

    /**
     * @var JsonSerializer
     */
    protected $jsonSerializer;

    /**
     * @var ResourceConnection
     */
    protected $resourceConnection;

    /**
     * Index constructor.
     * @param Context $context
     * @param DateTime $dateTime
     * @param Logger $logger
     * @param ConfigHelper $configHelper
     * @param FormKey $formKey
     * @param File $file
     * @param CasedataFactory $casedataFactory
     * @param CasedataResourceModel $casedataResourceModel
     * @param OrderResourceModel $orderResourceModel
     * @param JsonSerializer $jsonSerializer
     * @param ResourceConnection $resourceConnection
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function __construct(
        Context $context,
        DateTime $dateTime,
        Logger $logger,
        ConfigHelper $configHelper,
        FormKey $formKey,
        File $file,
        CasedataFactory $casedataFactory,
        CasedataResourceModel $casedataResourceModel,
        OrderResourceModel $orderResourceModel,
        JsonSerializer $jsonSerializer,
        ResourceConnection $resourceConnection
    ) {
        parent::__construct($context);

        $this->logger = $logger;
        $this->configHelper = $configHelper;
        $this->file = $file;
        $this->casedataFactory = $casedataFactory;
        $this->casedataResourceModel = $casedataResourceModel;
        $this->orderResourceModel = $orderResourceModel;
        $this->jsonSerializer = $jsonSerializer;
        $this->resourceConnection = $resourceConnection;

        // Compatibility with Magento 2.3+ which required form_key on every request
        // Magento expects class to implement \Magento\Framework\App\CsrfAwareActionInterface but this causes
        // a backward incompatibility to Magento versions below 2.3
        if (interface_exists(\Magento\Framework\App\CsrfAwareActionInterface::class)) {
            $request = $this->getRequest();
            if ($request instanceof RequestInterface && $request->isPost() && empty($request->getParam('form_key'))) {
                $request->setParam('form_key', $formKey->getFormKey());
            }
        }
    }

    /**
     * @return string
     */
    protected function getRawPost()
    {
        if (isset($HTTP_RAW_POST_DATA) && $HTTP_RAW_POST_DATA) {
            return $HTTP_RAW_POST_DATA;
        }

        $post = $this->file->fileGetContents("php://input");

        if ($post) {
            return $post;
        }

        return '';
    }

    public function execute()
    {
        $request = $this->getRawPost();
        $hash = $this->getRequest()->getHeader('X-SIGNIFYD-SEC-HMAC-SHA256');
        $topic = $this->getRequest()->getHeader('X-SIGNIFYD-TOPIC');

        $this->logger->debug('WEBHOOK: request: ' . $request);
        $this->logger->debug('WEBHOOK: request hash: ' . $hash);
        $this->logger->debug('WEBHOOK: request topic: ' . $topic);

        return $this->processRequest($request, $hash, $topic);
    }

    public function processRequest($request, $hash, $topic)
    {
        if (empty($hash) || empty($request)) {
            $this->getResponse()->appendBody("You have successfully reached the webhook endpoint");
            $this->getResponse()->setStatusCode(Http::STATUS_CODE_200);
            return;
        }

        try {
            $requestJson = (object) $this->jsonSerializer->unserialize($request);
        } catch (\InvalidArgumentException $e) {
            $message = 'Invalid JSON provided on request body';
            $this->getResponse()->appendBody($message);
            $this->logger->debug("WEBHOOK: {$message}");
            $this->getResponse()->setStatusCode(Http::STATUS_CODE_400);
            return;
        }

        switch (($topic)) {
            case 'cases/test':
                // Test is only verifying that the endpoint is reachable. So we just complete here
                $this->getResponse()->setStatusCode(Http::STATUS_CODE_200);
                return;

            case 'cases/creation':
                $message = 'Case creation will not be processed by Magento';
                $this->getResponse()->appendBody($message);
                $this->logger->debug("WEBHOOK: {$message}");
                $this->getResponse()->setStatusCode(Http::STATUS_CODE_200);
                return;
        }

        /** @var $case \Signifyd\Connect\Model\Casedata */
        $case = $this->casedataFactory->create();
        $this->casedataResourceModel->load($case, $requestJson->caseId, 'code');

        if ($case->isEmpty()) {
            $message = "Case {$requestJson->orderId} on request not found on Magento";
            $this->getResponse()->appendBody($message);
            $this->logger->debug("WEBHOOK: {$message}");
            $this->getResponse()->setStatusCode(Http::STATUS_CODE_400);
            return;
        }

        if ($case->getMagentoStatus() == Casedata::WAITING_SUBMISSION_STATUS) {
            $message = "Case {$requestJson->orderId} it is not ready to be updated";
            $this->getResponse()->appendBody($message);
            $this->logger->debug("WEBHOOK: {$message}");
            $this->getResponse()->setStatusCode(Http::STATUS_CODE_400);
            return;
        }

        if ($this->configHelper->isEnabled($case) == false) {
            $message = 'This plugin is not currently enabled';
            $this->getResponse()->appendBody($message);
            $this->logger->debug("WEBHOOK: {$message}");
            $this->getResponse()->setStatusCode(Http::STATUS_CODE_400);
            return;
        }

        $signifydApi = $this->configHelper->getSignifydApi($case);

        if ($signifydApi->validWebhookRequest($request, $hash, $topic)) {
            $this->logger->info("Processing case {$case->getData('order_increment')} ({$case->getData('order_id')})");

            try {
                $this->resourceConnection->getConnection()->beginTransaction();
                $this->casedataResourceModel->loadForUpdate($case, $case->getData('code'), 'code');

                $case->updateCase($requestJson);
                $case->updateOrder();

                $this->casedataResourceModel->save($case);
                $this->orderResourceModel->save($case->getOrder());
                $this->resourceConnection->getConnection()->commit();

                $this->getResponse()->setStatusCode(Http::STATUS_CODE_200);
            } catch (\Exception $e) {
                $this->resourceConnection->getConnection()->rollBack();
                $this->logger->error('Failed to save case data to database: ' . $e->getMessage());

                $this->getResponse()->setStatusCode(Http::STATUS_CODE_403);
            }
        } else {
            $this->getResponse()->setStatusCode(Http::STATUS_CODE_403);
        }
    }
}
