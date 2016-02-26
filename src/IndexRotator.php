<?php

namespace Zumba\ElasticsearchRotator;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Elasticsearch\Client;

class IndexRotator
{
	const INDEX_NAME_CONFIG = '.%s_configuration';
	const TYPE_CONFIGURATION = 'configuration';
	const PRIMARY_ID = 'primary';
	const RETRY_TIME_COPY = 500000;
	const MAX_RETRY_COUNT = 5;

	/**
	 * Elasticsearch client instance.
	 *
	 * @var \Elasticsearch\Client
	 */
	private $engine;

	/**
	 * Prefix identifier for this index.
	 *
	 * @var string
	 */
	private $prefix;

	/**
	 * Configuration index name for this index.
	 *
	 * @var string
	 */
	private $configurationIndexName;

	/**
	 * Mapping for configuration index.
	 *
	 * @var array
	 */
	public static $elasticSearchConfigurationMapping = [
		'mappings' => [
			'configuration' => [
				'properties' => [
					'name' => ['type' => 'string', 'index' => 'not_analyzed'],
					'timestamp' => ['type' => 'date']
				]
			]
		]
	];
	/**
	 * Constructor.
	 *
	 * @param \Elasticsearch\Client $engine
	 * @param string $prefix Identifier for who's configuration this is intended.
	 * @param Psr\Log\LoggerInterface $logger
	 */
	public function __construct(\Elasticsearch\Client $engine, $prefix, LoggerInterface $logger = null)
	{
		$this->engine = $engine;
		$this->prefix = $prefix;
		if ($logger !== null) {
			$this->logger = $logger;
		} else {
			$this->logger = new NullLogger();
		}
		$this->configurationIndexName = sprintf(static::INDEX_NAME_CONFIG, $this->prefix);
	}

	/**
	 * Get the primary index name for this configuration.
	 *
	 * @return string
	 * @throws \ElasticsearchRotator\Exceptions\MissingPrimaryException
	 */
	public function getPrimaryIndex()
	{
		if (!$this->engine->indices()->exists(['index' => $this->configurationIndexName])) {
			throw new Exception\MissingPrimaryIndex('Configuration index not available.');
		}
		$primaryPayload = [
			'index' => $this->configurationIndexName,
			'type' => static::TYPE_CONFIGURATION,
			'id' => static::PRIMARY_ID
		];
		try {
			$primary = $this->engine->get($primaryPayload);
		} catch (\Elasticsearch\Common\Exceptions\Missing404Exception $e) {
			throw new Exception\MissingPrimaryIndex('Configuration index not available.');
		}
		return $primary['_source']['name'];
	}

	/**
	 * Sets the primary index for searches using this configuration.
	 *
	 * @param string $name Index name for the primary index to use.
	 * @return void
	 */
	public function setPrimaryIndex($name)
	{
		if (!$this->engine->indices()->exists(['index' => $this->configurationIndexName])) {
			$this->createCurrentIndexConfiguration();
		}
		$this->engine->index([
			'index' => $this->configurationIndexName,
			'type' => static::TYPE_CONFIGURATION,
			'id' => static::PRIMARY_ID,
			'body' => [
				'name' => $name,
				'timestamp' => time()
			]
		]);
		$this->logger->debug('Primary index set.', compact('name'));
	}

	/**
	 * Copy the primary index to a secondary index.
	 *
	 * @param integer $retryCount Recursive retry count for retrying the operation of this method.
	 * @return string ID of the newly created secondary entry.
	 * @throws \Zumba\ElasticsearchRotator\Exception\PrimaryIndexCopyFailure
	 */
	public function copyPrimaryIndexToSecondary($retryCount = 0)
	{
		if (!$this->engine->indices()->exists(['index' => $this->configurationIndexName])) {
			$this->createCurrentIndexConfiguration();
		}
		try {
			$primaryName = $this->getPrimaryIndex();
		} catch (\Elasticsearch\Common\Exceptions\ServerErrorResponseException $e) {
			$this->logger->debug('Unable to get primary index.', json_decode($e->getMessage(), true));
			usleep(static::RETRY_TIME_COPY);
			if ($retryCount > static::MAX_RETRY_COUNT) {
				throw new Exception\PrimaryIndexCopyFailure('Unable to copy primary to secondary index.');
			}
			return $this->copyPrimaryIndexToSecondary($retryCount++);
		}
		$id = $this->engine->index([
			'index' => $this->configurationIndexName,
			'type' => static::TYPE_CONFIGURATION,
			'body' => [
				'name' => $primaryName,
				'timestamp' => time()
			]
		])['_id'];
		$this->logger->debug('Secondary entry created.', compact('id'));
		return $id;
	}

	/**
	 * Retrieve a list of all secondary indexes (rotated from) that are older than provided date (or ES date math)
	 *
	 * Note, if date is not provided, it will find all secondary indexes.
	 *
	 * @param string $olderThan
	 * @return array
	 */
	public function getSecondaryIndexes(\DateTime $olderThan = null)
	{
		if ($olderThan === null) {
			$olderThan = new \DateTime();
		}
		$params = [
			'index' => $this->configurationIndexName,
			'type' => static::TYPE_CONFIGURATION,
			'body' => [
				'query' => [
					'bool' => [
						'must_not' => [
							'term' => [
								'_id' => static::PRIMARY_ID
							]
						],
						'filter' => [
							'range' => [
								'timestamp' => [
									'lt' => $olderThan->format('U')
								]
							]
						]
					]
				],
				'sort' => ['_doc' => 'asc']
			]
		];
		// This section is to support deprecated feature set for ES 1.x.
		// It may be removed in future versions of this library when ES 1.x is sufficiently unsupported.
		if (!$this->doesSupportCombinedQueryFilter()) {
			unset($params['body']['query']['bool']['filter']);
			$params['body']['filter']['range']['timestamp']['lt'] = $olderThan->format('U');
		}
		$results = $this->engine->search($params);
		if ($results['hits']['total'] == 0) {
			return [];
		}
		return array_map(function($entry) {
			return $entry['_source']['name'];
		}, $results['hits']['hits']);
		return $results['hits']['total'] > 0 ? array_column($results['hits']['hits'], '_source') : [];
	}

	/**
	 * Remove any secondary index older that provided date.
	 *
	 * If no date is provided, will remove all secondary indices.
	 *
	 * @param \DateTime $olderThan
	 * @return array Results of the bulk operation.
	 */
	public function deleteSecondaryIndexes(\DateTime $olderThan = null)
	{
		$results = [];
		foreach ($this->getSecondaryIndexes($olderThan) as $indexToDelete) {
			if ($this->engine->indices()->exists(['index' => $indexToDelete])) {
				$results[$indexToDelete] = $this->engine->indices()->delete(['index' => $indexToDelete]);
				$this->logger->debug('Deleted secondary index.', compact('indexToDelete'));
			} else {
				$this->logger->debug('Index not found to delete.', compact('indexToDelete'));
			}
		}
		return $results;
	}

	/**
	 * Create the index needed to store the primary index name.
	 *
	 * @return void
	 */
	private function createCurrentIndexConfiguration()
	{
		$this->engine->indices()->create([
			'index' => $this->configurationIndexName,
			'body' => static::$elasticSearchConfigurationMapping
		]);
		$this->logger->debug('Configuration index created.', [
			'index' => $this->configurationIndexName
		]);
	}

	/**
	 * Determines if the combined filter in query DSL is supported.
	 *
	 * @return boolean
	 */
	private function doesSupportCombinedQueryFilter()
	{
		return version_compare($this->engine->info()['version']['number'], '2.0.0', '>=');
	}
}
