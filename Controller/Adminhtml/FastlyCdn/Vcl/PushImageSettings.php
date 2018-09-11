<?php
/**
 * Fastly CDN for Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Fastly CDN for Magento End User License Agreement
 * that is bundled with this package in the file LICENSE_FASTLY_CDN.txt.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Fastly CDN to newer
 * versions in the future. If you wish to customize this module for your
 * needs please refer to http://www.magento.com for more information.
 *
 * @category    Fastly
 * @package     Fastly_Cdn
 * @copyright   Copyright (c) 2016 Fastly, Inc. (http://www.fastly.com)
 * @license     BSD, see LICENSE_FASTLY_CDN.txt
 */
namespace Fastly\Cdn\Controller\Adminhtml\FastlyCdn\Vcl;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\JsonFactory;
use Fastly\Cdn\Model\Config;
use Fastly\Cdn\Model\Api;
use Fastly\Cdn\Helper\Vcl;
use Fastly\Cdn\Model\Product\Image;
use Magento\Framework\Exception\LocalizedException;

/**
 * Class PushImageSettings
 *
 * @package Fastly\Cdn\Controller\Adminhtml\FastlyCdn\Vcl
 */
class PushImageSettings extends Action
{
    /**
     * VCL snippet names
     */
    const CONDITION_NAME = 'fastly-image-optimizer-condition';
    const HEADER_NAME = 'fastly-image-optimizer-header';
    const VCL_SNIPPET_PATH = '/vcl_snippets_image_optimizations';

    /**
     * @var Http
     */
    private $request;

    /**
     * @var JsonFactory
     */
    private $resultJson;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var \Fastly\Cdn\Model\Api
     */
    private $api;

    /**
     * @var Vcl
     */
    private $vcl;

    /**
     * @var Image
     */
    private $image;

    /**
     * PushImageSettings constructor.
     *
     * @param Context $context
     * @param Http $request
     * @param JsonFactory $resultJsonFactory
     * @param Config $config
     * @param Api $api
     * @param Vcl $vcl
     * @param Image $image
     */
    public function __construct(
        Context $context,
        Http $request,
        JsonFactory $resultJsonFactory,
        Config $config,
        Api $api,
        Vcl $vcl,
        Image $image
    ) {
        $this->request = $request;
        $this->resultJson = $resultJsonFactory;
        $this->config = $config;
        $this->api = $api;
        $this->vcl = $vcl;
        $this->image = $image;

        parent::__construct($context);
    }

    /**
     * @return $this|\Magento\Framework\App\ResponseInterface|\Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $result = $this->resultJson->create();
        try {
            $activeVersion = $this->getRequest()->getParam('active_version');
            $activateVcl = $this->getRequest()->getParam('activate_flag');
            $imageQualityFlag = $this->getRequest()->getParam('image_quality_flag');
            $imageQuality = $this->image->getQuality();
            $service = $this->api->checkServiceDetails();
            $currActiveVersion = $this->getActiveVersion($service, $activeVersion);

            $clone = $this->api->cloneVersion($currActiveVersion['active_version']);

            $reqName = Config::FASTLY_MAGENTO_MODULE . '_image_optimization';
            $checkIfReqExist = $this->api->getRequest($activeVersion, $reqName);
            $snippet = $this->config->getVclSnippets(self::VCL_SNIPPET_PATH, 'recv.vcl');

            $condition = [
                'name' => Config::FASTLY_MAGENTO_MODULE . '_image_optimization',
                'statement' => 'req.http.x-pass',
                'type'      => 'REQUEST',
                'priority'  => 5
            ];

            $createCondition = $this->api->createCondition($clone->number, $condition);

            if (!$checkIfReqExist) {
                $request = [
                    'name' => $reqName,
                    'service_id' => $service->id,
                    'version' => $currActiveVersion['active_version'],
                    'request_condition' => $createCondition->name
                ];

                $this->api->createRequest($clone->number, $request);

                foreach ($snippet as $key => $value) {
                    $snippetData = [
                        'name' => Config::FASTLY_MAGENTO_MODULE . '_image_optimization_' . $key,
                        'type' => $key,
                        'dynamic' => "0",
                        'content' => $value,
                        'priority' => 10
                    ];

                    $this->api->uploadSnippet($clone->number, $snippetData);

                    $id = $service->id . '-' . $clone->number . '-imageopto';
                    $imageParams = json_encode([
                        'data' => [
                            'id'            => $id,
                            'type'          => 'io_settings',
                            'attributes'    => [
                                'jpeg_quality'  => $imageQuality
                            ]
                        ]
                    ]);

                    if ($imageQualityFlag === 'true') {
                        $this->api->configureImageOptimizationDefaultConfigOptions($imageParams, $clone->number);
                    }
                }
            } else {
                $this->api->deleteRequest($clone->number, $reqName);

                // Remove image optimization snippet
                foreach ($snippet as $key => $value) {
                    $name = Config::FASTLY_MAGENTO_MODULE . '_image_optimization_' . $key;
                    if ($this->api->hasSnippet($clone->number, $name) == true) {
                        $this->api->removeSnippet($clone->number, $name);
                    }
                }
            }

            $this->api->validateServiceVersion($clone->number);

            if ($activateVcl === 'true') {
                $this->api->activateVersion($clone->number);
            }

            if ($this->config->areWebHooksEnabled() && $this->config->canPublishConfigChanges()) {
                if ($checkIfReqExist) {
                    $this->api->sendWebHook(
                        '*Image optimization snippet has been removed in Fastly version ' . $clone->number . '*'
                    );
                } else {
                    $this->api->sendWebHook(
                        '*Image optimization snippet has been pushed in Fastly version ' . $clone->number . '*'
                    );
                }
            }
            // Validate before sending success
            return $result->setData([
                'status' => true
            ]);
        } catch (\Exception $e) {
            return $result->setData([
                'status' => false,
                'msg' => $e->getMessage()
            ]);
        }
    }

    /**
     * Fetches and validates active version
     *
     * @param $service
     * @param $activeVersion
     * @return array
     * @throws LocalizedException
     */
    private function getActiveVersion($service, $activeVersion)
    {
        $currActiveVersion = $this->vcl->determineVersions($service->versions);
        if ($currActiveVersion['active_version'] != $activeVersion) {
            throw new LocalizedException(__('Active versions mismatch.'));
        }
        return $currActiveVersion;
    }

    /**
     * Adjusts the status of the config
     *
     * @param bool $status
     */
    private function setStatus($status)
    {
        $this->configWriter->save(Config::XML_FASTLY_IMAGE_OPTIMIZATIONS, (bool)$status);

        /** @var \Magento\Framework\App\Cache\Manager $cacheManager */
        $cacheManager = $this->cacheFactory->create();

        $cacheManager->flush([\Magento\Framework\App\Cache\Type\Config::TYPE_IDENTIFIER]);
    }
}
