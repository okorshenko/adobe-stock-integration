<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\AdobeStockClient\Model;

use AdobeStock\Api\Client\AdobeStock;
use AdobeStock\Api\Core\Constants;
use AdobeStock\Api\Exception\StockApi;
use AdobeStock\Api\Models\SearchParameters;
use AdobeStock\Api\Models\StockFile;
use AdobeStock\Api\Request\SearchFiles as SearchFilesRequest;
use \Magento\AdobeStockClientApi\Api\ExceptionManagerInterface;
use \Magento\AdobeStockClientApi\Api\SearchParameterProviderInterface;
use Magento\Framework\Api\AttributeValue;
use Magento\Framework\Api\Search\DocumentFactory;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\AdobeStockClientApi\Api\ClientInterface;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\Api\Search\SearchResultFactory;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Locale\ResolverInterface as LocaleResolver;

/**
 * Client for communication to Adobe Stock API
 */
class Client implements ClientInterface
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var SearchResultFactory
     */
    private $searchResultFactory;

    /**
     * @var DocumentFactory
     */
    private $documentFactory;

    /**
     * @var AttributeValueFactory
     */
    private $attributeValueFactory;

    /**
     * @var SearchParameterProviderInterface
     */
    private $searchParametersProvider;

    /**
     * @var LocaleResolver
     */
    private $localeResolver;

    /**
     * @var ExceptionManagerInterface
     */
    private $exceptionManager;

    /**
     * Client constructor.
     *
     * @param Config                           $config
     * @param DocumentFactory                  $documentFactory
     * @param SearchResultFactory              $searchResultFactory
     * @param AttributeValueFactory            $attributeValueFactory
     * @param SearchParameterProviderInterface $searchParametersProvider
     * @param LocaleResolver                   $localeResolver
     * @param ExceptionManagerInterface        $exceptionManager
     */
    public function __construct(
        Config $config,
        DocumentFactory $documentFactory,
        SearchResultFactory $searchResultFactory,
        AttributeValueFactory $attributeValueFactory,
        SearchParameterProviderInterface $searchParametersProvider,
        LocaleResolver $localeResolver,
        ExceptionManagerInterface $exceptionManager
    ) {
        $this->config = $config;
        $this->documentFactory = $documentFactory;
        $this->searchResultFactory = $searchResultFactory;
        $this->attributeValueFactory = $attributeValueFactory;
        $this->searchParametersProvider = $searchParametersProvider;
        $this->localeResolver = $localeResolver;
        $this->exceptionManager = $exceptionManager;
    }

    /**
     * @inheritdoc
     */
    public function search(SearchCriteriaInterface $searchCriteria): SearchResultInterface
    {
        $response = $this->processSearchRequest($searchCriteria);
        $items = [];
        /** @var StockFile $file */
        foreach ($response->getFiles() as $file) {
            $itemData = (array) $file;
            $itemData['url'] = $itemData['thumbnail_500_url'];
            $itemId = $itemData['id'];
            $attributes = $this->createAttributes('id', $itemData);

            $item = $this->documentFactory->create();
            $item->setId($itemId);
            $item->setCustomAttributes($attributes);
            $items[] = $item;
        }

        $searchResult = $this->searchResultFactory->create();
        $searchResult->setSearchCriteria($searchCriteria);
        $searchResult->setItems($items);
        $searchResult->setTotalCount($response->getNbResults());
        return $searchResult;
    }

    /**
     * @param SearchCriteriaInterface $searchCriteria
     *
     * @return \AdobeStock\Api\Response\SearchFiles
     */
    private function processSearchRequest(SearchCriteriaInterface $searchCriteria)
    {
        try {
            $searchParams = $this->searchParametersProvider->apply($searchCriteria, new SearchParameters());

            $resultsColumns = Constants::getResultColumns();
            $resultColumnArray = [];
            foreach ($this->config->getSearchResultFields() as $field) {
                $resultColumnArray[] = $resultsColumns[$field];
            }

            $searchRequest = new SearchFilesRequest();
            $searchRequest->setLocale($this->localeResolver->getLocale());
            $searchRequest->setSearchParams($searchParams);
            $searchRequest->setResultColumns($resultColumnArray);

            $client = $this->getClient()->searchFilesInitialize($searchRequest, $this->getAccessToken());
            $response = $client->getNextResponse();

            return $response;
        } catch (StockApi $exception) {
            $this->exceptionManager->processException(
                $exception,
                $exception->getCode()
            );
        } catch (\Exception $exception) {
            $this->exceptionManager->processException(
                $exception,
                $exception->getCode()
            );
        }
        finally {
            $unknownException = 'An Unknown exception happened during search request.';
            $exception = new \Exception(
                $unknownException,
                ExceptionManagerInterface::DEFAULT_CLIENT_EXCEPTION_CODE,
                null
            );
            $this->exceptionManager->processException(
                $exception,
                ExceptionManagerInterface::FORBIDDEN_CONNECTION_ERROR_CODE
            );
        }
    }

    /**
     * @param string $idFieldName
     * @param array $itemData
     * @return AttributeValue[]
     */
    private function createAttributes(string $idFieldName, array $itemData): array
    {
        $attributes = [];

        $idFieldNameAttribute = $this->attributeValueFactory->create();
        $idFieldNameAttribute->setAttributeCode('id_field_name');
        $idFieldNameAttribute->setValue($idFieldName);
        $attributes['id_field_name'] = $idFieldNameAttribute;

        foreach ($itemData as $key => $value) {
            if ($value === null) {
                continue;
            }
            $attribute = $this->attributeValueFactory->create();
            $attribute->setAttributeCode($key);
            if (is_bool($value)) {
                // for proper work of form and grid (for example for Yes/No properties)
                $value = (string)(int)$value;
            }
            $attribute->setValue($value);
            $attributes[$key] = $attribute;
        }
        return $attributes;
    }

    /**
     * TODO: Implement retriving of an access token
     *
     * @return null
     */
    private function getAccessToken()
    {
        return null;
    }

    /**
     * @return AdobeStock
     */
    private function getClient()
    {
        return new AdobeStock(
            $this->config->getApiKey(),
            $this->config->getProductName(),
            $this->config->getTargetEnvironment()
        );
    }

    /**
     * @inheritdoc
     */
    public function testConnection()
    {
        //TODO: should be refactored
        $searchParams = new SearchParameters();
        $searchRequest = new SearchFilesRequest();
        $resultColumnArray = [];

        $resultColumnArray[] = 'nb_results';

        $searchRequest->setLocale('en_GB');
        $searchRequest->setSearchParams($searchParams);
        $searchRequest->setResultColumns($resultColumnArray);

        $client = $this->getClient()->searchFilesInitialize($searchRequest, $this->getAccessToken());

        return (bool) $client->getNextResponse()->nb_results;
    }
}
